<?php

use App\Models\Project;
use App\Services\LsmService;
use Illuminate\Support\Facades\Http;

test('lsm service sends the api key via header and never in the url', function () {
    Http::fake([
        '*' => Http::response(['success' => true, 'data' => ['ok' => true]], 200),
    ]);

    $project = Project::factory()->create([
        'url' => 'https://client.example.com',
        'health_check_secret' => 'SECRETKEY123',
    ]);

    // GET path
    LsmService::for($project)->getHealth();
    // POST path
    LsmService::for($project)->clearCache();

    Http::assertSent(function ($request) {
        return $request->hasHeader('X-LSM-Key', 'SECRETKEY123')
            && ! str_contains($request->url(), 'SECRETKEY123');
    });
});
