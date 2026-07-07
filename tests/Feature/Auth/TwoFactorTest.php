<?php

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

test('a 2fa-enabled user can complete login via the verify endpoint', function () {
    $google2fa = new Google2FA();
    $secret = $google2fa->generateSecretKey();

    $user = User::factory()->create([
        'password' => bcrypt('password123'),
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
    ]);

    // Step 1: login returns a 2FA challenge, not a token
    $login = $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'password123',
    ]);

    $login->assertOk();
    $login->assertJsonPath('data.two_factor_required', true);
    $pendingToken = $login->json('data.two_factor_token');
    expect($pendingToken)->toBeString();

    // Step 2: submit a valid TOTP code to complete login
    $code = $google2fa->getCurrentOtp($secret);

    $verify = $this->postJson('/api/v1/two-factor/verify', [
        'two_factor_token' => $pendingToken,
        'code' => $code,
    ]);

    $verify->assertOk();
    $verify->assertJsonStructure(['data' => ['user', 'token']]);
});

test('an authenticated user can enable and confirm 2fa', function () {
    $user = User::factory()->create(['two_factor_confirmed_at' => null, 'two_factor_secret' => null]);

    $enable = $this->actingAs($user)->postJson('/api/v1/two-factor/enable');
    $enable->assertOk();
    $enable->assertJsonStructure(['data' => ['secret', 'qr_code_url']]);

    $secret = $enable->json('data.secret');
    $code = (new Google2FA())->getCurrentOtp($secret);

    $confirm = $this->actingAs($user)->postJson('/api/v1/two-factor/confirm', ['code' => $code]);
    $confirm->assertOk();
    $confirm->assertJsonStructure(['data' => ['recovery_codes']]);

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();
});
