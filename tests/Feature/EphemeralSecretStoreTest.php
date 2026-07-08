<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

test('any role can create an ephemeral secret and gets a link', function () {
    foreach (['admin', 'manager', 'developer'] as $role) {
        $user = User::factory()->create(['role' => $role]);
        $response = $this->actingAs($user)->postJson('/api/v1/ephemeral-secrets', [
            'title' => 'Staging FTP',
            'username' => 'deploy',
            'password' => 'p@ss;word',
            'expires_in_minutes' => 60,
        ]);
        $response->assertStatus(201);
        expect($response->json('data.link'))->toContain('/s/');
    }
});

test('the payload is stored encrypted, not in plaintext', function () {
    $user = User::factory()->create(['role' => 'developer']);
    $this->actingAs($user)->postJson('/api/v1/ephemeral-secrets', [
        'password' => 'topsecret123',
        'expires_in_minutes' => 60,
    ])->assertStatus(201);

    $raw = DB::table('ephemeral_secrets')->latest('id')->value('payload');
    expect($raw)->not->toContain('topsecret123');
});

test('a secret with no fields is rejected', function () {
    $user = User::factory()->create(['role' => 'developer']);
    $this->actingAs($user)->postJson('/api/v1/ephemeral-secrets', [
        'title' => 'Empty',
        'expires_in_minutes' => 60,
    ])->assertStatus(422);
});

test('creating requires authentication', function () {
    $this->postJson('/api/v1/ephemeral-secrets', ['password' => 'x', 'expires_in_minutes' => 60])
        ->assertStatus(401);
});
