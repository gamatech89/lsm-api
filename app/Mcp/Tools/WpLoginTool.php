<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Services\LsmService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class WpLoginTool extends Tool
{
    protected string $name = 'wp-login';

    protected string $description = <<<'MARKDOWN'
        Generate a one-time SSO login URL for WordPress admin. The URL is valid 
        for a short time and will log you in directly without entering credentials.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $user = Auth::user();
        $input = $request->all();

        if (empty($input['project_id'])) {
            return Response::error('Project ID is required.');
        }

        $project = Project::with('developers')->find($input['project_id']);

        if (!$project) {
            return Response::error("Project with ID {$input['project_id']} not found.");
        }

        // Check access
        if (!$this->canAccessProject($user, $project)) {
            return Response::error('You do not have access to this project.');
        }

        try {
            $lsmService = LsmService::for($project);
            $result = $lsmService->generateLoginToken();

            if (!empty($result['login_url'])) {
                return Response::text(
                    "✅ **WordPress Login URL Generated**\n\n" .
                    "Project: {$project->name}\n" .
                    "URL: {$result['login_url']}\n\n" .
                    "⚠️ This link expires in 60 seconds. Click it to log in directly to WordPress admin."
                );
            }

            return Response::error('Failed to generate login URL: ' . ($result['message'] ?? 'No login URL returned'));
        } catch (\Exception $e) {
            return Response::error('Failed to generate login URL: ' . $e->getMessage());
        }
    }

    private function canAccessProject($user, $project): bool
    {
        if ($user->role === 'admin') return true;
        if ($user->role === 'manager' && $project->managers->contains('id', $user->id)) return true;
        if ($user->role === 'developer' && $project->developers->contains('id', $user->id)) return true;
        return false;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()
                ->description('The ID of the WordPress project to login to')
                ->required(),
        ];
    }
}
