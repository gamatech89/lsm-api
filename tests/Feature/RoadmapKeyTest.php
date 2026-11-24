<?php

use App\Models\User;

/**
 * GET /api/v1/roadmap/key hands the password of the encrypted CEO roadmap page to
 * logged-in admins (role or is_admin flag) and managers, so the page can open for them
 * without typing it. Developers, viewers and guests don't get it, and the response must
 * never be cached.
 */
const ROADMAP_TEST_PASSWORD = 'test-roadmap-password-123';

beforeEach(fn () => config(['services.roadmap.password' => ROADMAP_TEST_PASSWORD]));

function roadmapKeyAs(?User $user)
{
    $request = $user ? test()->actingAs($user) : test();

    return $request->getJson('/api/v1/roadmap/key');
}

test('admins and managers get the roadmap password, never cached', function (array $attributes) {
    $response = roadmapKeyAs(User::factory()->create($attributes));

    $response->assertOk()->assertExactJson(['password' => ROADMAP_TEST_PASSWORD]);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
})->with([
    'admin role' => [['role' => 'admin']],
    'is_admin flag on a developer' => [['role' => 'developer', 'is_admin' => true]],
    'manager' => [['role' => 'manager']],
]);

test('developers and viewers are refused', function (string $role) {
    roadmapKeyAs(User::factory()->create(['role' => $role, 'is_admin' => false]))
        ->assertForbidden()
        ->assertJsonMissing(['password' => ROADMAP_TEST_PASSWORD]);
})->with(['developer', 'viewer']);

test('guests must log in', function () {
    roadmapKeyAs(null)->assertUnauthorized();
});

test('answers 404 when no roadmap password is configured', function () {
    config(['services.roadmap.password' => null]);

    roadmapKeyAs(User::factory()->create(['role' => 'admin']))->assertNotFound();
});
