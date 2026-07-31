<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function rebackfillMigration(): object
{
    return require database_path('migrations/2026_07_31_090100_rebackfill_legacy_manager_developer_pivots.php');
}

function seedRebackfillFixtures(): array
{
    $managerWithPivot = User::factory()->create(['role' => 'manager']);
    $managerLegacyOnly = User::factory()->create(['role' => 'manager']);
    $developerLegacyOnly = User::factory()->create(['role' => 'developer']);

    // 1) manager_id set AND matching pivot row already present
    $projectSynced = Project::factory()->create(['manager_id' => $managerWithPivot->id]);
    $projectSynced->managers()->attach($managerWithPivot->id);

    // 2) manager_id set, NO pivot row (the drift the migration repairs)
    $projectDrifted = Project::factory()->create(['manager_id' => $managerLegacyOnly->id]);

    // 3) developer_id set, NO pivot row
    $projectDevDrifted = Project::factory()->create(['developer_id' => $developerLegacyOnly->id]);

    return [$projectSynced, $projectDrifted, $projectDevDrifted, $managerWithPivot, $managerLegacyOnly, $developerLegacyOnly];
}

test('the re-backfill migration adds exactly the missing pivot rows', function () {
    [$projectSynced, $projectDrifted, $projectDevDrifted, $managerWithPivot, $managerLegacyOnly, $developerLegacyOnly] = seedRebackfillFixtures();

    expect(DB::table('project_manager')->count())->toBe(1);
    expect(DB::table('project_developer')->count())->toBe(0);

    rebackfillMigration()->up();

    // Exactly one manager row added (the drifted project), nothing duplicated
    expect(DB::table('project_manager')->count())->toBe(2);
    expect(DB::table('project_manager')
        ->where('project_id', $projectSynced->id)
        ->where('user_id', $managerWithPivot->id)
        ->count())->toBe(1);
    expect(DB::table('project_manager')
        ->where('project_id', $projectDrifted->id)
        ->where('user_id', $managerLegacyOnly->id)
        ->count())->toBe(1);

    // Exactly one developer row added
    expect(DB::table('project_developer')->count())->toBe(1);
    expect(DB::table('project_developer')
        ->where('project_id', $projectDevDrifted->id)
        ->where('user_id', $developerLegacyOnly->id)
        ->count())->toBe(1);
});

test('the re-backfill migration is idempotent when run twice', function () {
    seedRebackfillFixtures();

    $migration = rebackfillMigration();
    $migration->up();
    $migration->up();

    expect(DB::table('project_manager')->count())->toBe(2);
    expect(DB::table('project_developer')->count())->toBe(1);
});
