<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectHardeningPause;
use App\Services\HardeningResponseMapper;
use App\Services\LsmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Managed .htaccess hardening (plugin 2.10.0+).
 *
 * Every plugin call goes through LsmService::hardeningRequest() and
 * HardeningResponseMapper — never through the unwrapping get()/post() helpers.
 */
class HardeningController extends Controller
{
    /**
     * A write-ahead pause row younger than this may belong to a pause call that
     * is still running (the POST timeout is 120 s), so status reads leave it alone.
     */
    private const IN_FLIGHT_SECONDS = 180;

    /**
     * Hardening status for the Security panel. Always answers 200 so an old
     * plugin or an unreachable site renders a quiet state, not an error.
     */
    public function show(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $result = LsmService::for($project)->hardeningRequest('GET', '/hardening/status');

        $status = $result['http'] === 200 && is_array($result['json']['status'] ?? null)
            ? $result['json']['status']
            : null;
        $pluginOutdated = $result['http'] === 404;

        // Opportunistic close: the plugin resumed on its own (or the rule was
        // changed on the site) and the platform row is the only thing still open.
        // Only a confirmed `on` proves the pause ended: mid-operation the plugin can report
        // `drift` (rule out of the file, pause_until not yet committed) — closing then would
        // leave a finisher-less host with no resumer at all.
        $archives = $status['rules']['block_archives']['state'] ?? null;
        if ($archives === 'on' && ($status['pause_until'] ?? null) === null) {
            $this->closeOpenPauses($project, 'Closed from status: site no longer paused', true);
        }

        return response()->json(array_merge([
            'reachable' => $status !== null || $pluginOutdated,
            'plugin_outdated' => $pluginOutdated,
            'min_version' => HardeningResponseMapper::MIN_PLUGIN_VERSION,
            'status' => $status,
        ], $this->platformState($project, $status)));
    }

    /**
     * The keys the platform adds next to the plugin status.
     */
    private function platformState(Project $project, ?array $status): array
    {
        $open = ProjectHardeningPause::open()
            ->where('project_id', $project->id)
            ->latest('id')
            ->first();

        return [
            'open_pause' => $open?->toArray(),
            'pause_overdue' => ($open !== null && $open->paused_until->isPast())
                || (bool) ($status['pause_overdue'] ?? false),
            'can' => [
                'pause' => Gate::allows('pauseHardening', $project),
                'enable' => Gate::allows('enableHardening', $project),
                'disable' => Gate::allows('disableHardening', $project),
            ],
        ];
    }

    /**
     * Close the project's open pause rows and return the ids that were closed.
     */
    private function closeOpenPauses(Project $project, string $note, bool $skipInFlight = false): array
    {
        $query = ProjectHardeningPause::open()->where('project_id', $project->id);

        if ($skipInFlight) {
            $query->where('created_at', '<', now()->subSeconds(self::IN_FLIGHT_SECONDS));
        }

        $ids = $query->pluck('id')->all();

        if ($ids !== []) {
            ProjectHardeningPause::whereIn('id', $ids)->update(['resumed_at' => now(), 'note' => $note]);
        }

        return $ids;
    }
}
