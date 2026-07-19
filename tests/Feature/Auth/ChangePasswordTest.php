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
