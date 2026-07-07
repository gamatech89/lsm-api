<?php

use App\Models\EphemeralSecret;

test('purge deletes old expired/viewed secrets and keeps live and recent ones', function () {
    $live = EphemeralSecret::factory()->create(['expires_at' => now()->addHour()]);
    $recentlyViewed = EphemeralSecret::factory()->create(['viewed_at' => now()->subDay()]);
    $oldExpired = EphemeralSecret::factory()->create(['expires_at' => now()->subDays(8)]);
    $oldViewed = EphemeralSecret::factory()->create(['viewed_at' => now()->subDays(8)]);

    $this->artisan('ephemeral-secrets:purge')->assertExitCode(0);

    expect(EphemeralSecret::find($live->id))->not->toBeNull();
    expect(EphemeralSecret::find($recentlyViewed->id))->not->toBeNull();
    expect(EphemeralSecret::find($oldExpired->id))->toBeNull();
    expect(EphemeralSecret::find($oldViewed->id))->toBeNull();
});
