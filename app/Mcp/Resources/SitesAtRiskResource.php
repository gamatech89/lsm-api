<?php

namespace App\Mcp\Resources;

use App\Models\Project;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class SitesAtRiskResource extends Resource
{
    protected string $name = 'sites-at-risk';

    protected string $uri = 'lsm://sites/at-risk';

    protected string $description = <<<'MARKDOWN'
        WordPress sites that require immediate attention due to security issues,
        being offline, or having critical problems. These should be addressed first.
    MARKDOWN;

    protected string $mimeType = 'application/json';

    public function handle(Request $request): Response
    {
        $user = Auth::user();

        $query = Project::where(function ($q) {
            $q->whereIn('health_status', ['offline', 'down_error'])
              ->orWhereIn('security_status', ['at_risk', 'compromised', 'hacked']);
        })
        ->with(['manager:id,name'])
        ->select([
            'id', 'name', 'url', 'health_status', 'security_status',
            'manager_id', 'last_health_check', 'updated_at'
        ]);

        // Apply role-based filtering
        if ($user->role === 'developer') {
            $query->whereHas('developers', fn($q) => $q->where('user_id', $user->id));
        } elseif ($user->role === 'manager') {
            $query->where('manager_id', $user->id);
        }
        // Admin sees all

        $sites = $query->orderByRaw("
            CASE 
                WHEN security_status = 'hacked' THEN 1
                WHEN security_status = 'compromised' THEN 2
                WHEN security_status = 'at_risk' THEN 3
                WHEN health_status = 'offline' THEN 4
                ELSE 5
            END
        ")->get();

        $data = [
            'count' => $sites->count(),
            'critical' => $sites->where('security_status', 'hacked')->count(),
            'at_risk' => $sites->whereIn('security_status', ['at_risk', 'compromised'])->count(),
            'offline' => $sites->whereIn('health_status', ['offline', 'down_error'])->count(),
            'sites' => $sites->map(fn($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'url' => $s->url,
                'health_status' => $s->health_status,
                'security_status' => $s->security_status,
                'severity' => $this->getSeverity($s),
                'manager' => $s->manager?->name,
                'last_health_check' => $s->last_health_check?->toIso8601String(),
            ]),
        ];

        return Response::json($data);
    }

    private function getSeverity(Project $project): string
    {
        if ($project->security_status === 'hacked') return 'critical';
        if ($project->security_status === 'compromised') return 'high';
        if ($project->security_status === 'at_risk') return 'medium';
        if (in_array($project->health_status, ['offline', 'down_error'])) return 'high';
        return 'low';
    }
}
