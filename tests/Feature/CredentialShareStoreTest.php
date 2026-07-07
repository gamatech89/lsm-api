<?php

use App\Models\Credential;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;

function makeShareableCredential(): array
{
    $admin = User::factory()->create(['role' => 'admin']);
    $project = Project::factory()->create();
    $credential = Credential::create([
        'project_id' => $project->id,
        'title' => 'Prod DB',
        'type' => 'database',
        'password' => Crypt::encryptString('s3cret'),
    ]);

    return [$admin, $credential];
}

test('a share link now requires an access password of at least 8 characters', function () {
    [$admin, $credential] = makeShareableCredential();

    // No password → rejected
    $this->actingAs($admin)->postJson("/api/v1/credentials/{$credential->id}/share", [
        'expires_in_minutes' => 60,
        'max_views' => 1,
    ])->assertStatus(422);

    // Too-short password → rejected
    $this->actingAs($admin)->postJson("/api/v1/credentials/{$credential->id}/share", [
        'expires_in_minutes' => 60,
        'max_views' => 1,
        'access_password' => 'short',
    ])->assertStatus(422);

    // Valid password → accepted
    $this->actingAs($admin)->postJson("/api/v1/credentials/{$credential->id}/share", [
        'expires_in_minutes' => 60,
        'max_views' => 1,
        'access_password' => 'longenough8',
    ])->assertOk();
});

test('a share link cannot be valid for more than 24 hours', function () {
    [$admin, $credential] = makeShareableCredential();

    // 25 hours → rejected
    $this->actingAs($admin)->postJson("/api/v1/credentials/{$credential->id}/share", [
        'expires_in_minutes' => 1500,
        'max_views' => 1,
        'access_password' => 'longenough8',
    ])->assertStatus(422);

    // Exactly 24 hours → accepted
    $this->actingAs($admin)->postJson("/api/v1/credentials/{$credential->id}/share", [
        'expires_in_minutes' => 1440,
        'max_views' => 1,
        'access_password' => 'longenough8',
    ])->assertOk();
});
