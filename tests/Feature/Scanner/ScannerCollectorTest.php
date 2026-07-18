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

it('scans uploaded file contents and finalizes with the frozen results shape', function () {
    Http::fake(['api.wordpress.org/*' => Http::response(['checksums' => []], 200)]);
    [$project, $headers] = authedProject();
    $token = $this->postJson('/api/v1/scanner/session', ['scan_id' => 9, 'scan_type' => 'full', 'wp_version' => '6.5'], $headers)->json('token');

    $payload = '<?php ' . 'eval' . '(base64' . '_decode($_GET[0]));';
    $this->postJson('/api/v1/scanner/files', [
        'token' => $token,
        'files' => [['path' => 'wp-content/uploads/x.php', 'content_b64' => base64_encode($payload), 'ext' => 'php']],
    ], $headers)->assertOk()->assertJsonPath('scanned', 1);

    $res = $this->postJson('/api/v1/scanner/finalize', [
        'token' => $token,
        'home_host' => 'client.example',
        'htaccess_files' => [],
        'database' => ['admins' => [['id' => 3, 'login' => 'x', 'email' => '', 'registered' => '2026-01-01 00:00:00']]],
        'suspicious_files' => [],
        'permissions' => [],
    ], $headers);

    $res->assertOk()
        ->assertJsonPath('results.summary.clean', false)
        ->assertJsonStructure(['results' => ['scan_id', 'status', 'summary' => ['threats_found', 'risk_level'], 'results']]);
    // Backdoor (critical) + admin_no_email (critical) => at least 2 threats.
    expect($res->json('results.summary.threats_found'))->toBeGreaterThanOrEqual(2);
});

it('rejects files calls with an invalid token', function () {
    [$project, $headers] = authedProject();
    $this->postJson('/api/v1/scanner/files', ['token' => 'bogus', 'files' => []], $headers)
        ->assertStatus(422);
});

it('rejects a token that belongs to a different project (tenant isolation)', function () {
    [$projectA, $headersA] = authedProject();
    $keyB = 'test-secret-key-456';
    $projectB = Project::factory()->create([
        'health_check_secret' => $keyB,
        'health_check_secret_hash' => hash('sha256', $keyB),
        'url' => 'https://other.example',
    ]);
    $headersB = ['X-LSM-Key' => $keyB];

    $token = $this->postJson('/api/v1/scanner/session', ['scan_id' => 1, 'scan_type' => 'full', 'wp_version' => '6.5'], $headersA)
        ->json('token');

    $this->postJson('/api/v1/scanner/files', ['token' => $token, 'files' => []], $headersB)
        ->assertStatus(422);
});
