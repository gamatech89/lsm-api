<?php

use App\Models\Project;
use App\Models\UptimeCheck;
use App\Models\User;
use App\Notifications\ProjectStatusChangedNotification;
use App\Notifications\SiteDownNotification;
use App\Notifications\SiteRecoveredNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

function monitoredProject(array $attrs = []): Project
{
    return Project::factory()->create(array_merge([
        'url' => 'https://site.example.com',
        'health_status' => 'online',
        'uptime_monitoring_enabled' => true,
        'health_check_secret' => null,
    ], $attrs));
}

// ---------------------------------------------------------------------------
// Confirm-before-alert state machine (sites:check-uptime)
// ---------------------------------------------------------------------------

test('first failed check marks confirming_down and does not notify', function () {
    Notification::fake();
    User::factory()->create(['role' => 'admin']);
    $project = monitoredProject();

    Http::fake(['*' => Http::response('server error', 500)]);

    $this->artisan('sites:check-uptime');

    expect($project->fresh()->health_status)->toBe('confirming_down');
    Notification::assertNothingSent();
});

test('second consecutive failure confirms down_error and notifies admins', function () {
    Notification::fake();
    $admin = User::factory()->create(['role' => 'admin']);
    $project = monitoredProject(['health_status' => 'confirming_down']);

    Http::fake(['*' => Http::response('server error', 500)]);

    $this->artisan('sites:check-uptime');

    expect($project->fresh()->health_status)->toBe('down_error');
    Notification::assertSentTo($admin, SiteDownNotification::class);
});

test('recovery from confirmed down flips to online and sends recovery notification', function () {
    Notification::fake();
    $admin = User::factory()->create(['role' => 'admin']);
    $project = monitoredProject(['health_status' => 'down_error']);

    Http::fake(['*' => Http::response('ok', 200)]);

    $this->artisan('sites:check-uptime');

    expect($project->fresh()->health_status)->toBe('online');
    Notification::assertSentTo($admin, SiteRecoveredNotification::class);
});

test('recovery from unconfirmed confirming_down does not send a recovery notification', function () {
    Notification::fake();
    User::factory()->create(['role' => 'admin']);
    $project = monitoredProject(['health_status' => 'confirming_down']);

    Http::fake(['*' => Http::response('ok', 200)]);

    $this->artisan('sites:check-uptime');

    expect($project->fresh()->health_status)->toBe('online');
    Notification::assertNothingSent();
});

