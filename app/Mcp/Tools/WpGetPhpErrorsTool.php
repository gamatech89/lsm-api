<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Models\PhpError;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use App\Mcp\Concerns\HasRequiredScope;

class WpGetPhpErrorsTool extends Tool
{
    use HasRequiredScope;

    protected function requiredScope(): string
    {
        return 'mcp:wp';
    }

    protected string $name = 'wp-get-php-errors';

    protected string $description = <<<'MARKDOWN'
        Get PHP errors from a WordPress site. Shows error type, message, file, 
        line number, and occurrence count. Useful for debugging site issues and 
        identifying problematic plugins or code.
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

        $project = Project::with('developers')->find($input['project_id']);

        if (!$project) {
            return Response::error("Project with ID {$input['project_id']} not found.");
        }

        // Check access
        if (!$this->canAccessProject($user, $project)) {
            return Response::error('You do not have access to this project.');
        }

        try {
            $query = PhpError::where('project_id', $project->id);

            // Filter by type
            if (!empty($input['type'])) {
                $query->where('type', $input['type']);
            }

            // Filter by resolved status
            if (isset($input['unresolved_only']) && $input['unresolved_only']) {
                $query->where('is_resolved', false);
            }

            $limit = $input['limit'] ?? 20;
            $errors = $query->orderByDesc('last_seen_at')
                ->take($limit)
                ->get();

            // Get summary stats
            $stats = [
                'total' => PhpError::where('project_id', $project->id)->count(),
                'fatal' => PhpError::where('project_id', $project->id)->where('type', 'fatal')->where('is_resolved', false)->count(),
                'warning' => PhpError::where('project_id', $project->id)->where('type', 'warning')->where('is_resolved', false)->count(),
                'notice' => PhpError::where('project_id', $project->id)->where('type', 'notice')->where('is_resolved', false)->count(),
                'deprecated' => PhpError::where('project_id', $project->id)->where('type', 'deprecated')->where('is_resolved', false)->count(),
            ];

            if ($errors->isEmpty()) {
                return Response::text(
                    "✅ **No PHP Errors Found**\n\n" .
                    "Project: {$project->name}\n\n" .
                    "Great news! There are no PHP errors recorded for this site."
                );
            }

            $output = "🐛 **PHP Errors for {$project->name}**\n\n";
            
            // Summary
            $output .= "**Summary:**\n";
            if ($stats['fatal'] > 0) $output .= "- 🔴 Fatal: {$stats['fatal']}\n";
            if ($stats['warning'] > 0) $output .= "- 🟠 Warnings: {$stats['warning']}\n";
            if ($stats['notice'] > 0) $output .= "- 🟡 Notices: {$stats['notice']}\n";
            if ($stats['deprecated'] > 0) $output .= "- 🔵 Deprecated: {$stats['deprecated']}\n";
            $output .= "\n---\n\n";

            foreach ($errors as $error) {
                $icon = match($error->type) {
                    'fatal' => '🔴',
                    'warning' => '🟠',
                    'notice' => '🟡',
                    'deprecated' => '🔵',
                    default => '⚪',
                };

                $status = $error->is_resolved ? '✅ Resolved' : '';

                $output .= "{$icon} **{$error->type}** {$status}\n";
                $output .= "Message: `{$this->truncate($error->message, 100)}`\n";
                
                if ($error->file) {
                    $filename = basename($error->file);
                    $output .= "File: `{$filename}` line {$error->line}\n";
                }
                
                if ($error->plugin_slug) {
                    $output .= "Plugin: `{$error->plugin_slug}`\n";
                }
                
                $output .= "Occurrences: {$error->count} | Last seen: {$error->last_seen_at->diffForHumans()}\n";
                $output .= "\n";
            }

            if ($stats['total'] > $limit) {
                $output .= "\n*Showing {$limit} of {$stats['total']} total errors.*";
            }

            return Response::text($output);

        } catch (\Exception $e) {
            return Response::error('Failed to get PHP errors: ' . $e->getMessage());
        }
    }

    private function canAccessProject($user, $project): bool
    {
        if ($user->role === 'admin') return true;
        if ($user->role === 'manager' && $project->isManagedBy($user)) return true;
        if ($user->role === 'developer' && $project->developers->contains('id', $user->id)) return true;
        return false;
    }

    private function truncate(string $text, int $length): string
    {
        if (strlen($text) <= $length) return $text;
        return substr($text, 0, $length) . '...';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()
                ->description('The ID of the WordPress project')
                ->required(),
            'type' => $schema->string()
                ->description('Filter by error type: fatal, warning, notice, deprecated')
                ->enum(['fatal', 'warning', 'notice', 'deprecated']),
            'unresolved_only' => $schema->boolean()
                ->description('Only show unresolved errors (default: false)')
                ->default(false),
            'limit' => $schema->integer()
                ->description('Maximum number of errors to show (default: 20)')
                ->default(20),
        ];
    }
}
