<?php
// tests/Feature/Scanner/ScanResultPersistenceTest.php
//
// Regression test for the Critical finding in the final whole-branch review:
// SecurityScanController::scan() must read the risk level from
// summary.risk_level and the file count from summary.total_files_scanned —
// NOT from the top-level `status` (which is the scan LIFECYCLE status,
// 'completed'|'partial') or from a nonexistent `summary.files_scanned` key.

use App\Models\Project;
use App\Models\SecurityScan;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function persistenceProject(): Project
{
    return Project::factory()->create([
        'url' => 'https://client.example.com',
        'health_check_secret' => 'SECRETKEY123',
    ]);
}

it('persists the real risk_level and file count from summary, not the lifecycle status', function () {
    // The frozen ScanSession::assembleResults() shape, as the plugin returns it,
    // wrapped in the LSM standard { success, data } envelope.
    Http::fake([
        '*' => Http::response([
            'success' => true,
            'data' => [
                'scan_id' => 999,
                'started_at' => '2026-07-16T10:00:00Z',
                'completed_at' => '2026-07-16T10:05:00Z',
                'duration_seconds' => 300,
                'status' => 'completed', // scan LIFECYCLE status — must NOT be used as risk_level
                'summary' => [
                    'total_files_scanned' => 1234,
                    'threats_found' => 3,
                    'warnings_found' => 5,
                    'clean' => false,
                    'risk_level' => 'critical', // the REAL risk
                ],
                'results' => [
                    'malware_signatures' => ['status' => 'fail', 'findings' => []],
                ],
            ],
        ], 200),
    ]);

    $project = persistenceProject();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson("/api/v1/projects/{$project->id}/lsm/security-scan", ['scan_type' => 'full'])
        ->assertOk();

    $scan = SecurityScan::firstOrFail();

    expect($scan->risk_level)->toBe('critical');
    expect($scan->files_scanned)->toBe(1234);
    expect($scan->threats_found)->toBe(3);
    expect($scan->warnings_found)->toBe(5);
    expect($scan->status)->toBe('completed');

    $project->refresh();
    expect($project->last_security_scan_risk)->toBe('critical');
});

it('records the scan lifecycle status as partial when the plugin reports a partial scan', function () {
    Http::fake([
        '*' => Http::response([
            'success' => true,
            'data' => [
                'scan_id' => 1000,
                'started_at' => '2026-07-16T10:00:00Z',
                'completed_at' => '2026-07-16T10:05:00Z',
                'duration_seconds' => 120,
                'status' => 'partial',
                'summary' => [
                    'total_files_scanned' => 42,
                    'threats_found' => 0,
                    'warnings_found' => 1,
                    'clean' => true,
                    'risk_level' => 'low',
                ],
                'results' => [],
            ],
        ], 200),
    ]);

    $project = persistenceProject();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson("/api/v1/projects/{$project->id}/lsm/security-scan", ['scan_type' => 'full'])
        ->assertOk();

    $scan = SecurityScan::firstOrFail();

    expect($scan->status)->toBe('partial');
    expect($scan->risk_level)->toBe('low');
    expect($scan->files_scanned)->toBe(42);
});
