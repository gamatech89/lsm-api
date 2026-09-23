<?php

use App\Models\ProjectHardeningPause;
use App\Notifications\HardeningPauseOverdueNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * An open pause row whose paused_until passed $minutesOverdue minutes ago,
 * created 60 minutes before that (a 60-minute pause).
 */
function expiredHardeningPause(int $minutesOverdue = 5, array $attrs = [], array $projectAttrs = []): ProjectHardeningPause
{
    return ProjectHardeningPause::factory()->create(array_merge([
        'project_id' => hardeningProject($projectAttrs)->id,
        'user_id' => hardeningUser('developer')->id,
        'paused_until' => now()->subMinutes($minutesOverdue),
        'created_at' => now()->subMinutes($minutesOverdue + 60),
    ], $attrs));
}

function fakeBackstopResume(array $body, int $http = 200): void
{
    Http::fake(['*/wp-json/lsm/v1/hardening/resume' => Http::response($body, $http)]);
}

// ---------------------------------------------------------------------------
// Which rows are picked up
// ---------------------------------------------------------------------------

test('an expired row is resumed with a POST and a 60 s timeout, never decided from a GET, and closed when the rule is on', function () {
    Notification::fake();
    $seenTimeout = null;
    Http::fake(function ($request, array $options) use (&$seenTimeout) {
        $seenTimeout = $options['timeout'] ?? null;

        return Http::response(hardeningPluginBody(true, null, hardeningPluginStatus('on')), 200);
    });
    $pause = expiredHardeningPause(5);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    expect($pause->fresh()->resumed_at)->not->toBeNull();
    expect($pause->fresh()->failed_at)->toBeNull();
    expect($pause->fresh()->note)->toBe('Closed by backstop: archive rule is on');
    expect($seenTimeout)->toBe(60);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://client.example.com/wp-json/lsm/v1/hardening/resume');
    Notification::assertNothingSent();
});

test('a row that has not expired yet is left alone', function () {
    Http::fake();
    $pause = ProjectHardeningPause::factory()->create([
        'project_id' => hardeningProject()->id,
        'paused_until' => now()->addMinutes(10),
    ]);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    expect($pause->fresh()->resumed_at)->toBeNull();
    Http::assertNothingSent();
});

test('rows that are already resumed or given up on are left alone', function () {
    Http::fake();
    expiredHardeningPause(5, ['resumed_at' => now()->subMinute()]);
    expiredHardeningPause(5, ['failed_at' => now()->subMinute()]);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------
// Outcome of the resume POST
// ---------------------------------------------------------------------------

test('an unreachable site leaves the row open for the next run', function (string $failure) {
    Notification::fake();
    Http::fake(['*' => match ($failure) {
        'connection failure' => Http::failedConnection(),
        'http 500' => Http::response('server error', 500),
    }]);
    $pause = expiredHardeningPause(5);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);
    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    expect($pause->fresh()->resumed_at)->toBeNull();
    expect($pause->fresh()->failed_at)->toBeNull();
    Http::assertSentCount(2); // retried on the second run
    Notification::assertNothingSent();
})->with(['connection failure', 'http 500']);

test('a response that does not show the rule on leaves the row open', function (array $body) {
    fakeBackstopResume($body);
    $pause = expiredHardeningPause(5);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    expect($pause->fresh()->resumed_at)->toBeNull();
    expect($pause->fresh()->failed_at)->toBeNull();
})->with([
    'resume failed, still paused' => [hardeningPluginBody(false, 'asset_broken', hardeningPluginStatus('paused', 1790000000, true))],
    'lock busy, still paused' => [hardeningPluginBody(false, 'busy', hardeningPluginStatus('paused', 1790000000, true))],
    'success but the rule is off' => [hardeningPluginBody(true, null, hardeningPluginStatus('off'))],
    'success but drifted' => [hardeningPluginBody(true, null, hardeningPluginStatus('drift'))],
]);

test('every backstop resume call writes one hardening log line without a user', function (?array $body, string $outcome) {
    // The schedule entry runs in the background, so console output is discarded:
    // this line is the only trace of a backstop resume that did not close the row.
    Log::spy();
    Http::fake(['*' => $body === null ? Http::failedConnection() : Http::response($body, 200)]);
    $pause = expiredHardeningPause(5);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    Log::shouldHaveReceived('info')
        ->withArgs(fn ($message, $context = []) => $message === 'hardening' && $context === [
            'user_id' => null,
            'project_id' => $pause->project_id,
            'action' => 'resume',
            'rule' => 'block_archives',
            'outcome' => $outcome,
        ])
        ->once();
})->with([
    'resumed' => [hardeningPluginBody(true, null, hardeningPluginStatus('on')), 'ok'],
    'refused by the site' => [hardeningPluginBody(false, 'asset_broken', hardeningPluginStatus('paused', 1790000000, true)), 'asset_broken'],
    'unreachable' => [null, 'unreachable'],
]);

test('an outdated plugin or a rejected key marks the row failed, notifies once and is never retried', function (int $http, string $note) {
    Notification::fake();
    fakeBackstopResume(['code' => 'rest_error'], $http);
    $admin = hardeningUser('admin');
    $pause = expiredHardeningPause(5);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);
    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    expect($pause->fresh()->failed_at)->not->toBeNull();
    expect($pause->fresh()->resumed_at)->toBeNull();
    expect($pause->fresh()->note)->toBe($note);
    expect($pause->fresh()->overdue_notified_at)->not->toBeNull();
    Http::assertSentCount(1);
    Notification::assertSentToTimes($admin, HardeningPauseOverdueNotification::class, 1);
})->with([
    'old plugin' => [404, 'Gave up: plugin_outdated'],
    'key rejected 401' => [401, 'Gave up: unauthorized'],
    'key rejected 403' => [403, 'Gave up: unauthorized'],
]);

