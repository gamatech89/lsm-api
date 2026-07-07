<?php

use App\Models\EphemeralSecret;

test('show returns metadata only, never the secret', function () {
    $secret = EphemeralSecret::factory()->create([
        'title' => 'Staging FTP',
        'payload' => ['password' => 'do-not-leak'],
    ]);

    $response = $this->getJson("/api/v1/s/{$secret->token}");

    $response->assertOk();
    $response->assertJsonPath('available', true);
    $response->assertJsonPath('title', 'Staging FTP');
    expect($response->getContent())->not->toContain('do-not-leak');
    expect($response->json('password'))->toBeNull();
});

test('show reports has_password when a gate is set', function () {
    $secret = EphemeralSecret::factory()->create(['access_password' => 'letmein']);
    $this->getJson("/api/v1/s/{$secret->token}")->assertJsonPath('has_password', true);
});

test('show returns unavailable for expired, burned, and missing secrets', function () {
    $expired = EphemeralSecret::factory()->create(['expires_at' => now()->subMinute()]);
    $this->getJson("/api/v1/s/{$expired->token}")->assertStatus(404)->assertJsonPath('reason', 'expired');

    $burned = EphemeralSecret::factory()->create(['viewed_at' => now()]);
    $this->getJson("/api/v1/s/{$burned->token}")->assertStatus(404)->assertJsonPath('reason', 'viewed');

    $this->getJson('/api/v1/s/nope')->assertStatus(404)->assertJsonPath('reason', 'not_found');
});
