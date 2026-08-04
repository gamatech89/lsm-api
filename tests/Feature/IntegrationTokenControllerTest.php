<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

test('personal access tokens carry a type and IP audit columns', function () {
    expect(Schema::hasColumns('personal_access_tokens', [
        'type', 'created_from_ip', 'last_used_ip',
    ]))->toBeTrue();

    $user = User::factory()->create();
    $token = $user->createToken('default', ['*'], now()->addMinutes(480));

    expect($token->accessToken->fresh()->type)->toBe('session');
});

test('authenticating with a token records the calling IP', function () {
    $user = User::factory()->create();
    $token = $user->createToken('integration', ['*'], now()->addYear());
    $token->accessToken->forceFill(['type' => 'integration'])->save();

    $this->withToken($token->plainTextToken)
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->getJson('/api/v1/user')
        ->assertOk();

    expect($token->accessToken->fresh()->last_used_ip)->toBe('203.0.113.7');
});

test('the user model exposes only integration tokens through the relation, newest first', function () {
    $user = User::factory()->create();
    $user->createToken('session-one', ['*'], now()->addMinutes(480));

    $older = $user->createToken('integration-older', ['mcp:read'], now()->addYear());
    $older->accessToken->forceFill(['type' => 'integration', 'created_at' => now()->subDay()])->save();

    $newer = $user->createToken('integration-newer', ['mcp:read'], now()->addYear());
    $newer->accessToken->forceFill(['type' => 'integration'])->save();

    expect($user->integrationTokens()->pluck('name')->all())->toBe([
        'integration-newer', 'integration-older',
    ]);
});

test('a user can mint an integration token with the right password', function () {
    $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('secret-pw')]);

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/integration-tokens', [
        'name' => 'Claude Code — MacBook',
        'scopes' => ['mcp:read', 'mcp:write'],
        'expires_in' => '90d',
        'password' => 'secret-pw',
    ])->assertCreated();

    expect($response->json('data.token'))->toBeString();
    expect($response->json('data.integration_token.scopes'))->toBe(['mcp:read', 'mcp:write']);

    $row = $user->integrationTokens()->first();
    expect($row->name)->toBe('Claude Code — MacBook');
    expect($row->abilities)->toBe(['mcp:read', 'mcp:write']);
    // A window, not an exact diff: Carbon 3's diffInDays is signed and returns
    // a float, so an equality assertion here would be quietly fragile.
    expect($row->expires_at->isBetween(now()->addDays(89), now()->addDays(91)))->toBeTrue();
});

test('the minted token actually authenticates and outlives a session', function () {
    $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('secret-pw')]);

    $token = $this->actingAs($user, 'sanctum')->postJson('/api/v1/integration-tokens', [
        'name' => 'Long lived',
        'scopes' => ['mcp:read'],
        'expires_in' => '1y',
        'password' => 'secret-pw',
    ])->assertCreated()->json('data.token');

    $this->travel(30)->days();

    // The Sanctum RequestGuard caches the resolved user for the lifetime of the
    // guard instance (see tests/Feature/TokenExpirationTest.php). actingAs()
    // above already resolved and cached $user on the guard, so without this the
    // assertion below never actually consults the bearer token being tested.
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/user')->assertOk();
});

test('a wrong password mints nothing', function () {
    $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('secret-pw')]);

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/integration-tokens', [
        'name' => 'Nope',
        'scopes' => ['mcp:read'],
        'expires_in' => '90d',
        'password' => 'wrong-pw',
    ])->assertStatus(422)->assertJsonValidationErrors('password');

    expect($user->integrationTokens()->count())->toBe(0);
});

test('a developer cannot mint a destructive scope', function () {
    $user = User::factory()->create(['role' => 'developer', 'password' => Hash::make('secret-pw')]);

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/integration-tokens', [
        'name' => 'Too much',
        'scopes' => ['mcp:read', 'mcp:wp-destructive'],
        'expires_in' => '90d',
        'password' => 'secret-pw',
    ])->assertStatus(422)->assertJsonValidationErrors('scopes');

    expect($user->integrationTokens()->count())->toBe(0);
});

