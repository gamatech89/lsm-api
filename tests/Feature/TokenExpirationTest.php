<?php

use App\Models\User;

test('sanctum tokens are configured with an 8-hour expiry by default', function () {
    expect(config('sanctum.expiration'))->toBe(480);
});

test('a fresh sanctum token authenticates', function () {
    $user = User::factory()->create();
    $fresh = $user->createToken('fresh');

    $this->withToken($fresh->plainTextToken)->getJson('/api/v1/user')->assertOk();
});

test('a sanctum token older than its lifetime is rejected', function () {
    // Backdate created_at past the configured lifetime rather than travelling
    // the clock. One request per test so the RequestGuard doesn't cache a user.
    $user = User::factory()->create();
    $stale = $user->createToken('stale');
    $stale->accessToken->forceFill([
        'created_at' => now()->subMinutes(config('sanctum.expiration') + 1),
    ])->save();

    $this->withToken($stale->plainTextToken)->getJson('/api/v1/user')->assertStatus(401);
});

test('refresh-token issues a working replacement before expiry', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $res = $this->withToken($token)->postJson('/api/v1/refresh-token');
    $res->assertOk();

    $newToken = $res->json('data.token');
    expect($newToken)->toBeString();
    $this->withToken($newToken)->getJson('/api/v1/user')->assertOk();
});
