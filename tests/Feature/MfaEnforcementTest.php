<?php

use App\Models\User;

// Enforcement is off by default in the test env (see phpunit.xml); turn it on
// for these tests. Individual tests can override the enforced set.
beforeEach(fn () => config(['auth.mfa_enforced_roles' => ['admin']]));

test('an admin without 2FA is blocked from normal endpoints until enrolled', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'two_factor_confirmed_at' => null,
        'two_factor_email_enabled' => false,
    ]);

    $this->actingAs($admin)->getJson('/api/v1/projects')
        ->assertStatus(403)
        ->assertJsonPath('code', 'two_factor_setup_required');
});

test('an un-enrolled admin can still reach 2FA setup, user, and logout', function () {
    $admin = User::factory()->create(['role' => 'admin', 'two_factor_confirmed_at' => null]);

    $this->actingAs($admin)->getJson('/api/v1/user')->assertOk();
    $this->actingAs($admin)->postJson('/api/v1/two-factor/enable')->assertOk();
});

test('an admin with confirmed 2FA is not blocked', function () {
    $admin = User::factory()->create(['role' => 'admin', 'two_factor_confirmed_at' => now()]);

    $this->actingAs($admin)->getJson('/api/v1/projects')->assertOk();
});

test('an admin who uses email 2FA counts as enrolled', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'two_factor_confirmed_at' => null,
        'two_factor_email_enabled' => true,
    ]);

    $this->actingAs($admin)->getJson('/api/v1/projects')->assertOk();
});

test('a developer is not enforced by default (admins first)', function () {
    $dev = User::factory()->create(['role' => 'developer', 'two_factor_confirmed_at' => null]);

    $this->actingAs($dev)->getJson('/api/v1/projects')->assertOk();
});

test('the user response advertises whether 2FA setup is required', function () {
    $admin = User::factory()->create(['role' => 'admin', 'two_factor_confirmed_at' => null]);

    $this->actingAs($admin)->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('data.requires_two_factor_setup', true);
});

test('enforcement extends to all roles when configured', function () {
    config(['auth.mfa_enforced_roles' => ['admin', 'manager', 'developer']]);

    $dev = User::factory()->create(['role' => 'developer', 'two_factor_confirmed_at' => null]);

    $this->actingAs($dev)->getJson('/api/v1/projects')->assertStatus(403);
});

test('an is_admin flag holder is enforced like an admin regardless of role', function () {
    // Daniel-case: role=manager but is_admin=true grants full admin power via
    // User::isAdmin()/ProjectPolicy::before(), so "admins must have 2FA" has
    // to cover the flag too, not just role === 'admin'.
    $flagAdmin = User::factory()->create([
        'role' => 'manager',
        'is_admin' => true,
        'two_factor_confirmed_at' => null,
        'two_factor_email_enabled' => false,
    ]);

    $this->actingAs($flagAdmin)->getJson('/api/v1/projects')
        ->assertStatus(403)
        ->assertJsonPath('code', 'two_factor_setup_required');
});

test('an enrolled is_admin flag holder is not blocked', function () {
    $flagAdmin = User::factory()->create([
        'role' => 'developer',
        'is_admin' => true,
        'two_factor_confirmed_at' => now(),
    ]);

    $this->actingAs($flagAdmin)->getJson('/api/v1/projects')->assertOk();
});

test('an is_admin flag holder is not enforced when admin is not an enforced role', function () {
    config(['auth.mfa_enforced_roles' => []]);
    $flagAdmin = User::factory()->create([
        'role' => 'manager',
        'is_admin' => true,
        'two_factor_confirmed_at' => null,
    ]);

    $this->actingAs($flagAdmin)->getJson('/api/v1/projects')->assertOk();
});
