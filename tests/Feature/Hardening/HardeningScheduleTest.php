<?php

use Illuminate\Console\Scheduling\Schedule;

test('hardening:resume-expired runs every ten minutes with a 15-minute mutex, in the background, on one server', function () {
    // routes/console.php registers tasks at boot; re-evaluate it against a fresh Schedule.
    $schedule = new Schedule;
    \Illuminate\Support\Facades\Schedule::swap($schedule);
    require base_path('routes/console.php');

    $event = collect($schedule->events())->first(fn ($e) => $e->description === 'hardening-resume-expired');

    expect($event)->not->toBeNull();
    expect($event->command)->toContain('hardening:resume-expired');
    expect($event->expression)->toBe('*/10 * * * *');
    expect($event->withoutOverlapping)->toBeTrue();
    // Not the 1440-minute default: a stuck default mutex blocked sites:check-uptime for three days in August.
    expect($event->expiresAt)->toBe(15);
    expect($event->runInBackground)->toBeTrue();
    expect($event->onOneServer)->toBeTrue();
});
