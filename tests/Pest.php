<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

pest()->extend(Tests\TestCase::class)
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Attach a real personal access token to a user so tokenCan() works.
 *
 * Do not use Sanctum::actingAs() for scope tests: it builds a Mockery mock that
 * only stubs can() for the abilities you list, so asserting that some *other*
 * ability is denied raises a Mockery error instead of returning false.
 */
function actingWithScopes(\App\Models\User $user, array $scopes): \App\Models\User
{
    $token = $user->createToken('test-token', $scopes, now()->addMinutes(60));

    return $user->withAccessToken($token->accessToken);
}

/**
 * A project the LSM plugin counts as "configured" (url + health_check_secret),
 * for the .htaccess hardening tests. ProjectFactory sets no secret on its own.
 */
function hardeningProject(array $attrs = []): \App\Models\Project
{
    return \App\Models\Project::factory()->create(array_merge([
        'url' => 'https://client.example.com',
        'health_check_secret' => 'SECRETKEY123',
    ], $attrs));
}

/**
 * A user for the hardening permission tests. two_factor_confirmed_at keeps
 * EnsureTwoFactorEnrolled out of the way even when a developer's shell exports
 * MFA_ENFORCED_ROLES (phpunit.xml does not force that variable).
 */
function hardeningUser(string $role, array $attrs = []): \App\Models\User
{
    return \App\Models\User::factory()->create(array_merge([
        'role' => $role,
        'two_factor_confirmed_at' => now(),
    ], $attrs));
}

/**
 * The plugin's `status` object (spec 2026-09-21, REST endpoints) with a
 * chosen block_archives state.
 */
function hardeningPluginStatus(string $archivesState = 'on', ?int $pauseUntil = null, bool $pauseOverdue = false): array
{
    $rule = fn (string $state, bool $desired) => [
        'state' => $state,
        'desired' => $desired,
        'unsupported_reason' => null,
        'last_failure' => null,
    ];

    return [
        'plugin_version' => '2.10.0',
        'server' => 'Apache',
        'rules' => [
            'block_archives' => $rule($archivesState, true),
            'block_debug_log' => $rule('off', false),
            'block_uploads_php' => $rule('off', false),
        ],
        'pause_until' => $pauseUntil,
        'pause_overdue' => $pauseOverdue,
        'archive_attachments' => 0,
        'last_result' => null,
    ];
}

/**
 * A full plugin hardening response body: top level, no `data` wrapper.
 */
function hardeningPluginBody(bool $success = true, ?string $reason = null, ?array $status = null, array $warnings = [], string $message = 'Applied and verified'): array
{
    return [
        'success' => $success,
        'reason' => $reason,
        'message' => $message,
        'warnings' => $warnings,
        'status' => $status ?? hardeningPluginStatus(),
    ];
}
