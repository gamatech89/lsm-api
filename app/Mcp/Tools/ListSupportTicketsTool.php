<?php

namespace App\Mcp\Tools;

use App\Models\SupportTicket;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use App\Mcp\Concerns\HasRequiredScope;

class ListSupportTicketsTool extends Tool
{
    use HasRequiredScope;

    protected function requiredScope(): string
    {
        return 'mcp:read';
    }

    protected string $name = 'list-support-tickets';

    protected string $description = <<<'MARKDOWN'
        List support tickets. Can filter by status (open, in_progress, resolved, closed) or project.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        if ($denied = $this->assertScope()) {
            return $denied;
        }

        $user = Auth::user();
        $input = $request->all();

        $query = SupportTicket::with(['project', 'creator', 'assignee']);

        // Filter by access level
        if ($user->role !== 'admin') {
            $query->where(function ($q) use ($user) {
                $q->where('created_by', $user->id)
                    ->orWhere('assigned_to', $user->id)
                    ->orWhereHas('project', function ($pq) use ($user) {
                        $pq->where('manager_id', $user->id)
                            ->orWhereHas('managers', fn($sub) => $sub->where('users.id', $user->id));
                    });
            });
        }

        if (!empty($input['status'])) {
            $query->where('status', $input['status']);
        }

        if (!empty($input['project_id'])) {
            $query->where('project_id', $input['project_id']);
        }

        if (!empty($input['priority'])) {
            $query->where('priority', $input['priority']);
        }

        $tickets = $query->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        if ($tickets->isEmpty()) {
            return Response::text("No support tickets found.");
        }

        $lines = ["**🎫 Support Tickets ({$tickets->count()})**\n"];

        foreach ($tickets as $ticket) {
            $statusEmoji = match ($ticket->status) {
                'open' => '🔵',
                'in_progress' => '🟡',
                'resolved' => '✅',
                'closed' => '⚫',
                default => '📋',
            };

            $priorityEmoji = match ($ticket->priority) {
                'urgent' => '🔴',
                'high' => '🟠',
                'medium' => '🟡',
                'low' => '🟢',
                default => '⚪',
            };

            $project = $ticket->project->name ?? 'General';

            $lines[] = sprintf(
                "%s %s **#%d**: %s\n   Project: %s | Assignee: %s",
                $statusEmoji,
                $priorityEmoji,
                $ticket->id,
                $ticket->subject,
                $project,
                $ticket->assignee->name ?? 'Unassigned'
            );
        }

        return Response::text(implode("\n", $lines));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->description('Filter by status: open, in_progress, resolved, closed'),
            'project_id' => $schema->integer()
                ->description('Filter by project ID'),
            'priority' => $schema->string()
                ->description('Filter by priority: low, medium, high, urgent'),
        ];
    }
}
