<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Services\LsmService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use App\Mcp\Concerns\HasRequiredScope;

class WpGetUpdatesTool extends Tool
{
    use HasRequiredScope;

    protected function requiredScope(): string
    {
        return 'mcp:wp';
    }

    protected string $name = 'wp-get-updates';

    protected string $description = <<<'MARKDOWN'
        Check available updates for a WordPress site. Shows WordPress core 
        updates, plugin updates, and theme updates that are pending.
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
            $lsmService = LsmService::for($project);
            $updates = $lsmService->getAvailableUpdates();

            if ($updates === null) {
                return Response::error('Failed to check updates: No response from WordPress plugin.');
            }
            
            $text = "📦 **Available Updates for {$project->name}**\n\n";

            // Core update
            if (!empty($updates['core'])) {
                $text .= "### WordPress Core\n";
                $current = $updates['core']['current_version'] ?? $updates['core']['current'] ?? 'Unknown';
                $latest = $updates['core']['new_version'] ?? $updates['core']['latest'] ?? 'Unknown';
                $text .= "Current: {$current} → Available: {$latest}\n\n";
            } else {
                $text .= "### WordPress Core\n";
                $text .= "✅ Up to date\n\n";
            }

            // Plugin updates
            $text .= "### Plugins\n";
            $plugins = $updates['plugins'] ?? [];
            if (!empty($plugins) && is_array($plugins)) {
                foreach ($plugins as $plugin) {
                    $name = $plugin['name'] ?? $plugin['title'] ?? 'Unknown plugin';
                    $current = $plugin['current_version'] ?? $plugin['current'] ?? '?';
                    $latest = $plugin['new_version'] ?? $plugin['latest'] ?? '?';
                    $text .= "- **{$name}**: {$current} → {$latest}\n";
                }
            } else {
                $text .= "✅ All plugins up to date\n";
            }
            $text .= "\n";

            // Theme updates
            $text .= "### Themes\n";
            $themes = $updates['themes'] ?? [];
            if (!empty($themes) && is_array($themes)) {
                foreach ($themes as $theme) {
                    $name = $theme['name'] ?? $theme['title'] ?? 'Unknown theme';
                    $current = $theme['current_version'] ?? $theme['current'] ?? '?';
                    $latest = $theme['new_version'] ?? $theme['latest'] ?? '?';
                    $text .= "- **{$name}**: {$current} → {$latest}\n";
                }
            } else {
                $text .= "✅ All themes up to date\n";
            }

            return Response::text($text);
        } catch (\Exception $e) {
            return Response::error('Failed to check updates: ' . $e->getMessage());
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
                ->description('The ID of the WordPress project to check for updates')
                ->required(),
        ];
    }
}
