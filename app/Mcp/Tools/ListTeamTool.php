<?php

namespace App\Mcp\Tools;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListTeamTool extends Tool
{
    protected string $name = 'list-team';

    protected string $description = <<<'MARKDOWN'
        List all team members (users) in the system. Shows name, email, role, status.
        Can filter by role: admin, manager, developer, viewer.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $user = Auth::user();
        $input = $request->all();

        // Only admins and managers can list team
        if (!in_array($user->role, ['admin', 'manager'])) {
            return Response::error('Only admins and managers can view the team list.');
        }

        $query = User::query();

        if (!empty($input['role'])) {
            $query->where('role', $input['role']);
        }

        if (!empty($input['search'])) {
            $search = $input['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->orderBy('name')->get();

        if ($users->isEmpty()) {
            return Response::text("No team members found.");
        }

        $lines = ["**👥 Team Members ({$users->count()})**\n"];

        foreach ($users as $member) {
            $roleEmoji = match ($member->role) {
                'admin' => '👑',
                'manager' => '📋',
                'developer' => '💻',
                'viewer' => '👁️',
                default => '👤',
            };

            $status = $member->is_active ? '🟢' : '🔴';

            $lines[] = sprintf(
                "%s **%s** (ID: %d)\n   %s %s | %s",
                $status,
                $member->name,
                $member->id,
                $roleEmoji,
                ucfirst($member->role),
                $member->email
            );
        }

        return Response::text(implode("\n", $lines));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'role' => $schema->string()
                ->description('Filter by role: admin, manager, developer, viewer'),
            'search' => $schema->string()
                ->description('Search by name or email'),
        ];
    }
}
