<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Models\PhpError;
use App\Services\LsmService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class WpClearPhpErrorsTool extends Tool
{
    protected string $name = 'wp-clear-php-errors';

    protected string $description = <<<'MARKDOWN'
        Clear PHP error log for a WordPress site. Use this to reset the error 
        log after fixing issues or to start fresh. Can optionally only clear 
        resolved errors or errors of a specific type.
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

        // Check access - only admins and managers can clear logs
        if (!$this->canClearErrors($user, $project)) {
            return Response::error('Only admins and project managers can clear PHP error logs.');
        }

        try {
            $query = PhpError::where('project_id', $project->id);

            $clearType = $input['clear_type'] ?? 'all';
            $deletedCount = 0;

            switch ($clearType) {
                case 'resolved':
                    $deletedCount = $query->where('is_resolved', true)->delete();
                    $message = "Cleared {$deletedCount} resolved errors.";
                    break;
                    
                case 'type':
                    if (empty($input['error_type'])) {
                        return Response::error("Error type is required when using 'type' clear mode.");
                    }
                    $deletedCount = $query->where('type', $input['error_type'])->delete();
                    $message = "Cleared {$deletedCount} {$input['error_type']} errors.";
                    break;
                    
                case 'all':
                default:
                    // Confirm required for clearing all
                    if (empty($input['confirm']) || $input['confirm'] !== true) {
                        $count = $query->count();
                        return Response::text(
                            "⚠️ **Confirmation Required**\n\n" .
                            "You are about to clear ALL PHP errors for:\n" .
                            "- **Project:** {$project->name}\n" .
                            "- **Error Count:** {$count}\n\n" .
                            "This action cannot be undone.\n\n" .
                            "To proceed, call this tool again with `confirm: true`"
                        );
                    }
                    $deletedCount = $query->delete();
                    $message = "Cleared all {$deletedCount} errors.";
                    break;
            }

            // Also clear on WordPress if connected
            if ($project->health_check_secret) {
                try {
                    $lsmService = LsmService::for($project);
                    $lsmService->clearPhpErrors();
                } catch (\Exception $e) {
                    // Log but don't fail
                }
            }

            return Response::text(
                "🧹 **PHP Errors Cleared**\n\n" .
                "Project: {$project->name}\n" .
                $message . "\n\n" .
                "The error log has been reset."
            );

        } catch (\Exception $e) {
            return Response::error('Failed to clear PHP errors: ' . $e->getMessage());
        }
    }

    private function canClearErrors($user, $project): bool
    {
        if ($user->role === 'admin') return true;
        if ($user->role === 'manager' && $project->manager_id === $user->id) return true;
        return false;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()
                ->description('The ID of the WordPress project')
                ->required(),
            'clear_type' => $schema->string()
                ->description("What to clear: 'all' (default), 'resolved', or 'type'")
                ->enum(['all', 'resolved', 'type'])
                ->default('all'),
            'error_type' => $schema->string()
                ->description("When clear_type is 'type', specify which type to clear")
                ->enum(['fatal', 'warning', 'notice', 'deprecated']),
            'confirm' => $schema->boolean()
                ->description("Confirm clearing all errors (required when clear_type is 'all')")
                ->default(false),
        ];
    }
}