test('a row older than 24 hours that still is not on is marked failed', function () {
    Notification::fake();
    Http::fake(['*' => Http::failedConnection()]);
    $pause = expiredHardeningPause(5, [
        'paused_until' => now()->subHours(24),
        'created_at' => now()->subHours(25),
        'overdue_notified_at' => now()->subHours(23),
    ]);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    expect($pause->fresh()->failed_at)->not->toBeNull();
    expect($pause->fresh()->note)->toBe('Gave up: not resumed within 24 h');
    Notification::assertNothingSent(); // already notified 23 h ago
});

test('a row younger than 24 hours is not given up on', function () {
    Http::fake(['*' => Http::failedConnection()]);
    $pause = expiredHardeningPause(5, [
        'paused_until' => now()->subHours(22),
        'created_at' => now()->subHours(23),
        'overdue_notified_at' => now()->subHours(21),
    ]);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    expect($pause->fresh()->failed_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// The one overdue notification
// ---------------------------------------------------------------------------

test('more than 30 minutes overdue sends exactly one notification to the pausing user and every admin', function () {
    Notification::fake();
    Http::fake(['*' => Http::failedConnection()]);
    $admin = hardeningUser('admin');
    $flagHolder = hardeningUser('manager', ['is_admin' => true]);
    $bystander = hardeningUser('manager');
    $pause = expiredHardeningPause(31);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);
    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    Notification::assertSentToTimes($pause->user, HardeningPauseOverdueNotification::class, 1);
    Notification::assertSentToTimes($admin, HardeningPauseOverdueNotification::class, 1);
    Notification::assertSentToTimes($flagHolder, HardeningPauseOverdueNotification::class, 1);
    Notification::assertNotSentTo($bystander, HardeningPauseOverdueNotification::class);
    expect($pause->fresh()->overdue_notified_at)->not->toBeNull();
    expect($pause->fresh()->resumed_at)->toBeNull();
});

test('30 minutes overdue or less sends nothing yet', function () {
    Notification::fake();
    Http::fake(['*' => Http::failedConnection()]);
    hardeningUser('admin');
    $pause = expiredHardeningPause(29);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    Notification::assertNothingSent();
    expect($pause->fresh()->overdue_notified_at)->toBeNull();
});

test('an admin who paused is notified once, not twice', function () {
    Notification::fake();
    Http::fake(['*' => Http::failedConnection()]);
    $admin = hardeningUser('admin');
    expiredHardeningPause(45, ['user_id' => $admin->id]);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    Notification::assertSentToTimes($admin, HardeningPauseOverdueNotification::class, 1);
});

test('a row whose pausing user is gone still notifies the admins', function () {
    Notification::fake();
    Http::fake(['*' => Http::failedConnection()]);
    $admin = hardeningUser('admin');
    expiredHardeningPause(45, ['user_id' => null]);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    Notification::assertSentToTimes($admin, HardeningPauseOverdueNotification::class, 1);
});

test('a row that gets closed in this run is not reported as overdue', function () {
    Notification::fake();
    fakeBackstopResume(hardeningPluginBody(true, null, hardeningPluginStatus('on')));
    hardeningUser('admin');
    $pause = expiredHardeningPause(45);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    expect($pause->fresh()->resumed_at)->not->toBeNull();
    Notification::assertNothingSent();
});

test('a failing mail transport still means one stored notification per recipient, not one per run', function () {
    // No Notification::fake() here on purpose: the real channels must run.
    // Nothing listens on port 1: the mail channel throws, as a broken SMTP does in production (queue = sync).
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);
    Http::fake(['*' => Http::failedConnection()]);
    $admin = hardeningUser('admin');
    $pause = expiredHardeningPause(45, [], ['notification_preferences' => ['email_alerts_enabled' => true]]);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);
    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    expect($admin->notifications()->count())->toBe(1);
    expect($pause->user->notifications()->count())->toBe(1);
    expect($pause->fresh()->overdue_notified_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Projects that can no longer be called
// ---------------------------------------------------------------------------

test('a trashed project closes the row with a note and nothing is sent', function () {
    Notification::fake();
    Http::fake();
    $pause = expiredHardeningPause(45);
    $pause->project->delete();

    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    expect($pause->fresh()->resumed_at)->not->toBeNull();
    expect($pause->fresh()->note)->toBe('Closed without resume: project deleted or LSM not configured');
    Http::assertNothingSent();
    Notification::assertNothingSent();
});

test('a project that lost its LSM key closes the row with a note and nothing is sent', function () {
    Http::fake();
    $pause = expiredHardeningPause(5);
    $pause->project->update(['health_check_secret' => null]);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    expect($pause->fresh()->resumed_at)->not->toBeNull();
    expect($pause->fresh()->note)->toBe('Closed without resume: project deleted or LSM not configured');
    Http::assertNothingSent();
});

test('a row that blows up does not stop the rows after it', function () {
    Http::fake([
        'https://broken.example.com/*' => fn () => throw new \Error('boom'),
        '*' => Http::response(hardeningPluginBody(true, null, hardeningPluginStatus('on')), 200),
    ]);
    $broken = expiredHardeningPause(5, [], ['url' => 'https://broken.example.com']);
    $healthy = expiredHardeningPause(5);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    expect($broken->fresh()->resumed_at)->toBeNull();
    expect($healthy->fresh()->resumed_at)->not->toBeNull();
});
