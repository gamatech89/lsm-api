<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\SupportTicketResource;
use App\Models\Project;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Support Ticket Controller
 * 
 * Manages support tickets submitted from WordPress plugin.
 */
class SupportTicketController extends Controller
{
    /**
     * Display a listing of support tickets for a project.
     *
     * @param Request $request
     * @param Project $project
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index(Request $request, Project $project)
    {
        Gate::authorize('view', $project);

        $query = $project->supportTickets()->with('todo:id,title,status');

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by type
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // Filter by unread
        if ($request->boolean('unread_only', false)) {
            $query->unread();
        }

        // Filter by open only
        if ($request->boolean('open_only', false)) {
            $query->open();
        }

        $tickets = $query->orderByDesc('created_at')->get();

        return SupportTicketResource::collection($tickets);
    }

    /**
     * Display a listing of all support tickets across all projects.
     *
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function indexAll(Request $request)
    {
        $user = $request->user();

        $query = SupportTicket::with(['project', 'todo:id,title,status']);

        // Scope to projects the user is allowed to see. Admins see everything;
        // managers and developers only see tickets on their assigned projects.
        if (!$user->isAdmin()) {
            $query->whereHas('project', function ($q) use ($user) {
                if ($user->role === 'manager') {
                    $q->where('manager_id', $user->id)
                        ->orWhereHas('managers', fn($m) => $m->where('users.id', $user->id));
                } elseif ($user->role === 'developer') {
                    $q->where('developer_id', $user->id)
                        ->orWhereHas('developers', fn($d) => $d->where('users.id', $user->id));
                } else {
                    // Unknown role: see nothing.
                    $q->whereRaw('1 = 0');
                }
            });
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                  ->orWhere('ticket_number', 'like', "%{$search}%")
                  ->orWhere('client_email', 'like', "%{$search}%")
                  ->orWhereHas('project', function($pq) use ($search) {
                      $pq->where('name', 'like', "%{$search}%");
                  });
            });
        }

        $tickets = $query->orderByDesc('created_at')->get();

        return SupportTicketResource::collection($tickets);
    }

    /**
     * Display the specified support ticket.
     *
     * @param SupportTicket $supportTicket
     * @return SupportTicketResource
     */
    public function show(SupportTicket $supportTicket): SupportTicketResource
    {
        Gate::authorize('view', $supportTicket->project);

        $supportTicket->load(['todo:id,title,status', 'messages.attachments', 'attachments']);

        // Auto-mark as read when viewing
        $supportTicket->markAsRead();

        return new SupportTicketResource($supportTicket);
    }

    /**
     * Update the specified support ticket.
     *
     * @param Request $request
     * @param SupportTicket $supportTicket
     * @return SupportTicketResource
     */
    public function update(Request $request, SupportTicket $supportTicket): SupportTicketResource
    {
        Gate::authorize('update', $supportTicket->project);

        $validated = $request->validate([
            'status' => 'sometimes|in:open,in_progress,resolved,closed',
            'priority' => 'sometimes|in:low,medium,high,critical',
            'resolution_notes' => 'nullable|string',
        ]);

        // If marking as resolved, set resolved_at and email the client (only on transition)
        $becameResolved = isset($validated['status'])
            && $validated['status'] === 'resolved'
            && $supportTicket->status !== 'resolved';

        if ($becameResolved && !$supportTicket->resolved_at) {
            $validated['resolved_at'] = now();
        }

        $supportTicket->update($validated);

        if ($becameResolved && $supportTicket->client_email) {
            \Illuminate\Support\Facades\Notification::route('mail', $supportTicket->client_email)
                ->notify(new \App\Notifications\SupportTicketResolvedNotification($supportTicket));
        }

        $supportTicket->load('todo:id,title,status');

        return new SupportTicketResource($supportTicket);
    }

    /**
     * Remove the specified support ticket.
     *
     * @param SupportTicket $supportTicket
     * @return JsonResponse
     */
    public function destroy(SupportTicket $supportTicket): JsonResponse
    {
        Gate::authorize('update', $supportTicket->project);

        $supportTicket->delete();

        return $this->successResponse(null, 'Support ticket deleted successfully');
    }

    /**
     * Mark ticket as read.
     *
     * @param SupportTicket $supportTicket
     * @return SupportTicketResource
     */
    public function markAsRead(SupportTicket $supportTicket): SupportTicketResource
    {
        Gate::authorize('view', $supportTicket->project);

        $supportTicket->markAsRead();

        return new SupportTicketResource($supportTicket);
    }

    /**
     * Create a todo from this ticket.
     *
     * @param SupportTicket $supportTicket
     * @return JsonResponse
     */
    public function createTodo(SupportTicket $supportTicket): JsonResponse
    {
        Gate::authorize('update', $supportTicket->project);

        if ($supportTicket->todo_id) {
            return $this->errorResponse('Ticket already has a linked todo', 400);
        }

        $todo = $supportTicket->createTodo();

        return $this->createdResponse([
            'ticket' => new SupportTicketResource($supportTicket->fresh('todo')),
            'todo' => [
                'id' => $todo->id,
                'title' => $todo->title,
            ],
        ], 'Todo created successfully from ticket');
    }

