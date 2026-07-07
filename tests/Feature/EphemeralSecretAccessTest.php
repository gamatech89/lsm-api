<?php

use App\Models\EphemeralSecret;

test('access reveals the payload once, then burns it', function () {
    $secret = EphemeralSecret::factory()->create([
        'title' => 'Staging FTP',
        'payload' => ['username' => 'deploy', 'password' => 'p@ss;word'],
    ]);

    $first = $this->postJson("/api/v1/s/{$secret->token}/access");
    $first->assertOk();
    $first->assertJsonPath('data.username', 'deploy');
    $first->assertJsonPath('data.password', 'p@ss;word');
    $first->assertJsonPath('revealed_once', true);

    // Burned: second attempt is gone, and the payload is wiped at rest.
    $this->postJson("/api/v1/s/{$secret->token}/access")->assertStatus(404)->assertJsonPath('reason', 'viewed');
    expect($secret->fresh()->payload)->toBeNull();
    expect($secret->fresh()->viewed_at)->not->toBeNull();
});

test('a password-protected secret needs the correct password and does not burn on failure', function () {
    $secret = EphemeralSecret::factory()->create([
        'access_password' => 'letmein',
        'payload' => ['password' => 'the-secret'],
    ]);

    $this->postJson("/api/v1/s/{$secret->token}/access", ['password' => 'wrong'])->assertStatus(403);
    expect($secret->fresh()->isAvailable())->toBeTrue(); // not burned

    $ok = $this->postJson("/api/v1/s/{$secret->token}/access", ['password' => 'letmein']);
    $ok->assertOk()->assertJsonPath('data.password', 'the-secret');
});

test('access on an expired secret is unavailable', function () {
    $secret = EphemeralSecret::factory()->create(['expires_at' => now()->subMinute()]);
    $this->postJson("/api/v1/s/{$secret->token}/access")->assertStatus(404)->assertJsonPath('reason', 'expired');
});
