<?php
// tests/Feature/Scanner/ScanIdForwardingTest.php
use App\Models\Project;
use App\Services\LsmService;
use Illuminate\Support\Facades\Http;

function scanForwardingProject(): Project
{
    return Project::factory()->create([
        'url' => 'https://client.example.com',
        'health_check_secret' => 'SECRETKEY123',
    ]);
}

it('includes scan_id in the runSecurityScan POST body when provided', function () {
    Http::fake([
        '*' => Http::response(['success' => true, 'status' => 'clean', 'summary' => []], 200),
    ]);

    $project = scanForwardingProject();

    LsmService::for($project)->runSecurityScan(null, 'full', 42);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/security/scan')
            && $request['scan_type'] === 'full'
            && $request['scan_id'] === 42;
    });
});

it('omits scan_id from the runSecurityScan POST body when not provided', function () {
    Http::fake([
        '*' => Http::response(['success' => true, 'status' => 'clean', 'summary' => []], 200),
    ]);

    $project = scanForwardingProject();

    LsmService::for($project)->runSecurityScan(null, 'full');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/security/scan')
            && ! array_key_exists('scan_id', $request->data());
    });
});

it('includes scan_id as a query param on runQuickScan when provided', function () {
    Http::fake([
        '*' => Http::response(['success' => true, 'status' => 'clean', 'summary' => []], 200),
    ]);

    $project = scanForwardingProject();

    LsmService::for($project)->runQuickScan(7);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/security/scan/quick')
            && str_contains($request->url(), 'scan_id=7');
    });
});

it('omits scan_id query param on runQuickScan when not provided', function () {
    Http::fake([
        '*' => Http::response(['success' => true, 'status' => 'clean', 'summary' => []], 200),
    ]);

    $project = scanForwardingProject();

    LsmService::for($project)->runQuickScan();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/security/scan/quick')
            && ! str_contains($request->url(), 'scan_id');
    });
});

it('forwards the created scan id to the plugin when triggering a full scan via the controller', function () {
    Http::fake([
        '*' => Http::response(['success' => true, 'status' => 'clean', 'summary' => []], 200),
    ]);

    $project = scanForwardingProject();
    $user = \App\Models\User::factory()->create();

    $this->actingAs($user)
        ->postJson("/api/v1/projects/{$project->id}/lsm/security-scan", ['scan_type' => 'standard'])
        ->assertOk();

    $scanId = \App\Models\SecurityScan::first()->id;

    Http::assertSent(function ($request) use ($scanId) {
        return str_contains($request->url(), '/security/scan')
            && $request['scan_id'] === $scanId;
    });
});

it('forwards the created scan id to the plugin when the scheduled security:scan command runs a full scan', function () {
    Http::fake([
        '*' => Http::response(['success' => true, 'status' => 'clean', 'summary' => []], 200),
    ]);

    $project = scanForwardingProject();

    $this->artisan('security:scan')->assertExitCode(0);

    $scanId = \App\Models\SecurityScan::first()->id;

    Http::assertSent(function ($request) use ($scanId) {
        return str_contains($request->url(), '/security/scan')
            && $request['scan_id'] === $scanId;
    });
});

it('forwards the created scan id to the plugin when the scheduled security:scan command runs a quick scan', function () {
    Http::fake([
        '*' => Http::response(['success' => true, 'status' => 'clean', 'summary' => []], 200),
    ]);

    $project = scanForwardingProject();

    $this->artisan('security:scan', ['--quick' => true])->assertExitCode(0);

    $scanId = \App\Models\SecurityScan::first()->id;

    Http::assertSent(function ($request) use ($scanId) {
        return str_contains($request->url(), '/security/scan/quick')
            && str_contains($request->url(), "scan_id={$scanId}");
    });
});
