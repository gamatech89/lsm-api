<?php

use App\Models\ProjectHardeningPause;
use App\Notifications\HardeningPauseOverdueNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

function overdueHardeningPause(array $projectAttrs = []): ProjectHardeningPause
{
    return ProjectHardeningPause::factory()->create([
        'project_id' => hardeningProject(array_merge(['name' => 'Praxis Dr. Jung'], $projectAttrs))->id,
        'paused_until' => now()->subMinutes(45),
        'note' => null,
    ]);
}

test('it is queueable like the other alert notifications', function () {
    $pause = overdueHardeningPause();

    expect(new HardeningPauseOverdueNotification($pause->project, $pause))->toBeInstanceOf(ShouldQueue::class);
});

test('it always stores a database notification and mails only when the project has email alerts on', function () {
    $quiet = overdueHardeningPause(['notification_preferences' => ['email_alerts_enabled' => false]]);
    $loud = overdueHardeningPause(['notification_preferences' => ['email_alerts_enabled' => true]]);
    $user = hardeningUser('admin');

    expect((new HardeningPauseOverdueNotification($quiet->project, $quiet))->via($user))->toBe(['database']);
    expect((new HardeningPauseOverdueNotification($loud->project, $loud))->via($user))->toBe(['database', 'mail']);
});

test('the database payload identifies the project, the pause and who paused', function () {
    $pause = overdueHardeningPause();

    $data = (new HardeningPauseOverdueNotification($pause->project, $pause))->toArray(hardeningUser('admin'));

    expect($data)->toBe([
        'type' => 'hardening_pause_overdue',
        'project_id' => $pause->project_id,
        'project_name' => 'Praxis Dr. Jung',
        'project_url' => 'https://client.example.com',
        'pause_id' => $pause->id,
        'paused_by' => $pause->user_id,
        'paused_until' => $pause->paused_until->toISOString(),
        'message' => '⚠️ Praxis Dr. Jung — the archive rule pause is overdue, backups may be publicly downloadable',
        'severity' => 'critical',
    ]);
});

test('the mail names the project, the deadline and links to the security section', function () {
    config(['app.frontend_url' => 'https://wartung.example']);
    $pause = overdueHardeningPause();

    $mail = (new HardeningPauseOverdueNotification($pause->project, $pause))->toMail(hardeningUser('admin'));

    expect($mail->subject)->toBe('⚠️ Hardening pause overdue: Praxis Dr. Jung');
    expect($mail->actionUrl)->toBe("https://wartung.example/projects/{$pause->project_id}?section=security");
    expect(implode("\n", $mail->introLines))
        ->toContain('https://client.example.com')
        ->toContain($pause->paused_until->format('Y-m-d H:i') . ' UTC');
});
