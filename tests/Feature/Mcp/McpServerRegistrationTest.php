<?php

use App\Mcp\Servers\LsmServer;
use App\Models\User;

test('exactly one server class is registered for the mcp route', function () {
    expect(class_exists(\App\Mcp\LsmServer::class))->toBeFalse();
    expect(class_exists(\App\Providers\McpServiceProvider::class))->toBeFalse();
});

test('the mcp endpoint rejects unauthenticated requests', function () {
    $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ])->assertStatus(401);
});

test('the server registers all 44 tools, 6 resources and 2 prompts', function () {
    $reflection = new ReflectionClass(LsmServer::class);
    $server = $reflection->newInstanceWithoutConstructor();

    $read = function (string $property) use ($reflection, $server) {
        $p = $reflection->getProperty($property);
        $p->setAccessible(true);

        return $p->getValue($server);
    };

    expect($read('tools'))->toHaveCount(44);
    expect($read('resources'))->toHaveCount(6);
    expect($read('prompts'))->toHaveCount(2);
});

test('every registered tool class exists and extends the MCP Tool base', function () {
    $reflection = new ReflectionClass(LsmServer::class);
    $server = $reflection->newInstanceWithoutConstructor();
    $p = $reflection->getProperty('tools');
    $p->setAccessible(true);

    foreach ($p->getValue($server) as $tool) {
        expect(class_exists($tool))->toBeTrue("Missing tool class: {$tool}");
        expect(is_subclass_of($tool, \Laravel\Mcp\Server\Tool::class))->toBeTrue();
    }
});

test('an authenticated user reaches the mcp endpoint', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $token = $user->createToken('mcp-test', ['*'], now()->addMinutes(60))->plainTextToken;

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ])->assertOk();
});