    /**
     * Get unread count for a project.
     *
     * @param Project $project
     * @return JsonResponse
     */
    public function unreadCount(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $count = $project->supportTickets()->unread()->count();

        return $this->successResponse(['count' => $count]);
    }

    /**
     * Add a staff reply to the ticket thread and email the client.
     */
    public function storeMessage(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        Gate::authorize('update', $supportTicket->project);

        $validated = $request->validate(array_merge(
            ['message' => 'required|string'],
            \App\Services\SupportTicketAttachmentService::rules()
        ));

        $message = $supportTicket->addStaffMessage($request->user(), $validated['message']);
        app(\App\Services\SupportTicketAttachmentService::class)
            ->store($supportTicket, $message, $request->file('attachments', []));

        if ($supportTicket->client_email) {
            \Illuminate\Support\Facades\Notification::route('mail', $supportTicket->client_email)
                ->notify(new \App\Notifications\SupportTicketStaffReplyNotification($supportTicket, $message));
        }

        return $this->createdResponse(
            new \App\Http\Resources\SupportTicketMessageResource($message->load('attachments')),
            'Reply added'
        );
    }

    /**
     * Download a ticket attachment (staff).
     */
    public function downloadAttachment(\App\Models\SupportTicketAttachment $attachment)
    {
        Gate::authorize('view', $attachment->ticket->project);

        return app(\App\Services\SupportTicketAttachmentService::class)->download($attachment);
    }

    // =========================================================================
    // PUBLIC WEBHOOK (for WordPress plugin)
    // =========================================================================

    /**
     * Receive support ticket from WordPress plugin.
     * 
     * This is a PUBLIC endpoint - authentication via API key.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function receiveFromPlugin(Request $request): JsonResponse
    {
        try {
            // Validate incoming data
            $validated = $request->validate([
                'api_key' => 'required|string',
                'site_url' => 'required|url',
                'type' => 'required|in:bug,content,design,feature,question,urgent',
                'subject' => 'required|string|max:255',
                'message' => 'required|string',
                'client_email' => 'required|email',
                'client_name' => 'nullable|string|max:255',
                'problem_page' => 'nullable|string|max:500',
            ]);

            // Find project by site URL and API key. The secret is encrypted at
            // rest, so match on its deterministic hash rather than the value.
            $siteUrl = rtrim($validated['site_url'], '/');
            $apiKeyHash = hash('sha256', $validated['api_key']);
            $project = Project::where('health_check_secret_hash', $apiKeyHash)
                ->where(function ($query) use ($siteUrl) {
                    // Match URL with or without trailing slash, http or https
                    $query->where('url', $siteUrl)
                        ->orWhere('url', $siteUrl . '/')
                        ->orWhere('url', str_replace('https://', 'http://', $siteUrl))
                        ->orWhere('url', str_replace('http://', 'https://', $siteUrl));
                })
                ->first();

            if (!$project) {
                // Try matching by domain only
                $parsedUrl = parse_url($siteUrl);
                $domain = $parsedUrl['host'] ?? '';
                
                $project = Project::where('health_check_secret_hash', $apiKeyHash)
                    ->where(function ($query) use ($domain) {
                        $query->where('domain', $domain)
                            ->orWhere('domain', 'www.' . $domain)
                            ->orWhere('domain', str_replace('www.', '', $domain));
                    })
                    ->first();
            }

            if (!$project) {
                Log::warning('Support ticket received but project not found', [
                    'site_url' => $validated['site_url'],
                ]);
                return $this->errorResponse('Project not found or invalid API key', 404);
            }

            // Determine priority based on type
            $priority = 'medium';
            if ($validated['type'] === 'urgent') {
                $priority = 'critical';
            } elseif ($validated['type'] === 'bug') {
                $priority = 'high';
            }

            // Create the ticket
            $ticket = SupportTicket::create([
                'project_id' => $project->id,
                'type' => $validated['type'],
                'subject' => $validated['subject'],
                'message' => $validated['message'],
                'client_email' => $validated['client_email'],
                'client_name' => $validated['client_name'] ?? '',
                'problem_page' => $validated['problem_page'] ?? null,
                'site_url' => $validated['site_url'],
                'status' => 'open',
                'priority' => $priority,
            ]);

            foreach ($project->notifiableTeamMembers() as $member) {
                $member->notify(new \App\Notifications\SupportTicketReceivedNotification($ticket));
            }

            Log::info('Support ticket created from WordPress', [
                'ticket_number' => $ticket->ticket_number,
                'project_id' => $project->id,
                'type' => $ticket->type,
            ]);

            return $this->createdResponse([
                'ticket_number' => $ticket->ticket_number,
                'message' => 'Support ticket created successfully',
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse('Validation failed: ' . json_encode($e->errors()), 422);
        } catch (\Exception $e) {
            Log::error('Failed to create support ticket from plugin', [
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to create support ticket', 500);
        }
    }
}
