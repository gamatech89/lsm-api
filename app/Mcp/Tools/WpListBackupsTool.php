<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use App\Mcp\Concerns\HasRequiredScope;
use App\Mcp\Concerns\RequiresBackupFeature;

class WpListBackupsTool extends Tool
{
    use HasRequiredScope, RequiresBackupFeature {
        RequiresBackupFeature::shouldRegister insteadof HasRequiredScope;
    }

    protected function requiredScope(): string
    {
        return 'mcp:wp';
    }

    protected string $name = 'wp-list-backups';

    protected string $description = <<<'MARKDOWN'
        List all backups for a WordPress site. Shows backup status, size, 
        creation date, and what each backup contains (database, files, uploads).
    MARKDOWN;

    public function handle(Request $request): Response
    {
        if ($denied = $this->assertBackupFeature() ?? $this->assertScope()) {
            return $denied;
        }

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
            $limit = $input['limit'] ?? 10;
            $backups = $project->backups()
                ->orderByDesc('created_at')
                ->take($limit)
                ->get();

            if ($backups->isEmpty()) {
                return Response::text(
                    "📦 **No Backups Found**\n\n" .
                    "Project: {$project->name}\n\n" .
                    "No backups have been created yet. Use `wp-create-backup` to create one."
                );
            }

            $output = "📦 **Backups for {$project->name}**\n\n";
            $output .= "| ID | Status | Type | Size | Created | Contents |\n";
            $output .= "|---|---|---|---|---|---|\n";

            foreach ($backups as $backup) {
                $status = match($backup->status) {
                    'completed' => '✅',
                    'in_progress' => '🔄',
                    'pending' => '⏳',
                    'failed' => '❌',
                    default => '❓',
                };

                $size = $backup->file_size 
                    ? $this->formatBytes($backup->file_size) 
                    : '-';

                $contents = [];
                if ($backup->includes_database) $contents[] = 'DB';
                if ($backup->includes_files) $contents[] = 'Files';
                if ($backup->includes_uploads) $contents[] = 'Uploads';

                $output .= "| {$backup->id} | {$status} {$backup->status} | {$backup->type} | {$size} | {$backup->created_at->format('M d, H:i')} | " . implode(', ', $contents) . " |\n";
            }

            $totalCount = $project->backups()->count();
            if ($totalCount > $limit) {
                $output .= "\n*Showing {$limit} of {$totalCount} total backups.*";
            }

            return Response::text($output);

        } catch (\Exception $e) {
            return Response::error('Failed to list backups: ' . $e->getMessage());
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
            'project_id' => $schema->integer()
                ->description('The ID of the WordPress project')
                ->required(),
            'limit' => $schema->integer()
                ->description('Maximum number of backups to show (default: 10)')
                ->default(10),
        ];
    }
}