test('a viewer can only mint a read scope', function () {
    $user = User::factory()->create(['role' => 'viewer', 'password' => Hash::make('secret-pw')]);

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/integration-tokens', [
        'name' => 'Viewer write',
        'scopes' => ['mcp:write'],
        'expires_in' => '90d',
        'password' => 'secret-pw',
    ])->assertStatus(422)->assertJsonValidationErrors('scopes');

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/integration-tokens', [
        'name' => 'Viewer read',
        'scopes' => ['mcp:read'],
        'expires_in' => '90d',
        'password' => 'secret-pw',
    ])->assertCreated();
});

test('an unrecognised role falls back to read only, not to developer', function () {
    // The DB enum keeps 'auditor' out of the users table, which is correct.
    // Override the attribute in memory only: actingAs puts THIS instance on the
    // guard, so the FormRequest sees a role ROLE_SCOPES has never heard of
    // without the row on disk ever holding an invalid value.
    $user = User::factory()->create([
        'role' => 'developer',
        'password' => Hash::make('secret-pw'),
    ]);

    $user->role = 'auditor';

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/integration-tokens', [
        'name' => 'Unknown role write',
        'scopes' => ['mcp:write'],
        'expires_in' => '90d',
        'password' => 'secret-pw',
    ])->assertStatus(422)->assertJsonValidationErrors('scopes');
});

test('the store endpoint records the minting IP', function () {
    $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('secret-pw')]);

    $this->actingAs($user, 'sanctum')
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.4'])
        ->postJson('/api/v1/integration-tokens', [
            'name' => 'Origin tracked',
            'scopes' => ['mcp:read'],
            'expires_in' => '90d',
            'password' => 'secret-pw',
        ])->assertCreated();

    expect($user->integrationTokens()->first()->created_from_ip)->toBe('198.51.100.4');
});

test('a manager may mint a destructive scope', function () {
    $user = User::factory()->create(['role' => 'manager', 'password' => Hash::make('secret-pw')]);

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/integration-tokens', [
        'name' => 'Manager token',
        'scopes' => ['mcp:wp-destructive'],
        'expires_in' => '30d',
        'password' => 'secret-pw',
    ])->assertCreated();

    expect($user->integrationTokens()->first()->abilities)->toBe(['mcp:wp-destructive']);
});

test('an unknown scope is rejected', function () {
    $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('secret-pw')]);

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/integration-tokens', [
        'name' => 'Bad scope',
        'scopes' => ['mcp:read', 'mcp:everything'],
        'expires_in' => '90d',
        'password' => 'secret-pw',
    ])->assertStatus(422)->assertJsonValidationErrors('scopes.1');

    expect($user->integrationTokens()->count())->toBe(0);
});

test('a wrong password and a forbidden scope both report their own error', function () {
    // Structurally, the after() hook keeps validating both password and
    // scopes rather than bailing on the first failure. Pin that: an early
    // return added later would drop one of these keys without a red test.
    $user = User::factory()->create(['role' => 'developer', 'password' => Hash::make('secret-pw')]);

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/integration-tokens', [
        'name' => 'Both wrong',
        'scopes' => ['mcp:wp-destructive'],
        'expires_in' => '90d',
        'password' => 'wrong-pw',
    ])->assertStatus(422)->assertJsonValidationErrors(['password', 'scopes']);

    expect($user->integrationTokens()->count())->toBe(0);
});

test('the index never returns a token value and never lists session tokens', function () {
    $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('secret-pw')]);
    $user->createToken('a-session-token', ['*'], now()->addMinutes(config('sanctum.session_expiration')));

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/integration-tokens', [
        'name' => 'Visible one',
        'scopes' => ['mcp:read'],
        'expires_in' => '90d',
        'password' => 'secret-pw',
    ])->assertCreated();

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/integration-tokens')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.name'))->toBe('Visible one');
    expect($response->json('data.0'))->not->toHaveKey('token');
    expect($response->json('data.0'))->not->toHaveKey('plain_text_token');
});

test('one user cannot see or revoke another user\'s tokens', function () {
    $owner = User::factory()->create(['role' => 'admin', 'password' => Hash::make('secret-pw')]);
    $stranger = User::factory()->create(['role' => 'admin']);

    $id = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/integration-tokens', [
        'name' => 'Private',
        'scopes' => ['mcp:read'],
        'expires_in' => '90d',
        'password' => 'secret-pw',
    ])->assertCreated()->json('data.integration_token.id');

    $this->actingAs($stranger, 'sanctum')
        ->getJson('/api/v1/integration-tokens')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs($stranger, 'sanctum')
        ->deleteJson("/api/v1/integration-tokens/{$id}")
        ->assertStatus(404);

    expect($owner->integrationTokens()->count())->toBe(1);
});

