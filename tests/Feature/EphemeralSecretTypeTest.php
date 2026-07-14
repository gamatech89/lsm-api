<?php

use App\Models\User;

test('an ephemeral secret carries type and connection fields through to reveal', function () {
    $user = User::factory()->create();

    $create = $this->actingAs($user)->postJson('/api/v1/ephemeral-secrets', [
        'type' => 'sftp',
        'title' => 'Staging SFTP',
        'username' => 'deploy',
        'password' => 'sekret',
        'hostname' => 'sftp.example.com',
        'port' => '22',
        'expires_in_minutes' => 60,
    ]);

    $create->assertCreated();
    $token = last(explode('/s/', $create->json('data.link')));

    $reveal = $this->postJson("/api/v1/s/{$token}/access");
    $reveal->assertOk();
    $reveal->assertJsonPath('data.type', 'sftp');
    $reveal->assertJsonPath('data.hostname', 'sftp.example.com');
    $reveal->assertJsonPath('data.port', '22');
    $reveal->assertJsonPath('data.username', 'deploy');
});

test('a type with no actual secret value is rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/ephemeral-secrets', [
        'type' => 'ftp',
        'expires_in_minutes' => 60,
    ])->assertStatus(422);
});
