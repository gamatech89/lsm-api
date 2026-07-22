<?php
// app/Http/Controllers/Api/V1/PluginTicketController.php

namespace App\Http\Controllers\Api\V1;

use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Notifications\SupportTicketClientReplyNotification;
use App\Notifications\SupportTicketReceivedNotification;
use App\Services\SupportTicketAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ticket endpoints consumed by the Landeseiten Maintenance WP plugin.
 *
 * All routes sit behind the AuthenticateLsmPlugin middleware, which resolves
 * the calling site's Project into the 'lsm_project' request attribute. Every
 * ticket/attachment id is verified to belong to that project (404 otherwise).
 */
class PluginTicketController extends Controller
{
    public function __construct(protected SupportTicketAttachmentService $attachments) {}

    public function index(Request $request): JsonResponse
    {
        $project = $request->attributes->get('lsm_project');

        $tickets = $project->supportTickets()
            ->withCount('messages')
            ->withMax('messages as last_message_at', 'created_at')
            ->withMax('staffMessages as last_staff_reply_at', 'created_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (SupportTicket $ticket) => [
                'id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'type' => $ticket->type,
                'type_label' => $ticket->type_label,
                'subject' => $ticket->subject,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'message_count' => $ticket->messages_count,
                'last_message_at' => $ticket->last_message_at,
                'last_staff_reply_at' => $ticket->last_staff_reply_at,
                'created_at' => $ticket->created_at?->toISOString(),
            ]);

        return $this->successResponse($tickets);
    }

    public function show(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        $project = $request->attributes->get('lsm_project');
        abort_unless($supportTicket->project_id === $project->id, 404);

        $supportTicket->load([
            'messages.attachments',
            'attachments' => fn ($q) => $q->whereNull('support_ticket_message_id'),
        ]);

        return $this->successResponse([
            'id' => $supportTicket->id,
            'ticket_number' => $supportTicket->ticket_number,
            'type' => $supportTicket->type,
            'type_label' => $supportTicket->type_label,
            'subject' => $supportTicket->subject,
            'message' => $supportTicket->message,
            'status' => $supportTicket->status,
            'priority' => $supportTicket->priority,
            'problem_page' => $supportTicket->problem_page,
            'resolution_notes' => $supportTicket->resolution_notes,
            'created_at' => $supportTicket->created_at?->toISOString(),
            'attachments' => $supportTicket->attachments->map(fn ($a) => $this->attachmentSummary($a)),
            'messages' => $supportTicket->messages->map(fn ($m) => [
                'id' => $m->id,
                'author_type' => $m->author_type,
                'author_name' => $m->author_name,
                'message' => $m->message,
                'created_at' => $m->created_at?->toISOString(),
                'attachments' => $m->attachments->map(fn ($a) => $this->attachmentSummary($a)),
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $project = $request->attributes->get('lsm_project');

        $validated = $request->validate(array_merge([
            'type' => 'required|in:bug,content,design,feature,question,urgent',
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
            'client_email' => 'required|email',
            'client_name' => 'nullable|string|max:255',
            'problem_page' => 'nullable|string|max:500',
            'reported_priority' => 'nullable|in:normal,high,urgent',
        ], SupportTicketAttachmentService::rules()));

        // The client can report urgency directly; it seeds the staff-owned
        // severity (staff can re-triage later). Older plugins omit it — fall
        // back to deriving severity from the ticket type.
        $priority = match ($validated['reported_priority'] ?? null) {
            'urgent' => 'critical',
            'high' => 'high',
            'normal' => 'medium',
            default => match ($validated['type']) {
                'urgent' => 'critical',
                'bug' => 'high',
                default => 'medium',
            },
        };

        $ticket = SupportTicket::create([
            'project_id' => $project->id,
            'type' => $validated['type'],
            'subject' => $validated['subject'],
            'message' => $validated['message'],
            'client_email' => $validated['client_email'],
            'client_name' => $validated['client_name'] ?? '',
            'problem_page' => $validated['problem_page'] ?? null,
            'site_url' => $project->url,
            'status' => 'open',
            'priority' => $priority,
        ]);

        $this->attachments->store($ticket, null, $request->file('attachments', []));

        foreach ($project->notifiableTeamMembers() as $member) {
            $member->notify(new SupportTicketReceivedNotification($ticket));
        }

        return $this->createdResponse([
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
        ], 'Support ticket created successfully');
    }

    public function storeMessage(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        $project = $request->attributes->get('lsm_project');
        abort_unless($supportTicket->project_id === $project->id, 404);

        $validated = $request->validate(array_merge([
            'message' => 'required|string',
            'author_name' => 'nullable|string|max:255',
        ], SupportTicketAttachmentService::rules()));

        $message = $supportTicket->addClientMessage($validated['message'], $validated['author_name'] ?? null);
        $this->attachments->store($supportTicket, $message, $request->file('attachments', []));

        foreach ($project->notifiableTeamMembers() as $member) {
            $member->notify(new SupportTicketClientReplyNotification($supportTicket, $message));
        }

        return $this->createdResponse([
            'id' => $message->id,
            'author_type' => $message->author_type,
            'author_name' => $message->author_name,
            'message' => $message->message,
            'created_at' => $message->created_at?->toISOString(),
        ], 'Reply added');
    }

    public function downloadAttachment(Request $request, SupportTicketAttachment $attachment)
    {
        $project = $request->attributes->get('lsm_project');
        abort_unless($attachment->ticket && $attachment->ticket->project_id === $project->id, 404);

        return $this->attachments->download($attachment);
    }

    private function attachmentSummary(SupportTicketAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'filename' => $attachment->filename,
            'mime' => $attachment->mime,
            'size' => $attachment->size,
        ];
    }
}
