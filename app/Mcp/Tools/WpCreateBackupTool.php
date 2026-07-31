<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Jobs\CreateBackupJob;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class WpCreateBackupTool extends Tool
{
    protected string $name = 'wp-create-backup';

    protected string $description = <<<'MARKDOWN'
        Create a backup for a WordPress site. This will trigger a full backup 
        including database and files, then store it according to the configured 
        backup storage driver. The backup runs asynchronously and you'll be 
        notified when it completes.
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

        // Check if project has RMB connection
        if (!$project->health_check_secret) {
            return Response::error('This project is not connected to WordPress. Please connect it first.');
        }

        try {
            // Create backup record
            $backup = $project->backups()->create([
                'type' => $input['type'] ?? 'manual',
                'status' => 'pending',
                'includes_database' => $input['includes_database'] ?? true,
                'includes_files' => $input['includes_files'] ?? true,
                'includes_uploads' => $input['includes_uploads'] ?? true,
                'started_at' => now(),
            ]);

            // Dispatch the backup job
            CreateBackupJob::dispatch($backup);

            $contents = [];
            if ($backup->includes_database) $contents[] = 'database';
            if ($backup->includes_files) $contents[] = 'files';
            if ($backup->includes_uploads) $contents[] = 'uploads';

            return Response::text(
                "🔄 **Backup Started**\n\n" .
                "Project: {$project->name}\n" .
                "Backup ID: {$backup->id}\n" .
                "Type: {$backup->type}\n" .
                "Contents: " . implode(', ', $contents) . "\n\n" .
                "The backup is being created in the background. You'll be notified when it completes.\n" .
                "You can check the status using the `wp-list-backups` tool."
            );

        } catch (\Exception $e) {
            return Response::error('Failed to start backup: ' . $e->getMessage());
        }
    }

    private function canAccessProject($user, $project): bool
    {
        if ($user->role === 'admin') return true;
        if ($user->role === 'manager' && $project->isManagedBy($user)) return true;
        if ($user->role === 'developer' && $project->developers->contains('id', $user->id)) return true;
        return false;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()
                ->description('The ID of the WordPress project to backup')
                ->required(),
            'includes_database' => $schema->boolean()
                ->description('Include database in backup (default: true)')
                ->default(true),
            'includes_files' => $schema->boolean()
                ->description('Include WordPress files in backup (default: true)')
                ->default(true),
            'includes_uploads' => $schema->boolean()
                ->description('Include uploads folder in backup (default: true)')
                ->default(true),
        ];
    }
}
