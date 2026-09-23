<?php

use App\Services\LsmService;
use Illuminate\Support\Facades\Http;

test('a hardening GET keeps the success key, sends the key header and busts the host cache', function () {
    Http::fake([
        '*' => Http::response(['success' => true, 'data' => ['x' => 1], 'status' => ['server' => 'Apache']], 200),
    ]);

    $result = LsmService::for(hardeningProject())->hardeningRequest('GET', '/hardening/status');

    // Not unwrapped by handleResponse(): `success` survives next to `data`.
    expect($result)->toBe([
        'http' => 200,
        'json' => ['success' => true, 'data' => ['x' => 1], 'status' => ['server' => 'Apache']],
    ]);

    Http::assertSent(function ($request) {
        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://client.example.com/wp-json/lsm/v1/hardening/status?_=')
            && $request->hasHeader('X-LSM-Key', 'SECRETKEY123')
            && ! str_contains($request->url(), 'SECRETKEY123');
    });
});

test('two hardening GETs never share a cache-buster', function () {
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    $lsm = LsmService::for(hardeningProject());
    $lsm->hardeningRequest('GET', '/hardening/status');
    $lsm->hardeningRequest('GET', '/hardening/status');

    $urls = collect(Http::recorded())->map(fn ($pair) => $pair[0]->url());

    expect($urls)->toHaveCount(2);
    expect($urls->unique())->toHaveCount(2);
});

test('a hardening POST sends a JSON body with the given timeout and no cache-buster', function () {
    $seenTimeout = null;
    Http::fake(function ($request, array $options) use (&$seenTimeout) {
        $seenTimeout = $options['timeout'] ?? null;

        return Http::response(['success' => false, 'reason' => 'busy'], 200);
    });

    $result = LsmService::for(hardeningProject())
        ->hardeningRequest('POST', '/hardening/rule', ['rule' => 'block_archives', 'enabled' => true], 120);

    expect($result)->toBe(['http' => 200, 'json' => ['success' => false, 'reason' => 'busy']]);
    expect($seenTimeout)->toBe(120);

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://client.example.com/wp-json/lsm/v1/hardening/rule'
            && $request->isJson()
            && $request['rule'] === 'block_archives'
            && $request['enabled'] === true
            && $request->hasHeader('X-LSM-Key', 'SECRETKEY123');
    });
});

test('the default hardening timeout is 30 seconds', function () {
    $seenTimeout = null;
    Http::fake(function ($request, array $options) use (&$seenTimeout) {
        $seenTimeout = $options['timeout'] ?? null;

        return Http::response(['success' => true], 200);
    });

    LsmService::for(hardeningProject())->hardeningRequest('GET', '/hardening/status');

    expect($seenTimeout)->toBe(30);
});

test('a 404 from an old plugin comes back with its status code instead of null', function () {
    Http::fake([
        '*' => Http::response(['code' => 'rest_no_route', 'message' => 'No route was found', 'data' => ['status' => 404]], 404),
    ]);

    $result = LsmService::for(hardeningProject())->hardeningRequest('GET', '/hardening/status');

    expect($result['http'])->toBe(404);
    expect($result['json']['code'])->toBe('rest_no_route');
});

test('a non-JSON error page yields the status code and a null json', function () {
    Http::fake(['*' => Http::response('<html>Bad gateway</html>', 502)]);

    $result = LsmService::for(hardeningProject())->hardeningRequest('POST', '/hardening/resume', [], 120);

    expect($result)->toBe(['http' => 502, 'json' => null]);
});

test('a connection failure yields http null and json null', function () {
    Http::fake(['*' => Http::failedConnection()]);

    $result = LsmService::for(hardeningProject())->hardeningRequest('POST', '/hardening/pause', ['minutes' => 60], 120);

    expect($result)->toBe(['http' => null, 'json' => null]);
});

test('an unconfigured project yields http null without sending anything', function () {
    Http::fake();

    $result = LsmService::for(hardeningProject(['health_check_secret' => null]))
        ->hardeningRequest('GET', '/hardening/status');

    expect($result)->toBe(['http' => null, 'json' => null]);
    Http::assertNothingSent();
});

test('a redirected hardening POST stays a POST and keeps its body and key', function () {
    $seen = [];
    Http::fake(function ($request) use (&$seen) {
        $seen[] = [$request->method(), $request->url(), $request->body(), $request->hasHeader('X-LSM-Key', 'SECRETKEY123')];

        return str_starts_with($request->url(), 'http://')
            ? Http::response('', 301, ['Location' => str_replace('http://', 'https://', $request->url())])
            : Http::response(['success' => true], 200);
    });

    $result = LsmService::for(hardeningProject(['url' => 'http://client.example.com']))
        ->hardeningRequest('POST', '/hardening/pause', ['minutes' => 15], 120);

    expect($result)->toBe(['http' => 200, 'json' => ['success' => true]]);
    expect($seen[1])->toBe(['POST', 'https://client.example.com/wp-json/lsm/v1/hardening/pause', '{"minutes":15}', true]);
});
