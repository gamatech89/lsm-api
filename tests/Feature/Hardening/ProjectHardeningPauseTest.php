<?php

use App\Models\Project;
use App\Models\ProjectHardeningPause;
use App\Models\User;
use Illuminate\Support\Carbon;

test('the factory creates an open pause row with datetime casts and both relations', function () {
    $pause = ProjectHardeningPause::factory()->create();

    expect($pause->paused_until)->toBeInstanceOf(Carbon::class);
    expect($pause->resumed_at)->toBeNull();
    expect($pause->failed_at)->toBeNull();
    expect($pause->overdue_notified_at)->toBeNull();
    expect($pause->note)->toBeNull();
    expect($pause->project)->toBeInstanceOf(Project::class);
    expect($pause->user)->toBeInstanceOf(User::class);
});

test('the open scope leaves out resumed rows and failed rows', function () {
    $open = ProjectHardeningPause::factory()->create();
    ProjectHardeningPause::factory()->create(['resumed_at' => now()]);
    ProjectHardeningPause::factory()->create(['failed_at' => now()]);

    expect(ProjectHardeningPause::open()->pluck('id')->all())->toBe([$open->id]);
});

test('nullable columns round-trip through mass assignment', function () {
    $pause = ProjectHardeningPause::factory()->create();

    $pause->update([
        'resumed_at' => now(),
        'failed_at' => now(),
        'overdue_notified_at' => now(),
        'note' => 'closed by test',
    ]);

    $fresh = $pause->fresh();
    expect($fresh->resumed_at)->toBeInstanceOf(Carbon::class);
    expect($fresh->failed_at)->toBeInstanceOf(Carbon::class);
    expect($fresh->overdue_notified_at)->toBeInstanceOf(Carbon::class);
    expect($fresh->note)->toBe('closed by test');
});

test('force-deleting the project removes its pause rows', function () {
    $pause = ProjectHardeningPause::factory()->create();

    $pause->project->forceDelete();

    expect(ProjectHardeningPause::count())->toBe(0);
});

test('force-deleting the pausing user keeps the row and nulls user_id', function () {
    $pause = ProjectHardeningPause::factory()->create();

    $pause->user->forceDelete();

    expect($pause->fresh())->not->toBeNull();
    expect($pause->fresh()->user_id)->toBeNull();
});

test('a soft-deleted project leaves the row in place with a null project relation', function () {
    $pause = ProjectHardeningPause::factory()->create();

    $pause->project->delete();

    expect($pause->fresh())->not->toBeNull();
    expect($pause->fresh()->project)->toBeNull();
});