test('revoking an integration token leaves the session token working', function () {
    $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('secret-pw')]);
    $session = $user->createToken('session', ['*'], now()->addMinutes(config('sanctum.session_expiration')))->plainTextToken;

    $created = $this->withToken($session)->postJson('/api/v1/integration-tokens', [
        'name' => 'Disposable',
        'scopes' => ['mcp:read'],
        'expires_in' => '30d',
        'password' => 'secret-pw',
    ])->assertCreated();

    $integrationToken = $created->json('data.token');
    $id = $created->json('data.integration_token.id');

    $this->withToken($session)
        ->deleteJson("/api/v1/integration-tokens/{$id}")
        ->assertOk();

    // The Sanctum RequestGuard caches the resolved user for the lifetime of the
    // guard instance (see tests/Feature/TokenExpirationTest.php), so forget
    // guards between requests here to force each one to re-resolve its user
    // from the token actually presented.
    $this->app['auth']->forgetGuards();
    $this->withToken($integrationToken)->getJson('/api/v1/user')->assertStatus(401);

    $this->app['auth']->forgetGuards();
    $this->withToken($session)->getJson('/api/v1/user')->assertOk();
});

test('a never-expiring token is stored with a null expiry', function () {
    $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('secret-pw')]);

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/integration-tokens', [
        'name' => 'Forever',
        'scopes' => ['mcp:read'],
        'expires_in' => 'never',
        'password' => 'secret-pw',
    ])->assertCreated();

    expect($user->integrationTokens()->first()->expires_at)->toBeNull();
});

test('a non-string password is rejected with 422, not a 500', function () {
    // Laravel runs after() callbacks even when the base 'string' rule has
    // already failed, so an array here reaches Hash::check() unless guarded.
    $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('secret-pw')]);

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/integration-tokens', [
        'name' => 'Malformed password',
        'scopes' => ['mcp:read'],
        'expires_in' => '90d',
        'password' => ['nope'],
    ])->assertStatus(422)->assertJsonValidationErrors('password');

    expect($user->integrationTokens()->count())->toBe(0);
});

test('an unauthenticated request cannot mint a token', function () {
    $this->postJson('/api/v1/integration-tokens', [
        'name' => 'No session',
        'scopes' => ['mcp:read'],
        'expires_in' => '90d',
        'password' => 'secret-pw',
    ])->assertStatus(401);
});

test('an un-enrolled user subject to the MFA gate cannot reach this endpoint', function () {
    config(['auth.mfa_enforced_roles' => ['admin']]);

    $user = User::factory()->create([
        'role' => 'admin',
        'password' => Hash::make('secret-pw'),
        'two_factor_confirmed_at' => null,
        'two_factor_email_enabled' => false,
    ]);

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/integration-tokens', [
        'name' => 'Blocked by MFA gate',
        'scopes' => ['mcp:read'],
        'expires_in' => '90d',
        'password' => 'secret-pw',
    ])->assertStatus(403)->assertJsonPath('code', 'two_factor_setup_required');
});

test('the sixth mint attempt within a minute is throttled', function () {
    $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('secret-pw')]);

    $payload = fn (int $i) => [
        'name' => "Attempt {$i}",
        'scopes' => ['mcp:read'],
        'expires_in' => '90d',
        'password' => 'secret-pw',
    ];

    for ($i = 1; $i <= 5; $i++) {
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/integration-tokens', $payload($i))
            ->assertCreated();
    }

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/integration-tokens', $payload(6))
        ->assertStatus(429);
});

test('a session token\'s id 404s on delete rather than logging the caller out', function () {
    // integrationTokens() filters on type = 'integration'; this pins that the
    // delete route cannot reach a session-typed row even by numeric id, so a
    // future refactor to $request->user()->tokens()->find($id) would be
    // caught here rather than letting a user log themselves out through this
    // endpoint with an otherwise-green suite.
    $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('secret-pw')]);
    $session = $user->createToken('session', ['*'], now()->addMinutes(config('sanctum.session_expiration')));

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/v1/integration-tokens/{$session->accessToken->id}")
        ->assertStatus(404);

    expect($session->accessToken->fresh())->not->toBeNull();
});
