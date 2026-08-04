<?php

use App\Models\User;
use Illuminate\Support\Facades\Schema;

test('personal access tokens carry a type and IP audit columns', function () {
    expect(Schema::hasColumns('personal_access_tokens', [
        'type', 'created_from_ip', 'last_used_ip',
    ]))->toBeTrue();

    $user = User::factory()->create();
    $token = $user->createToken('default', ['*'], now()->addMinutes(480));

    expect($token->accessToken->fresh()->type)->toBe('session');
});

test('authenticating with a token records the calling IP once', function () {
    $user = User::factory()->create();
    $token = $user->createToken('integration', ['*'], now()->addYear());
    $token->accessToken->forceFill(['type' => 'integration'])->save();

    $this->withToken($token->plainTextToken)
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->getJson('/api/v1/user')
        ->assertOk();

    expect($token->accessToken->fresh()->last_used_ip)->toBe('203.0.113.7');
});

test('the user model exposes only integration tokens through the relation', function () {
    $user = User::factory()->create();
    $user->createToken('session-one', ['*'], now()->addMinutes(480));

    $integration = $user->createToken('integration-one', ['mcp:read'], now()->addYear());
    $integration->accessToken->forceFill(['type' => 'integration'])->save();

    expect($user->integrationTokens()->pluck('name')->all())->toBe(['integration-one']);
});
