<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\SecurityScan;
use App\Notifications\MalwareDetectedNotification;
use App\Services\LsmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SecurityScanController extends Controller
{
    /**
     * List security scans for a project.
     */
    public function index(Project $project, Request $request): JsonResponse
    {
        $scans = $project->securityScans()
            ->orderBy('created_at', 'desc')
            ->limit($request->input('limit', 20))
            ->get();

        return response()->json([
            'success' => true,
            'data' => $scans,
        ]);
    }

    /**
     * Get a single scan with full results.
     */
    public function show(SecurityScan $securityScan): JsonResponse
    {
        $securityScan->load('project', 'triggeredByUser');

        return response()->json([
            'success' => true,
            'data' => $securityScan,
        ]);
    }

    /**
     * Trigger a security scan for a project via the WP plugin.
     */
    public function scan(Project $project, Request $request): JsonResponse
    {
        $request->validate([
            'scan_type' => 'in:full,quick',
            'modules' => 'nullable|string',
        ]);

        $scanType = $request->input('scan_type', 'full');
        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json([
                'success' => false,
                'message' => 'LSM plugin is not configured for this project.',
            ], 422);
        }

        // Create a pending scan record
        $scan = SecurityScan::create([
            'project_id' => $project->id,
            'scan_type' => $scanType,
            'status' => 'running',
            'triggered_by' => 'manual',
            'triggered_by_user_id' => auth()->id(),
            'started_at' => now(),
        ]);

        // Run the scan via WP plugin API
        if ($scanType === 'quick') {
            $results = $lsm->runQuickScan();
        } else {
            $modules = $request->input('modules')
                ? explode(',', $request->input('modules'))
                : null;
            $results = $lsm->runSecurityScan($modules);
        }

        if (!$results) {
            $scan->update([
                'status' => 'failed',
                'completed_at' => now(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to communicate with WordPress plugin. Ensure the site is reachable and the plugin is active.',
                'data' => $scan,
            ], 502);
        }

        // Store the results
        $riskLevel = $results['status'] ?? 'unknown';
        $summary = $results['summary'] ?? [];

        $scan->update([
            'status' => 'completed',
            'risk_level' => $riskLevel,
            'threats_found' => $summary['threats_found'] ?? 0,
            'warnings_found' => $summary['warnings_found'] ?? 0,
            'files_scanned' => $summary['files_scanned'] ?? 0,
            'duration_seconds' => $results['duration_seconds'] ?? null,
            'results' => $results,
            'summary' => $summary,
            'completed_at' => now(),
        ]);

        // Update project metadata
        $project->update([
            'last_security_scan_at' => now(),
            'last_security_scan_risk' => $riskLevel,
        ]);

        // Send notification if threats found
        if (($summary['threats_found'] ?? 0) > 0) {
            $this->notifyThreatsDetected($project, $scan);
        }

        return response()->json([
            'success' => true,
            'data' => $scan->fresh(),
        ]);
    }

    /**
     * Get the latest scan for a project.
     */
    public function latest(Project $project): JsonResponse
    {
        $scan = $project->securityScans()
            ->where('status', 'completed')
            ->orderBy('completed_at', 'desc')
            ->first();

        return response()->json([
            'success' => true,
            'data' => $scan,
        ]);
    }

    /**
     * Get scan statistics for a project.
     */
    public function stats(Project $project): JsonResponse
    {
        $scans = $project->securityScans()->where('status', 'completed');

        return response()->json([
            'success' => true,
            'data' => [
                'total_scans' => $scans->count(),
                'last_scan' => $project->last_security_scan_at,
                'current_risk' => $project->last_security_scan_risk,
                'scans_with_threats' => (clone $scans)->where('threats_found', '>', 0)->count(),
                'last_7_days' => (clone $scans)->where('completed_at', '>=', now()->subDays(7))->count(),
            ],
        ]);
    }

    /**
     * Send notification when threats are detected.
     */
    protected function notifyThreatsDetected(Project $project, SecurityScan $scan): void
    {
        // Notify project managers
        foreach ($project->managers as $manager) {
            $manager->notify(new MalwareDetectedNotification($project, $scan));
        }

        // If no managers, notify the legacy single manager
        if ($project->managers->isEmpty() && $project->manager) {
            $project->manager->notify(new MalwareDetectedNotification($project, $scan));
        }
    }
}
