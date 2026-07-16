<?php
// app/Services/SupportTicketAttachmentService.php

namespace App\Services;

use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stores and serves support ticket attachments.
 * Files live on the local disk (outside the public root) under support-attachments/{ticket_id}/.
 */
class SupportTicketAttachmentService
{
    public const MAX_FILES = 5;
    public const MAX_KB = 5120; // 5 MB
    public const ALLOWED_EXTENSIONS = 'png,jpg,jpeg,webp,gif,pdf';

    /**
     * Validation rules for an `attachments` request array.
     */
    public static function rules(): array
    {
        return [
            'attachments' => 'sometimes|array|max:' . self::MAX_FILES,
            'attachments.*' => 'file|max:' . self::MAX_KB . '|mimes:' . self::ALLOWED_EXTENSIONS,
        ];
    }

    /**
     * Store uploaded files against a ticket (and optionally one of its messages).
     *
     * @param UploadedFile[] $files
     * @return SupportTicketAttachment[]
     */
    public function store(SupportTicket $ticket, ?SupportTicketMessage $message, array $files): array
    {
        return collect($files)
            ->filter(fn ($file) => $file instanceof UploadedFile)
            ->map(function (UploadedFile $file) use ($ticket, $message) {
                $path = $file->store("support-attachments/{$ticket->id}", 'local');

                return $ticket->attachments()->create([
                    'support_ticket_message_id' => $message?->id,
                    'filename' => $file->getClientOriginalName(),
                    'path' => $path,
                    'mime' => $file->getMimeType() ?: 'application/octet-stream',
                    'size' => $file->getSize() ?: 0,
                ]);
            })
            ->values()
            ->all();
    }

    /**
     * Stream an attachment as a download with its original name.
     */
    public function download(SupportTicketAttachment $attachment): StreamedResponse
    {
        return Storage::disk('local')->download(
            $attachment->path,
            $attachment->filename,
            ['Content-Type' => $attachment->mime]
        );
    }
}
