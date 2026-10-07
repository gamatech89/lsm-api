<?php

use App\Models\User;
use App\Notifications\WelcomeNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => config(['auth.mfa_enforced_roles' => ['admin', 'manager', 'developer', 'viewer']]));

test('a new team member gets email 2FA enabled and is not asked to enroll', function () {
    Notification::fake();
    $admin = User::factory()->create(['role' => 'admin', 'two_factor_email_enabled' => true]);

    $this->actingAs($admin)->postJson('/api/v1/team', [
        'name' => 'Anna Hahn',
        'email' => 'anna@example.com',
        'password' => 'Str0ng!Passw0rd',
        'role' => 'manager',
    ])->assertCreated();

    $user = User::where('email', 'anna@example.com')->firstOrFail();
    expect($user->two_factor_email_enabled)->toBeTrue()
        ->and($user->mustEnrollTwoFactor())->toBeFalse();

    Notification::assertSentTo($user, WelcomeNotification::class);
});

test('the welcome email is German, role-aware and contains the credentials', function () {
    config(['app.frontend_url' => 'https://wartung-ls.com']);
    $user = User::factory()->make(['name' => 'Anna Hahn', 'email' => 'anna@example.com', 'role' => 'manager']);

    $html = (string) (new WelcomeNotification('tok', 'Xy7#kP2m!Qa9'))->toMail($user)->render();

    expect($html)
        ->toContain('Willkommen im Team')
        ->toContain('Anna!')
        ->toContain('Projektmanager/in')
        ->toContain('Support-Tickets')
        ->toContain('Xy7#kP2m!Qa9')
        ->toContain('https://wartung-ls.com/login')
        ->toContain('reset-password?token=tok');
});

test('the welcome email hides manager-only features from viewers', function () {
    $user = User::factory()->make(['name' => 'Vera Viewer', 'role' => 'viewer']);

    $html = (string) (new WelcomeNotification('tok', 'pw'))->toMail($user)->render();

    expect($html)->toContain('Lesezugriff')->not->toContain('Support-Tickets');
});
