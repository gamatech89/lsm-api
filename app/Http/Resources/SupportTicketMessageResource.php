<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupportTicketMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'author_type' => $this->author_type,
            'author_name' => $this->author_name,
            'user_id' => $this->user_id,
            'message' => $this->message,
            'created_at' => $this->created_at?->toISOString(),
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($a) => [
                'id' => $a->id,
                'filename' => $a->filename,
                'mime' => $a->mime,
                'size' => $a->size,
            ])),
        ];
    }
}
