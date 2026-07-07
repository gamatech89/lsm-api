<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'admin']);
    // Fail loudly instead of hitting the network if a request slips past the
    // SSRF guard. Blocked URLs never reach the fetch, so they need no stub.
    Http::preventStrayRequests();
});

test('blocks the cloud metadata address', function () {
    $response = $this->actingAs($this->user)
        ->get('/api/v1/site-review-proxy?url=' . urlencode('http://169.254.169.254/latest/meta-data/'));

    $response->assertStatus(403);
});

test('blocks loopback and private addresses', function () {
    foreach (['http://127.0.0.1/', 'http://10.0.0.5/', 'http://192.168.1.1/'] as $url) {
        $this->actingAs($this->user)
            ->get('/api/v1/site-review-proxy?url=' . urlencode($url))
            ->assertStatus(403);
    }
});

test('blocks non-http schemes', function () {
    $this->actingAs($this->user)
        ->get('/api/v1/site-review-proxy?url=' . urlencode('file:///etc/passwd'))
        ->assertStatus(403);
});

test('blocks a public url that redirects to an internal address', function () {
    Http::fake([
        'http://1.1.1.1/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/']),
        '*' => Http::response('should not reach', 200),
    ]);

    $response = $this->actingAs($this->user)
        ->get('/api/v1/site-review-proxy?url=' . urlencode('http://1.1.1.1/'));

    $response->assertStatus(403);
});

test('proxies a public html page and injects the base tag', function () {
    Http::fake([
        'http://1.1.1.1/*' => Http::response('<html><head></head><body>hi</body></html>', 200, ['Content-Type' => 'text/html']),
    ]);

    $response = $this->actingAs($this->user)
        ->get('/api/v1/site-review-proxy?url=' . urlencode('http://1.1.1.1/'));

    $response->assertOk();
    expect($response->getContent())->toContain('<base href=');
    expect($response->getContent())->toContain('__lsm_scroll_tracker__');
});

test('requires authentication', function () {
    $this->getJson('/api/v1/site-review-proxy?url=' . urlencode('http://1.1.1.1/'))
        ->assertStatus(401);
});
