<?php
// tests/Feature/Scanner/ScannerCollectorTest.php
use App\Models\Project;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function authedProject(): array {
    $key = 'test-secret-key-123';
    $project = Project::factory()->create([
        'health_check_secret' => $key,
        'health_check_secret_hash' => hash('sha256', $key),
        'url' => 'https://client.example',
    ]);
    return [$project, ['X-LSM-Key' => $key]];
}

it('rejects a session request without an API key', function () {
    $this->postJson('/api/v1/scanner/session', ['scan_id' => 1, 'scan_type' => 'full', 'wp_version' => '6.5'])
        ->assertStatus(401);
});

it('starts a session and returns a token plus spam keywords', function () {
    [$project, $headers] = authedProject();
    $this->postJson('/api/v1/scanner/session', [
        'scan_id' => 55, 'scan_type' => 'full', 'wp_version' => '6.5',
    ], $headers)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['token', 'spam_keywords', 'config' => ['max_file_size', 'batch_bytes']]);
});

it('returns needed paths from a manifest diff', function () {
    Storage::fake('local');
    Http::fake(['api.wordpress.org/*' => Http::response(['checksums' => ['wp-load.php' => 'core-hash']], 200)]);
    [$project, $headers] = authedProject();
    $token = $this->postJson('/api/v1/scanner/session', ['scan_id' => 1, 'scan_type' => 'full', 'wp_version' => '6.5'], $headers)
        ->json('token');

    $this->postJson('/api/v1/scanner/manifest', [
        'token' => $token, 'wp_version' => '6.5',
        'manifest' => [
            ['path' => 'wp-load.php', 'md5' => 'core-hash', 'size' => 10],
            ['path' => 'wp-content/uploads/x.php', 'md5' => 'evil', 'size' => 20],
        ],
    ], $headers)
        ->assertOk()
        ->assertJsonPath('needed_paths', ['wp-content/uploads/x.php']);
});