test('archived and monitoring-disabled projects are not checked', function () {
    Notification::fake();
    $archived = monitoredProject(['status' => 'archived']);
    $disabled = monitoredProject(['uptime_monitoring_enabled' => false]);

    Http::fake(['*' => Http::response('server error', 500)]);

    $this->artisan('sites:check-uptime');

    expect($archived->fresh()->health_status)->toBe('online');
    expect($disabled->fresh()->health_status)->toBe('online');
    expect(UptimeCheck::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// SSL status ownership
// ---------------------------------------------------------------------------

test('uptime checker does not clobber ssl_status set by the deep health check', function () {
    $project = monitoredProject([
        'health_check_secret' => 'SECRET123',
        'ssl_status' => 'expiring_soon',
    ]);

    Http::fake([
        '*' => Http::response([
            'success' => true,
            'data' => [
                'wordpress' => ['version' => '6.5'],
                'php' => ['version' => '8.2'],
                'plugins' => ['outdated_count' => 3],
                'ssl' => ['enabled' => true],
            ],
        ], 200),
    ]);

    $this->artisan('sites:check-uptime');

    $fresh = $project->fresh();
    expect($fresh->ssl_status)->toBe('expiring_soon');
    expect($fresh->wp_version)->toBe('6.5');
    expect($fresh->outdated_plugins_count)->toBe(3);
});

// ---------------------------------------------------------------------------
// projects:health-check is a data collector, not a status owner
// ---------------------------------------------------------------------------

test('health-check command does not change health_status or notify on failure', function () {
    Notification::fake();
    User::factory()->create(['role' => 'admin']);
    $project = monitoredProject(['url' => 'http://site.example.com']);

    Http::fake(['*' => Http::response('server error', 500)]);

    $this->artisan('projects:health-check');

    $fresh = $project->fresh();
    expect($fresh->health_status)->toBe('online');
    expect($fresh->last_health_check_at)->not->toBeNull();
    Notification::assertNotSentTo(User::all(), ProjectStatusChangedNotification::class);
});

test('health-check command skips archived and monitoring-disabled projects', function () {
    $archived = monitoredProject(['url' => 'http://a.example.com', 'status' => 'archived']);
    $disabled = monitoredProject(['url' => 'http://b.example.com', 'uptime_monitoring_enabled' => false]);

    Http::fake(['*' => Http::response('ok', 200)]);

    $this->artisan('projects:health-check');

    expect($archived->fresh()->last_health_check_at)->toBeNull();
    expect($disabled->fresh()->last_health_check_at)->toBeNull();
});

test('deep health check parses the lsm/v1 envelope correctly', function () {
    $project = monitoredProject([
        'url' => 'http://site.example.com',
        'health_check_secret' => 'SECRET123',
    ]);

    Http::fake([
        'site.example.com/wp-json/lsm/v1/health*' => Http::response([
            'success' => true,
            'data' => [
                'wordpress' => ['version' => '6.5.2'],
                'php' => ['version' => '8.3.1'],
                'plugins' => ['outdated_count' => 4],
                'ssl' => ['enabled' => true],
            ],
        ], 200),
        '*' => Http::response('ok', 200),
    ]);

    $this->artisan('projects:health-check --deep');

    $fresh = $project->fresh();
    expect($fresh->wp_version)->toBe('6.5.2');
    expect($fresh->php_version)->toBe('8.3.1');
    expect($fresh->outdated_plugins_count)->toBe(4);
});

// ---------------------------------------------------------------------------
// Uptime statistics
// ---------------------------------------------------------------------------

test('getStats returns null percentage when there are no completed checks', function () {
    $project = monitoredProject();

    $stats = UptimeCheck::getStats($project->id);

    expect($stats['total_checks'])->toBe(0);
    expect($stats['uptime_percentage'])->toBeNull();
    expect($stats['avg_response_time'])->toBeNull();
});

test('getStats with only confirming rows does not divide by zero', function () {
    $project = monitoredProject();
    UptimeCheck::create([
        'project_id' => $project->id,
        'status' => 'confirming',
        'checked_at' => now(),
    ]);

    $stats = UptimeCheck::getStats($project->id);

    expect($stats['total_checks'])->toBe(0);
    expect($stats['uptime_percentage'])->toBeNull();
});

test('getStats computes percentage from completed checks only', function () {
    $project = monitoredProject();
    foreach (['up', 'up', 'up', 'down', 'confirming'] as $status) {
        UptimeCheck::create([
            'project_id' => $project->id,
            'status' => $status,
            'response_time_ms' => $status === 'up' ? 100 : null,
            'checked_at' => now(),
        ]);
    }

    $stats = UptimeCheck::getStats($project->id);

    expect($stats['total_checks'])->toBe(4);
    expect($stats['uptime_percentage'])->toBe(75.0);
});

test('uptime-stats endpoint returns null percentage for a project without history', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $project = monitoredProject();

    $this->actingAs($admin)
        ->getJson("/api/v1/projects/{$project->id}/uptime-stats")
        ->assertOk()
        ->assertJsonPath('data.total_checks', 0)
        ->assertJsonPath('data.uptime_percentage', null);
});

// ---------------------------------------------------------------------------
// Retention
// ---------------------------------------------------------------------------

test('old uptime checks are pruned', function () {
    $project = monitoredProject();
    UptimeCheck::create([
        'project_id' => $project->id,
        'status' => 'up',
        'checked_at' => now()->subDays(120),
    ]);
    UptimeCheck::create([
        'project_id' => $project->id,
        'status' => 'up',
        'checked_at' => now()->subDays(5),
    ]);

    $this->artisan('model:prune', ['--model' => UptimeCheck::class]);

    expect(UptimeCheck::count())->toBe(1);
    expect(UptimeCheck::first()->checked_at->isAfter(now()->subDays(30)))->toBeTrue();
});

// ---------------------------------------------------------------------------
// API contract
// ---------------------------------------------------------------------------

test('project resource exposes monitoring flags but not the plaintext secret', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $project = monitoredProject(['health_check_secret' => 'SUPERSECRET']);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/projects/{$project->id}")
        ->assertOk();

    $response->assertJsonPath('data.uptime_monitoring_enabled', true)
        ->assertJsonPath('data.has_health_check_secret', true)
        ->assertJsonMissingPath('data.health_check_secret');
});
