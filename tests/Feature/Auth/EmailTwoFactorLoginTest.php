<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;

test('an email-only 2FA user can complete login with the emailed code', function () {
    // Email 2FA enabled, but NO authenticator app (two_factor_confirmed_at is null).
    $user = User::factory()->create([
        'password' => bcrypt('password123'),
        'two_factor_email_enabled' => true,
        'two_factor_confirmed_at' => null,
        'two_factor_secret' => null,
    ]);

    // Step 1: login issues an email 2FA challenge and caches the code.
    $login = $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'password123',
    ]);
    $login->assertOk();
    $login->assertJsonPath('data.two_factor_required', true);
    $login->assertJsonPath('data.method', 'email');

    $token = $login->json('data.two_factor_token');
    $code = Cache::get("2fa_email_code:{$token}");
    expect($code)->toBeString();

    // Step 2: submitting the emailed code must complete login (not 401 back to start).
    $verify = $this->postJson('/api/v1/two-factor/verify', [
        'two_factor_token' => $token,
        'code' => $code,
    ]);

    $verify->assertOk();
    $verify->assertJsonStructure(['data' => ['user', 'token']]);
});

test('a wrong email code is rejected with 422, not a session error', function () {
    $user = User::factory()->create([
        'password' => bcrypt('password123'),
        'two_factor_email_enabled' => true,
        'two_factor_confirmed_at' => null,
    ]);

    $login = $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'password123',
    ]);
    $token = $login->json('data.two_factor_token');

    $this->postJson('/api/v1/two-factor/verify', [
        'two_factor_token' => $token,
        'code' => '000000',
    ])->assertStatus(422);
});
