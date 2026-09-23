<?php

// Every assertion goes through the Gate ($user->can()), never through
// `new ProjectPolicy`: admins are granted by ProjectPolicy::before(), which a
// directly instantiated policy never runs.

test('an admin by role may pause, enable and disable', function () {
    $admin = hardeningUser('admin');
    $project = hardeningProject();

    expect($admin->can('pauseHardening', $project))->toBeTrue();
    expect($admin->can('enableHardening', $project))->toBeTrue();
    expect($admin->can('disableHardening', $project))->toBeTrue();
});

test('a manager holding the is_admin flag may pause, enable and disable without being assigned', function () {
    $flagHolder = hardeningUser('manager', ['is_admin' => true]);
    $project = hardeningProject();

    expect($flagHolder->can('pauseHardening', $project))->toBeTrue();
    expect($flagHolder->can('enableHardening', $project))->toBeTrue();
    expect($flagHolder->can('disableHardening', $project))->toBeTrue();
});

test('a manager who manages the project via the legacy column may pause and enable but not disable', function () {
    $manager = hardeningUser('manager');
    $project = hardeningProject(['manager_id' => $manager->id]);

    expect($manager->can('pauseHardening', $project))->toBeTrue();
    expect($manager->can('enableHardening', $project))->toBeTrue();
    expect($manager->can('disableHardening', $project))->toBeFalse();
});

test('a manager who manages the project via the pivot may pause and enable but not disable', function () {
    $manager = hardeningUser('manager');
    $project = hardeningProject();
    $project->managers()->attach($manager->id);

    expect($manager->can('pauseHardening', $project))->toBeTrue();
    expect($manager->can('enableHardening', $project))->toBeTrue();
    expect($manager->can('disableHardening', $project))->toBeFalse();
});

test('an assigned developer may pause but neither enable nor disable', function () {
    $developer = hardeningUser('developer');
    $project = hardeningProject();
    $project->developers()->attach($developer->id);

    expect($developer->can('pauseHardening', $project))->toBeTrue();
    expect($developer->can('enableHardening', $project))->toBeFalse();
    expect($developer->can('disableHardening', $project))->toBeFalse();
});

test('an unassigned manager can view the project but has none of the three abilities', function () {
    $manager = hardeningUser('manager');
    $project = hardeningProject();

    // MANAGERS_VIEW_ALL_PROJECTS is pinned to true in phpunit.xml.
    expect($manager->can('view', $project))->toBeTrue();
    expect($manager->can('pauseHardening', $project))->toBeFalse();
    expect($manager->can('enableHardening', $project))->toBeFalse();
    expect($manager->can('disableHardening', $project))->toBeFalse();
});

test('an unassigned developer has none of the three abilities', function () {
    $developer = hardeningUser('developer');
    $project = hardeningProject();

    expect($developer->can('pauseHardening', $project))->toBeFalse();
    expect($developer->can('enableHardening', $project))->toBeFalse();
    expect($developer->can('disableHardening', $project))->toBeFalse();
});

test('a viewer has none of the three abilities and cannot even view', function () {
    $viewer = hardeningUser('viewer');
    $project = hardeningProject();

    expect($viewer->can('view', $project))->toBeFalse();
    expect($viewer->can('pauseHardening', $project))->toBeFalse();
    expect($viewer->can('enableHardening', $project))->toBeFalse();
    expect($viewer->can('disableHardening', $project))->toBeFalse();
});
