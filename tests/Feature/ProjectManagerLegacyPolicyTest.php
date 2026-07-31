<?php

use App\Models\Credential;
use App\Models\Project;
use App\Models\Resource;
use App\Models\TimeEntry;
use App\Models\Todo;
use App\Models\User;
use App\Policies\CredentialPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\ResourcePolicy;
use App\Policies\TimeEntryPolicy;
use App\Policies\TodoPolicy;

/**
 * Policy-level pin for the isManagedBy() sweep: a manager assigned ONLY via
 * the legacy projects.manager_id column (no project_manager pivot row) must
 * pass every manager-membership branch, and an unrelated manager must fail
 * every one. Policies are instantiated directly so before() (admin bypass)
 * is not involved.
 */

function legacyOnlySetup(): array
{
    $manager = User::factory()->create(['role' => 'manager']);
    $stranger = User::factory()->create(['role' => 'manager']);
    // Legacy column only — deliberately NO pivot row
    $project = Project::factory()->create(['manager_id' => $manager->id]);

    return [$manager, $stranger, $project];
}

test('ProjectPolicy manager branches accept a legacy-only manager and reject an unrelated manager', function () {
    [$manager, $stranger, $project] = legacyOnlySetup();
    $policy = new ProjectPolicy();

    expect($policy->update($manager, $project))->toBeTrue();
    expect($policy->delete($manager, $project))->toBeTrue();
    expect($policy->restore($manager, $project))->toBeTrue();
    expect($policy->manageCredentials($manager, $project))->toBeTrue();
    expect($policy->assignTeam($manager, $project))->toBeTrue();

    expect($policy->update($stranger, $project))->toBeFalse();
    expect($policy->delete($stranger, $project))->toBeFalse();
    expect($policy->restore($stranger, $project))->toBeFalse();
    expect($policy->manageCredentials($stranger, $project))->toBeFalse();
    expect($policy->assignTeam($stranger, $project))->toBeFalse();
});

test('CredentialPolicy manager branches accept a legacy-only manager and reject an unrelated manager', function () {
    [$manager, $stranger, $project] = legacyOnlySetup();
    $credential = Credential::factory()->create(['project_id' => $project->id]);
    $policy = new CredentialPolicy();

    expect($policy->view($manager, $credential))->toBeTrue();
    expect($policy->update($manager, $credential))->toBeTrue();
    expect($policy->delete($manager, $credential))->toBeTrue();
    expect($policy->share($manager, $credential))->toBeTrue();
    expect($policy->manageAccess($manager, $credential))->toBeTrue();

    expect($policy->view($stranger, $credential))->toBeFalse();
    expect($policy->update($stranger, $credential))->toBeFalse();
    expect($policy->delete($stranger, $credential))->toBeFalse();
    expect($policy->share($stranger, $credential))->toBeFalse();
    expect($policy->manageAccess($stranger, $credential))->toBeFalse();
});

test('TodoPolicy manager branches accept a legacy-only manager and reject an unrelated manager', function () {
    [$manager, $stranger, $project] = legacyOnlySetup();
    $todo = Todo::factory()->create(['project_id' => $project->id, 'status' => 'pending']);
    $policy = new TodoPolicy();

    expect($policy->create($manager, $project))->toBeTrue();
    expect($policy->update($manager, $todo))->toBeTrue();
    expect($policy->delete($manager, $todo))->toBeTrue();

    expect($policy->create($stranger, $project))->toBeFalse();
    expect($policy->update($stranger, $todo))->toBeFalse();
    expect($policy->delete($stranger, $todo))->toBeFalse();
});

test('ResourcePolicy manager branches accept a legacy-only manager and reject an unrelated manager', function () {
    [$manager, $stranger, $project] = legacyOnlySetup();
    $resource = new Resource(['project_id' => $project->id, 'title' => 'Doc', 'type' => 'link']);
    $resource->setRelation('project', $project);
    $policy = new ResourcePolicy();

    expect($policy->create($manager, $project))->toBeTrue();
    expect($policy->update($manager, $resource))->toBeTrue();
    expect($policy->delete($manager, $resource))->toBeTrue();

    expect($policy->create($stranger, $project))->toBeFalse();
    expect($policy->update($stranger, $resource))->toBeFalse();
    expect($policy->delete($stranger, $resource))->toBeFalse();
});

test('TimeEntryPolicy manager branches accept a legacy-only manager and reject an unrelated manager', function () {
    [$manager, $stranger, $project] = legacyOnlySetup();
    $owner = User::factory()->create(['role' => 'developer']);
    $timeEntry = new TimeEntry([
        'user_id' => $owner->id,
        'project_id' => $project->id,
        'status' => TimeEntry::STATUS_DRAFT,
    ]);
    $timeEntry->setRelation('project', $project);
    $policy = new TimeEntryPolicy();

    expect($policy->view($manager, $timeEntry))->toBeTrue();
    expect($policy->update($manager, $timeEntry))->toBeTrue();
    expect($policy->approve($manager, $timeEntry))->toBeTrue();

    expect($policy->view($stranger, $timeEntry))->toBeFalse();
    expect($policy->update($stranger, $timeEntry))->toBeFalse();
    expect($policy->approve($stranger, $timeEntry))->toBeFalse();
});
