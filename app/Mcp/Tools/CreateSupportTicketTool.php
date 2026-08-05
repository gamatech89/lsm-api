<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use App\Mcp\Concerns\HasRequiredScope;

class CreateSupportTicketTool extends Tool
{
    use HasRequiredScope;

    protected function requiredScope(): string
    {
        return 'mcp:write';
    }

    protected string $name = 'create-support-ticket';

    protected string $description = <<<'MARKDOWN'
        Create a new support ticket for a project. Requires project ID and subject.
        Optionally set priority, description, and assignee.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        if ($denied = $this->assertScope()) {
            return $denied;
        }

        $user = Auth::user();
        $input = $request->all();

        if (empty($input['project_id'])) {
            return Response::error('Project ID is required.');
        }

        if (empty($input['subject'])) {
            return Response::error('Subject is required.');
        }

        $project = Project::find($input['project_id']);

        if (!$project) {
            return Response::error("Project with ID {$input['project_id']} not found.");
        }

        // Find assignee if specified
        $assigneeId = null;
        if (!empty($input['assignee_id'])) {
            $assignee = User::find($input['assignee_id']);
            if ($assignee) {
                $assigneeId = $assignee->id;
            }
        } elseif (!empty($input['assignee_name'])) {
            $assignee = User::where('name', 'like', '%' . $input['assignee_name'] . '%')->first();
            if ($assignee) {
                $assigneeId = $assignee->id;
            }
        }

        $ticket = SupportTicket::create([
            'project_id' => $project->id,
            'subject' => $input['subject'],
            'description' => $input['description'] ?? null,
            'priority' => $input['priority'] ?? 'medium',
            'status' => 'open',
            'created_by' => $user->id,
            'assigned_to' => $assigneeId,
        ]);

        $assigneeName = $assigneeId ? User::find($assigneeId)->name : 'Unassigned';

        return Response::text(
            "✅ **Support Ticket Created**\n\n" .
            "**Ticket #**: {$ticket->id}\n" .
            "**Subject**: {$ticket->subject}\n" .
            "**Project**: {$project->name}\n" .
            "**Priority**: {$ticket->priority}\n" .
            "**Assigned to**: {$assigneeName}"
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()
                ->description('The project ID to create ticket for')
                ->required(),
            'subject' => $schema->string()
                ->description('The ticket subject/title')
                ->required(),
            'description' => $schema->string()
                ->description('Detailed description of the issue'),
            'priority' => $schema->string()
                ->description('Priority: low, medium, high, urgent (default: medium)'),
            'assignee_id' => $schema->integer()
                ->description('User ID to assign to'),
            'assignee_name' => $schema->string()
                ->description('User name to assign to'),
        ];
    }
}
