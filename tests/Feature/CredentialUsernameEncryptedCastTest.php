<?php

use App\Models\Credential;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('a credential with a long email-style username round-trips through the encrypted cast', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $project = Project::factory()->create();

    // Long enough that its ciphertext overflows a VARCHAR(255) column —
    // the exact failure seen in production (SQLSTATE[22001]).
    $username = 'info@icb-beschaffungslogistik-beispiel.de';

    $this->actingAs($admin)
        ->postJson("/api/v1/projects/{$project->id}/credentials", [
            'title' => 'Hosting Login',
            'type' => 'hosting',
            'username' => $username,
            'password' => 's3cret',
        ])
        ->assertStatus(201);

    $credential = Credential::where('project_id', $project->id)->firstOrFail();

    // The encrypted cast must decrypt back to the exact plaintext...
    expect($credential->username)->toBe($username);

    // ...while the raw stored value is ciphertext, not the plaintext.
    $raw = DB::table('credentials')->where('id', $credential->id)->value('username');
    expect($raw)->not->toBeNull();
    expect($raw)->not->toBe($username);
});
