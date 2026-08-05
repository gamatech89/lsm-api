<?php

use App\Models\User;

// Item 1 of the final review round: auth:sanctum only checks that a token is
// valid, not what abilities it carries, and Sanctum's abilities middleware is
// applied on zero of this API's 260 routes. Without RejectIntegrationTokens,
// a token minted with scopes as narrow as ['mcp:read'] reaches every REST
// endpoint the user's role can reach. These tests pin that the gate exists,
// that it does not collaterally block /mcp (the transport integration tokens
// exist for), and that ordinary session tokens are unaffected.

test('an integration token gets 403 on a REST route', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $token = $user->createToken('mcp-client', ['mcp:read'], now()->addYear());
    $token->accessToken->forceFill(['type' => 'integration'])->save();

    $this->withToken($token->plainTextToken)->getJson('/api/v1/user')
        ->assertStatus(403)
        ->assertJsonPath('code', 'integration_token_not_permitted')
        ->assertJsonPath('success', false);
});

test('the same integration token still works against /mcp', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $token = $user->createToken('mcp-client', ['mcp:read'], now()->addYear());
    $token->accessToken->forceFill(['type' => 'integration'])->save();

    // Proves the gate is scoped to the REST API (routes/api.php) and does
    // not reach /mcp, which is registered separately in routes/mcp.php with
    // its own auth:sanctum and carries no RejectIntegrationTokens middleware.
    $this->withToken($token->plainTextToken)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => ['per_page' => 50],
    ])->assertOk();
});

test('a session token still reaches the same REST route normally', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $token = $user->createToken('web-browser', ['*'], now()->addMinutes(480))->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/user')->assertOk();
});
