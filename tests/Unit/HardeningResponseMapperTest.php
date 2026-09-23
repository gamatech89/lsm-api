<?php

use App\Services\HardeningResponseMapper;

// One test per row of the spec's response-mapping table, plus the edges that
// must fall into the "no response" row.

test('row 1: 200 success:true maps to 200 with message, warnings and status', function () {
    $status = hardeningPluginStatus('on');
    $mapped = HardeningResponseMapper::map([
        'http' => 200,
        'json' => hardeningPluginBody(true, null, $status, ['already_blocked_elsewhere']),
    ]);

    expect($mapped)->toBe([
        'outcome' => 'ok',
        'code' => 200,
        'body' => [
            'success' => true,
            'message' => 'Applied and verified',
            'warnings' => ['already_blocked_elsewhere'],
            'status' => $status,
        ],
    ]);
});

test('row 2: 200 success:false with reason busy maps to 409', function () {
    $status = hardeningPluginStatus('on');
    $mapped = HardeningResponseMapper::map([
        'http' => 200,
        'json' => hardeningPluginBody(false, 'busy', $status, [], 'Another hardening operation is running'),
    ]);

    expect($mapped)->toBe([
        'outcome' => 'busy',
        'code' => 409,
        'body' => [
            'success' => false,
            'reason' => 'busy',
            'message' => 'Another hardening operation is running',
            'warnings' => [],
            'status' => $status,
        ],
    ]);
});

test('row 3: 200 success:false with any other reason maps to 422 and keeps the reason', function () {
    $status = hardeningPluginStatus('off');
    $mapped = HardeningResponseMapper::map([
        'http' => 200,
        'json' => hardeningPluginBody(false, 'rule_ineffective', $status, [], 'The .zip probe was served by a front-end nginx'),
    ]);

    expect($mapped)->toBe([
        'outcome' => 'failed',
        'code' => 422,
        'body' => [
            'success' => false,
            'reason' => 'rule_ineffective',
            'message' => 'The .zip probe was served by a front-end nginx',
            'warnings' => [],
            'status' => $status,
        ],
    ]);
});

test('row 4: 404 maps to 409 plugin_outdated with min_version 2.10.0', function () {
    $mapped = HardeningResponseMapper::map([
        'http' => 404,
        'json' => ['code' => 'rest_no_route', 'message' => 'No route was found matching the URL and request method.'],
    ]);

    expect($mapped)->toBe([
        'outcome' => 'plugin_outdated',
        'code' => 409,
        'body' => [
            'success' => false,
            'reason' => 'plugin_outdated',
            'min_version' => '2.10.0',
            'message' => 'Requires plugin 2.10.0 or newer — update the plugin',
        ],
    ]);
});

test('row 5: 401 and 403 map to 502 unauthorized', function (int $http) {
    $mapped = HardeningResponseMapper::map(['http' => $http, 'json' => ['code' => 'rest_forbidden']]);

    expect($mapped)->toBe([
        'outcome' => 'unauthorized',
        'code' => 502,
        'body' => [
            'success' => false,
            'reason' => 'unauthorized',
            'message' => 'The site rejected the platform API key',
        ],
    ]);
})->with([401, 403]);

test('row 6: no response maps to 502 unreachable with the outcome-unknown message', function () {
    $mapped = HardeningResponseMapper::map(['http' => null, 'json' => null]);

    expect($mapped)->toBe([
        'outcome' => 'unreachable',
        'code' => 502,
        'body' => [
            'success' => false,
            'reason' => 'unreachable',
            'message' => 'Outcome unknown — refresh status',
        ],
    ]);
});

test('row 6: a 5xx maps to 502 unreachable', function (int $http) {
    $mapped = HardeningResponseMapper::map(['http' => $http, 'json' => null]);

    expect($mapped['outcome'])->toBe('unreachable');
    expect($mapped['code'])->toBe(502);
    expect($mapped['body']['message'])->toBe('Outcome unknown — refresh status');
})->with([500, 502, 503, 504]);

test('a 200 without a boolean success key is treated as no usable response', function (mixed $json) {
    $mapped = HardeningResponseMapper::map(['http' => 200, 'json' => $json]);

    expect($mapped['outcome'])->toBe('unreachable');
    expect($mapped['code'])->toBe(502);
})->with([
    'html page' => [null],
    'empty object' => [[]],
    'truthy string' => [['success' => 'true']],
]);

test('any other status code is treated as no usable response', function (int $http) {
    $mapped = HardeningResponseMapper::map(['http' => $http, 'json' => ['message' => 'nope']]);

    expect($mapped['outcome'])->toBe('unreachable');
    expect($mapped['code'])->toBe(502);
})->with([301, 400, 429]);

test('missing message, warnings and status in a plugin body get safe defaults', function () {
    $ok = HardeningResponseMapper::map(['http' => 200, 'json' => ['success' => true]]);
    $failed = HardeningResponseMapper::map(['http' => 200, 'json' => ['success' => false]]);

    expect($ok['body'])->toBe(['success' => true, 'message' => 'Done', 'warnings' => [], 'status' => null]);
    expect($failed['code'])->toBe(422);
    expect($failed['body'])->toBe([
        'success' => false,
        'reason' => null,
        'message' => 'The site could not apply the change',
        'warnings' => [],
        'status' => null,
    ]);
});
