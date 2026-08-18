<?php

namespace App\Mcp\Tools;

use App\Models\Backup;
use App\Models\Project;
use App\Services\BackupStorageService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use App\Mcp\Concerns\HasRequiredScope;
use App\Mcp\Concerns\RequiresBackupFeature;

class WpDownloadBackupTool extends Tool
{
    use HasRequiredScope, RequiresBackupFeature {
        RequiresBackupFeature::shouldRegister insteadof HasRequiredScope;
    }

    /**
     * Classified as mcp:wp-destructive on confidentiality grounds, not
     * mutation: this tool changes nothing, but the signed URL it hands out
     * points at a full site backup — database, password hashes, PII and
     * all. That is worth as much as any mutating action here. Do not
     * "correct" this back to mcp:wp just because it's read-only.
     */
    protected function requiredScope(): string
    {
        return 'mcp:wp-destructive';
    }

    protected string $name = 'wp-download-backup';

    protected string $description = <<<'MARKDOWN'
        Get a download URL for a WordPress backup. Returns a temporary signed URL 
        for cloud storage backups, or a direct download link for local backups.
        The URL expires after 1 hour for security.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        if ($denied = $this->assertBackupFeature() ?? $this->assertScope()) {
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

        // Check access
        if (!$this->canAccessProject($user, $project)) {
            return Response::error('You do not have access to this project.');
        }

        if ($backup->status !== 'completed') {
            return Response::error("Cannot download backup - status is '{$backup->status}'.");
        }

        if (!$backup->file_path) {
            return Response::error('Backup file not found.');
        }

        try {
            $storage = app(BackupStorageService::class);

            // Check if file exists
            if (!$storage->exists($backup->file_path)) {
                return Response::error('Backup file no longer exists in storage.');
            }

            // Try to get a temporary URL (for cloud storage)
            $tempUrl = $storage->temporaryUrl($backup->file_path, 60);

            if ($tempUrl) {
                return Response::text(
                    "📥 **Backup Download Ready**\n\n" .
                    "Project: {$project->name}\n" .
                    "Backup ID: {$backup->id}\n" .
                    "Size: " . $this->formatBytes($backup->file_size ?? 0) . "\n" .
                    "Created: {$backup->created_at->format('M d, Y H:i')}\n\n" .
                    "**Download URL** (expires in 1 hour):\n{$tempUrl}\n\n" .
                    "*Copy and paste this URL into your browser to download.*"
                );
            }

            // For local storage, provide the API endpoint
            $downloadUrl = url("/api/v1/backups/{$backup->id}/download");

            return Response::text(
                "📥 **Backup Download Ready**\n\n" .
                "Project: {$project->name}\n" .
                "Backup ID: {$backup->id}\n" .
                "Size: " . $this->formatBytes($backup->file_size ?? 0) . "\n" .
                "Created: {$backup->created_at->format('M d, Y H:i')}\n\n" .
                "**Download URL:**\n{$downloadUrl}\n\n" .
                "*Note: You'll need to be authenticated to download.*"
            );

        } catch (\Exception $e) {
            return Response::error('Failed to generate download URL: ' . $e->getMessage());
        }
    }

    private function canAccessProject($user, $project): bool
    {
        if ($user->role === 'admin') return true;
        if ($user->role === 'manager' && $project->isManagedBy($user)) return true;
        if ($user->role === 'developer' && $project->developers->contains('id', $user->id)) return true;
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
                ->description('The ID of the backup to download')
                ->required(),
        ];
    }
}
