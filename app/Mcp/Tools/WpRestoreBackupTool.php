<?php

namespace App\Mcp\Tools;

use App\Models\Backup;
use App\Models\Project;
use App\Jobs\RestoreBackupJob;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use App\Mcp\Concerns\HasRequiredScope;

class WpRestoreBackupTool extends Tool
{
    use HasRequiredScope;

    protected function requiredScope(): string
    {
        return 'mcp:wp-destructive';
    }

    protected string $name = 'wp-restore-backup';

    protected string $description = <<<'MARKDOWN'
        Restore a WordPress site from a backup. This is a potentially destructive 
        operation that will replace the current site content with the backup. 
        The site will be put in maintenance mode during restoration.
        
        ⚠️ Use with caution - this will overwrite current site data.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        if ($denied = $this->assertScope()) {
            return $denied;
        }

        $user = Auth::user();
        $input = $request->all();

        if (empty($input['backup_id'])) {
            return Response::error('Backup ID is required.');
        }

        $backup = Backup::with('project.developers')->find($input['backup_id']);

        if (!$backup) {
            return Response::error("Backup with ID {$input['backup_id']} not found.");
        }

        $project = $backup->project;

        // Check access - only admins and managers can restore
        if (!$this->canRestoreBackup($user, $project)) {
            return Response::error('Only admins and project managers can restore backups.');
        }

        if ($backup->status !== 'completed') {
            return Response::error("Cannot restore backup - status is '{$backup->status}'. Only completed backups can be restored.");
        }

        if (!$backup->file_path) {
            return Response::error('Cannot restore backup - backup file not found.');
        }

        // Check for confirmation
        if (empty($input['confirm']) || $input['confirm'] !== true) {
            return Response::text(
                "⚠️ **Restore Confirmation Required**\n\n" .
                "You are about to restore:\n" .
                "- **Project:** {$project->name}\n" .
                "- **Backup ID:** {$backup->id}\n" .
                "- **Created:** {$backup->created_at->format('M d, Y H:i')}\n" .
                "- **Size:** " . $this->formatBytes($backup->file_size ?? 0) . "\n\n" .
                "**This will:**\n" .
                ($backup->includes_database ? "- Replace the database\n" : "") .
                ($backup->includes_files ? "- Replace WordPress files\n" : "") .
                ($backup->includes_uploads ? "- Replace uploaded media\n" : "") .
                "\n⚠️ **Current site content will be overwritten!**\n\n" .
                "To proceed, call this tool again with `confirm: true`"
            );
        }

        try {
            // Dispatch restore job
            RestoreBackupJob::dispatch($backup);

            return Response::text(
                "🔄 **Restore Started**\n\n" .
                "Project: {$project->name}\n" .
                "Backup ID: {$backup->id}\n\n" .
                "The site is being restored from the backup. It will be in maintenance mode during this process.\n" .
                "You'll be notified when the restoration is complete."
            );

        } catch (\Exception $e) {
            return Response::error('Failed to start restore: ' . $e->getMessage());
        }
    }

    private function canRestoreBackup($user, $project): bool
    {
        if ($user->role === 'admin') return true;
        if ($user->role === 'manager' && $project->isManagedBy($user)) return true;
        return false;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'backup_id' => $schema->integer()
                ->description('The ID of the backup to restore')
                ->required(),
            'confirm' => $schema->boolean()
                ->description('Set to true to confirm the restore operation')
                ->default(false),
        ];
    }
}
