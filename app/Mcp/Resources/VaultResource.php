<?php

namespace App\Mcp\Resources;

use App\Models\Credential;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;
use App\Mcp\Concerns\HasRequiredScope;

class VaultResource extends Resource
{
    use HasRequiredScope;

    protected string $requiredScope = 'mcp:read';

    protected string $name = 'vault';

    protected string $uri = 'lsm://vault';

    protected string $description = <<<'MARKDOWN'
        List of all credentials you have access to across projects.
        Shows labels and usernames only. Passwords are not accessible via MCP.
        Credentials are grouped by project.
    MARKDOWN;

    protected string $mimeType = 'application/json';

    public function handle(Request $request): Response
    {
        if ($denied = $this->assertScope()) {
            return $denied;
        }

        $user = Auth::user();

        // Build query based on role
        $query = Credential::with(['project:id,name'])
            ->select(['id', 'project_id', 'label', 'username', 'url', 'type', 'updated_at']);

        if ($user->role === 'developer') {
            $query->whereHas('project.developers', fn($q) => $q->where('user_id', $user->id));
        } elseif ($user->role === 'manager') {
            $query->whereHas('project', fn($q) => $q->where('manager_id', $user->id)->orWhereHas('managers', fn($sub) => $sub->where('users.id', $user->id)));
        }
        // Admin sees all

        $credentials = $query->orderBy('project_id')->orderBy('label')->get();

        // Group by project
        $byProject = $credentials->groupBy('project.name')->map(function ($projectCredentials, $projectName) {
            return [
                'project' => $projectName,
                'credentials' => $projectCredentials->map(fn($c) => [
                    'id' => $c->id,
                    'label' => $c->label,
                    'username' => $c->username,
                    'url' => $c->url,
                    'type' => $c->type,
                    'updated_at' => $c->updated_at->toIso8601String(),
                ])->values(),
            ];
        })->values();

        return Response::json([
            'total' => $credentials->count(),
            'projects' => $byProject->count(),
            'by_project' => $byProject,
            'note' => 'Passwords are not accessible via MCP.',
        ]);
    }
}
