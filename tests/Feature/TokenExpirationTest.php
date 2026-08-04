<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

test('the global sanctum cap is off so per-token expiry governs', function () {
    // Guard::isValidAccessToken ANDs the global cap with expires_at, so any
    // non-null global expiration would silently cap long-lived tokens.
    expect(config('sanctum.expiration'))->toBeNull();
    expect(config('sanctum.session_expiration'))->toBe(480);
});

test('a fresh sanctum token authenticates', function () {
    $user = User::factory()->create();
    $fresh = $user->createToken('fresh', ['*'], now()->addMinutes(480));

    $this->withToken($fresh->plainTextToken)->getJson('/api/v1/user')->assertOk();
});

test('a login token still works at 7 hours 59 minutes', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password123'),
        'two_factor_confirmed_at' => null,
        'two_factor_email_enabled' => false,
    ]);

    $token = $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'password123',
        'device_name' => 'test',
    ])->assertOk()->json('data.token');

    $this->travel(479)->minutes();

    $this->withToken($token)->getJson('/api/v1/user')->assertOk();
});

test('a login token is rejected at 8 hours and 1 minute', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password123'),
        'two_factor_confirmed_at' => null,
        'two_factor_email_enabled' => false,
    ]);

    $token = $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'password123',
        'device_name' => 'test',
    ])->assertOk()->json('data.token');

    $this->travel(481)->minutes();

    $this->withToken($token)->getJson('/api/v1/user')->assertStatus(401);
});

test('a 2FA-verify token still works at 7 hours 59 minutes', function () {
    // TwoFactorController::verify() is the mint site for every enrolled human
    // user's session (routes/api.php puts EnsureTwoFactorEnrolled on the whole
    // protected group), so it needs the same boundary coverage as login.
    $user = User::factory()->create([
        'password' => Hash::make('password123'),
        'two_factor_email_enabled' => true,
        'two_factor_confirmed_at' => null,
        'two_factor_secret' => null,
    ]);

    $pendingToken = $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'password123',
    ])->assertOk()->json('data.two_factor_token');

    $code = Cache::get("2fa_email_code:{$pendingToken}");

    $token = $this->postJson('/api/v1/two-factor/verify', [
        'two_factor_token' => $pendingToken,
        'code' => $code,
    ])->assertOk()->json('data.token');

    $this->travel(479)->minutes();

    $this->withToken($token)->getJson('/api/v1/user')->assertOk();
});

test('a 2FA-verify token is rejected at 8 hours and 1 minute', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password123'),
        'two_factor_email_enabled' => true,
        'two_factor_confirmed_at' => null,
        'two_factor_secret' => null,
    ]);

    $pendingToken = $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'password123',
    ])->assertOk()->json('data.two_factor_token');

    $code = Cache::get("2fa_email_code:{$pendingToken}");

    $token = $this->postJson('/api/v1/two-factor/verify', [
        'two_factor_token' => $pendingToken,
        'code' => $code,
    ])->assertOk()->json('data.token');

    $this->travel(481)->minutes();

    $this->withToken($token)->getJson('/api/v1/user')->assertStatus(401);
});

test('refresh-token issues a replacement that also expires in 8 hours', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test', ['*'], now()->addMinutes(480))->plainTextToken;

    $newToken = $this->withToken($token)
        ->postJson('/api/v1/refresh-token')
        ->assertOk()
        ->json('data.token');

    expect($newToken)->toBeString();

    // The Sanctum RequestGuard caches the resolved user for the lifetime of the
    // guard instance (see tests/Feature/Auth/ChangePasswordTest.php), so forget
    // guards between requests here to force each one to re-resolve its user
    // from the token actually presented.
    $this->app['auth']->forgetGuards();
    $this->withToken($newToken)->getJson('/api/v1/user')->assertOk();

    $this->travel(481)->minutes();
    $this->app['auth']->forgetGuards();
    $this->withToken($newToken)->getJson('/api/v1/user')->assertStatus(401);
});

test('a long-lived integration token survives well past 8 hours', function () {
    $user = User::factory()->create();
    $token = $user->createToken('integration', ['mcp:read'], now()->addYear())->plainTextToken;

    $this->travel(24)->hours();

    $this->withToken($token)->getJson('/api/v1/user')->assertOk();
});

test('the backfill migration bounds legacy tokens that have no expiry', function () {
    $user = User::factory()->create();
    $legacy = $user->createToken('legacy', ['*']);

    // Simulate a pre-migration row: issued three days ago, no expires_at.
    $legacy->accessToken->forceFill([
        'expires_at' => null,
        'created_at' => now()->subDays(3),
    ])->save();

    // A token with no expiry and no global cap would live forever.
    $this->withToken($legacy->plainTextToken)->getJson('/api/v1/user')->assertOk();

    $migration = require database_path('migrations/2026_08_04_120000_backfill_session_token_expiry.php');
    $migration->up();

    expect($legacy->accessToken->fresh()->expires_at)->not->toBeNull();

    // See the note above: force the guard to re-resolve against the migrated row.
    $this->app['auth']->forgetGuards();
    $this->withToken($legacy->plainTextToken)->getJson('/api/v1/user')->assertStatus(401);
});

test('refresh-token refuses an integration token', function () {
    $user = User::factory()->create();
    $integration = $user->createToken('mcp-client', ['mcp:read'], now()->addYear());
    $integration->accessToken->forceFill(['type' => 'integration'])->save();

    $this->withToken($integration->plainTextToken)
        ->postJson('/api/v1/refresh-token')
        ->assertStatus(403);

    $this->app['auth']->forgetGuards();

    // The integration token must survive the refusal. refresh() deletes the
    // token it is handed, so a refusal that ran too late would revoke the very
    // credential an MCP client depends on.
    expect($integration->accessToken->fresh())->not->toBeNull();
    $this->withToken($integration->plainTextToken)->getJson('/api/v1/user')->assertOk();
});

test('refresh-token carries the presented abilities into the replacement', function () {
    $user = User::factory()->create();
    $session = $user->createToken('web-browser', ['*'], now()->addMinutes(480));

    $newToken = $this->withToken($session->plainTextToken)
        ->postJson('/api/v1/refresh-token')
        ->assertOk()
        ->json('data.token');

    $this->app['auth']->forgetGuards();

    $replacement = \Laravel\Sanctum\PersonalAccessToken::findToken($newToken);
    expect($replacement->abilities)->toBe(['*']);
    expect($replacement->type)->toBe('session');
});
