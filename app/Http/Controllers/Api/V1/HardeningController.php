<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectHardeningPause;
use App\Services\HardeningResponseMapper;
use App\Services\LsmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

    private const RULES = ['block_archives', 'block_debug_log', 'block_uploads_php'];

    /** The plugin's apply procedure self-tests over HTTP; worst case is about 60 s. */
    private const POST_TIMEOUT = 120;

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

        // Opportunistic close: the plugin resumed on its own and the platform
        // row is the only thing still open.
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
     * Turn one rule on or off.
     */
    public function setRule(Project $project, Request $request): JsonResponse
    {
        // The ability depends on the direction, so authorize on the cast bool
        // BEFORE validating: a missing or garbage `enabled` counts as a disable
        // (fail closed) — a non-admin gets 403, an admin gets the 422 below.
        $enabled = $request->boolean('enabled');
        Gate::authorize($enabled ? 'enableHardening' : 'disableHardening', $project);

        $validated = $request->validate([
            'rule' => 'required|string|in:' . implode(',', self::RULES),
            'enabled' => 'required|boolean',
        ]);

        $mapped = HardeningResponseMapper::map(
            LsmService::for($project)->hardeningRequest('POST', '/hardening/rule', [
                'rule' => $validated['rule'],
                'enabled' => $enabled,
            ], self::POST_TIMEOUT)
        );

        if ($mapped['outcome'] === 'ok' && $validated['rule'] === 'block_archives' && !$enabled) {
            $this->closeOpenPauses($project, 'Closed: block_archives turned off');
        }

        return $this->respond($project, $mapped);
    }

    /**
     * Pause the archive rule for a download (15, 30 or 60 minutes).
     */
    public function pause(Project $project, Request $request): JsonResponse
    {
        Gate::authorize('pauseHardening', $project);

        $validated = $request->validate([
            'minutes' => 'required|integer|in:15,30,60',
        ]);
        $minutes = (int) $validated['minutes'];

        // Write-ahead: the row exists before the plugin is called, so a pause
        // whose response gets lost is still found by hardening:resume-expired.
        $superseded = $this->closeOpenPauses($project, 'Superseded by a new pause');
        $pause = ProjectHardeningPause::create([
            'project_id' => $project->id,
            'user_id' => auth()->id(),
            'paused_until' => now()->addMinutes($minutes),
        ]);

        $lsm = LsmService::for($project);
        $mapped = HardeningResponseMapper::map(
            $lsm->hardeningRequest('POST', '/hardening/pause', ['minutes' => $minutes], self::POST_TIMEOUT)
        );

        if ($mapped['outcome'] === 'ok') {
            $until = $mapped['body']['status']['pause_until'] ?? null;
            if (is_int($until)) {
                $pause->update(['paused_until' => Carbon::createFromTimestamp($until)]);
            }
        } elseif ($mapped['outcome'] !== 'unreachable' || !$lsm->isConfigured()) {
            // The site answered and did not pause (success:false, old plugin, key
            // rejected), or nothing was sent at all (no LSM key): drop the row and
            // reopen whatever this call superseded.
            $pause->delete();
            ProjectHardeningPause::whereIn('id', $superseded)->update(['resumed_at' => null, 'note' => null]);
        }
        // unreachable: outcome unknown — the row stays and the backstop reconciles.

        return $this->respond($project, $mapped);
    }

    /**
     * Send a mapped plugin result. Bodies that carry a plugin status (ok, busy,
     * failed) also get open_pause, pause_overdue and can.
     */
    private function respond(Project $project, array $mapped): JsonResponse
    {
        $body = $mapped['body'];

        if (array_key_exists('status', $body)) {
            $body = array_merge($body, $this->platformState($project, $body['status']));
        }

        return response()->json($body, $mapped['code']);
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

        if ($ids === []) {
            return [];
        }

        // Close one id at a time and keep only the ones this call actually
        // closed: re-applying the open scope per row (rather than in one
        // whereIn update) means two calls landing in the same wall-clock
        // second can't misattribute a row the other one closed via a
        // resumed_at-timestamp match.
        $closedAt = now();
        $closed = [];
        foreach ($ids as $id) {
            $affected = ProjectHardeningPause::whereKey($id)
                ->open()
                ->update(['resumed_at' => $closedAt, 'note' => $note]);

            if ($affected === 1) {
                $closed[] = $id;
            }
        }

        return $closed;
    }
}
