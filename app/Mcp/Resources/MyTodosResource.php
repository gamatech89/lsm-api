<?php

namespace App\Mcp\Resources;

use App\Models\Todo;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;
use App\Mcp\Concerns\HasRequiredScope;

class MyTodosResource extends Resource
{
    use HasRequiredScope;

    protected function requiredScope(): string
    {
        return 'mcp:read';
    }

    protected string $name = 'my-todos';

    protected string $uri = 'lsm://todos/mine';

    protected string $description = <<<'MARKDOWN'
        Your assigned todos across all projects, ordered by priority and status.
        Shows pending and in-progress tasks that need your attention.
    MARKDOWN;

    protected string $mimeType = 'application/json';

    public function handle(Request $request): Response
    {
        if ($denied = $this->assertScope()) {
            return $denied;
        }

        $user = Auth::user();

        $todos = Todo::where('assigned_to', $user->id)
            ->where('status', '!=', 'completed')
            ->with(['project:id,name'])
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->orderBy('created_at', 'desc')
            ->get();

        $data = $todos->map(fn($todo) => [
            'id' => $todo->id,
            'title' => $todo->title,
            'description' => $todo->description,
            'project' => [
                'id' => $todo->project->id,
                'name' => $todo->project->name,
            ],
            'priority' => $todo->priority,
            'status' => $todo->status,
            'due_date' => $todo->due_date?->toIso8601String(),
            'created_at' => $todo->created_at->toIso8601String(),
        ]);

        // Group by priority for easier reading
        $byPriority = [
            'urgent' => $data->where('priority', 'urgent')->values(),
            'high' => $data->where('priority', 'high')->values(),
            'medium' => $data->where('priority', 'medium')->values(),
            'low' => $data->where('priority', 'low')->values(),
        ];

        return Response::json([
            'total' => $data->count(),
            'by_priority' => $byPriority,
            'all' => $data,
        ]);
    }
}
