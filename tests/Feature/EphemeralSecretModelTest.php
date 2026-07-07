<?php

use App\Models\EphemeralSecret;
use Illuminate\Support\Facades\DB;

test('payload is encrypted at rest and readable as an array', function () {
    $secret = EphemeralSecret::create([
        'token' => 'tok_'.uniqid(),
        'title' => 'Staging FTP',
        'payload' => ['username' => 'deploy', 'password' => 'p@ss;word'],
        'expires_at' => now()->addHour(),
    ]);

    $raw = DB::table('ephemeral_secrets')->where('id', $secret->id)->value('payload');
    expect($raw)->not->toContain('deploy');            // encrypted
    expect($secret->fresh()->payload)->toBe(['username' => 'deploy', 'password' => 'p@ss;word']);
});

test('availability helpers reflect expiry and burn state', function () {
    $live = EphemeralSecret::factory()->create(['expires_at' => now()->addHour(), 'viewed_at' => null]);
    expect($live->isAvailable())->toBeTrue();

    $expired = EphemeralSecret::factory()->create(['expires_at' => now()->subMinute()]);
    expect($expired->isAvailable())->toBeFalse();
    expect($expired->isExpired())->toBeTrue();

    $burned = EphemeralSecret::factory()->create(['viewed_at' => now()]);
    expect($burned->isAvailable())->toBeFalse();
    expect($burned->isBurned())->toBeTrue();
});
