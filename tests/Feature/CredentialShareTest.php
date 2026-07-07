<?php

use App\Models\Credential;
use App\Models\CredentialShareLink;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;

function makeShareLink(array $overrides = []): CredentialShareLink
{
    $manager = User::factory()->create(['role' => 'manager']);
    $project = Project::factory()->create(['manager_id' => $manager->id]);
    $credential = Credential::create([
        'project_id' => $project->id,
        'title' => 'Prod DB',
        'type' => 'database',
        'username' => 'root',
        'password' => Crypt::encryptString('s3cret'),
    ]);

    return $credential->shareLinks()->create(array_merge([
        'created_by' => $manager->id,
        'token' => \Illuminate\Support\Str::random(32),
        'expires_at' => now()->addHour(),
        'max_views' => 5,
        'view_count' => 0,
        'show_username' => true,
        'show_password' => true,
        'show_url' => true,
    ], $overrides));
}

test('a password-protected share link is accessible with the correct password', function () {
    $link = makeShareLink(['access_password' => 'letmein']);

    $response = $this->postJson("/api/v1/share/{$link->token}/access", [
        'password' => 'letmein',
    ]);

    $response->assertOk();
    $response->assertJsonPath('success', true);
    $response->assertJsonStructure(['data', 'share_info']);
});

test('a password-protected share link rejects the wrong password', function () {
    $link = makeShareLink(['access_password' => 'letmein']);

    $response = $this->postJson("/api/v1/share/{$link->token}/access", [
        'password' => 'wrong',
    ]);

    $response->assertStatus(403);
    $response->assertJsonPath('success', false);
});

test('an un-protected share link is accessible without a password', function () {
    $link = makeShareLink(['access_password' => null]);

    $response = $this->postJson("/api/v1/share/{$link->token}/access", []);

    $response->assertOk();
    $response->assertJsonPath('success', true);
});
