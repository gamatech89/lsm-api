<?php
// tests/Feature/Auth/ChangePasswordTest.php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('changes the password when the current password is correct', function () {
    $user = User::factory()->create(['password' => Hash::make('old-password-123')]);

    $response = $this->actingAs($user)->putJson('/api/v1/user/password', [
        'current_password' => 'old-password-123',
        'password' => 'new-password-456',
        'password_confirmation' => 'new-password-456',
    ]);

    $response->assertOk()->assertJson(['success' => true]);
    expect(Hash::check('new-password-456', $user->fresh()->password))->toBeTrue();
});

it('rejects a wrong current password and leaves the password unchanged', function () {
    $user = User::factory()->create(['password' => Hash::make('old-password-123')]);

    $response = $this->actingAs($user)->putJson('/api/v1/user/password', [
        'current_password' => 'wrong-password',
        'password' => 'new-password-456',
        'password_confirmation' => 'new-password-456',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('current_password');
    expect(Hash::check('old-password-123', $user->fresh()->password))->toBeTrue();
});

it('rejects a confirmation mismatch', function () {
    $user = User::factory()->create(['password' => Hash::make('old-password-123')]);

    $response = $this->actingAs($user)->putJson('/api/v1/user/password', [
        'current_password' => 'old-password-123',
        'password' => 'new-password-456',
        'password_confirmation' => 'different-456',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('password');
});

it('requires authentication', function () {
    $this->putJson('/api/v1/user/password', [
        'current_password' => 'old-password-123',
        'password' => 'new-password-456',
        'password_confirmation' => 'new-password-456',
    ])->assertUnauthorized();
});

it('revokes other tokens but keeps the current session token valid after a password change', function () {
    $user = User::factory()->create(['password' => Hash::make('old-password-123')]);

    // A token issued before the change (e.g. a stolen session) should stop working.
    $priorToken = $user->createToken('prior-device')->plainTextToken;

    // The token used to make the change itself should keep working afterwards.
    $currentToken = $user->createToken('current-device')->plainTextToken;

    $response = $this->withToken($currentToken)->putJson('/api/v1/user/password', [
        'current_password' => 'old-password-123',
        'password' => 'new-password-456',
        'password_confirmation' => 'new-password-456',
    ]);

    $response->assertOk()->assertJson(['success' => true]);

    // The Sanctum RequestGuard caches the resolved user for the lifetime of the
    // guard instance (see TokenExpirationTest.php), so forget guards between
    // requests here to force each one to re-resolve its user from the token.
    $this->app['auth']->forgetGuards();
    $this->withToken($priorToken)->getJson('/api/v1/user')->assertUnauthorized();

    $this->app['auth']->forgetGuards();
    $this->withToken($currentToken)->getJson('/api/v1/user')->assertOk();
});
