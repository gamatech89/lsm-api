<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use App\Mcp\Concerns\HasRequiredScope;

class WpCheckConnectionsTool extends Tool
{
    use HasRequiredScope;

    protected function requiredScope(): string
    {
        return 'mcp:wp';
    }

    protected string $name = 'wp-check-connections';

    protected string $description = <<<'MARKDOWN'
        List which projects have WordPress remote management configured (LSM plugin API key set).
        This is an instant database check - no HTTP requests needed.
        Use this to find which sites have WordPress connection enabled.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        if ($denied = $this->assertScope()) {
            return $denied;
        }

        $user = Auth::user();
        $input = $request->all();

        // Only admins and managers can check connections
        if (!in_array($user->role, ['admin', 'manager'])) {
            return Response::error('Only admins and managers can check WordPress connections.');
        }

        $showAll = $input['show_all'] ?? false;

        // Fast database query - projects with API key configured = connected
        $connected = Project::whereNotNull('health_check_secret')
            ->where('health_check_secret', '!=', '')
            ->select('id', 'name', 'url', 'health_status', 'security_status', 'is_maintenance')
            ->get();

        $notConnected = Project::where(function ($q) {
            $q->whereNull('health_check_secret')
              ->orWhere('health_check_secret', '=', '');
        })
        ->select('id', 'name', 'url')
        ->get();

        $totalProjects = $connected->count() + $notConnected->count();

        // Build response
        $summary = "## WordPress Connection Status\n\n";
        $summary .= "**Total Projects**: {$totalProjects}\n";
        $summary .= "**✅ Connected (API Key Set)**: " . $connected->count() . "\n";
        $summary .= "**❌ Not Connected**: " . $notConnected->count() . "\n\n";

        if ($connected->isEmpty()) {
            $summary .= "> ⚠️ **No projects have WordPress connection configured!**\n";
            $summary .= "> To connect a project, install the LSM plugin on the WordPress site and save the API key.\n\n";
        } else {
            $summary .= "### ✅ Connected Projects (LSM Plugin Configured)\n\n";
            $summary .= "| ID | Name | URL | Health | Maintenance | Security |\n";
            $summary .= "|---|------|-----|--------|-------------|----------|\n";
            
            foreach ($connected as $p) {
                $health = $p->health_status ?? 'unknown';
                $security = $p->security_status ?? 'unknown';
                $maintenance = $p->is_maintenance ? '🔧 ON' : 'OFF';
                $url = $p->url ? substr($p->url, 0, 35) . (strlen($p->url) > 35 ? '...' : '') : 'No URL';
                $summary .= "| {$p->id} | {$p->name} | {$url} | {$health} | {$maintenance} | {$security} |\n";
            }
            $summary .= "\n";
        }

        if ($showAll && $notConnected->isNotEmpty()) {
            $summary .= "### ❌ Not Connected (No API Key)\n";
            foreach ($notConnected->take(20) as $p) {
                $summary .= "- **{$p->name}** (ID: {$p->id})\n";
            }
            if ($notConnected->count() > 20) {
                $summary .= "- ...and " . ($notConnected->count() - 20) . " more\n";
            }
        }

        return Response::text($summary);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'show_all' => $schema->boolean()
                ->description('If true, also list projects that are NOT connected'),
        ];
    }
}
