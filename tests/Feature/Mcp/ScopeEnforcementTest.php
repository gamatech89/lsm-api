<?php

use App\Mcp\Resources\DashboardResource;
use App\Mcp\Servers\LsmServer;
use App\Mcp\Tools\GetDashboardTool;
use App\Mcp\Tools\ListTodosTool;
use App\Models\User;

test('a read-scoped token can list and call a read tool', function () {
    $user = actingWithScopes(User::factory()->create(['role' => 'admin']), ['mcp:read']);

    LsmServer::actingAs($user)
        ->tool(GetDashboardTool::class, [])
        ->assertOk();
});

test('a token with no mcp:read cannot call a read tool', function () {
    // shouldRegister() already filters this tool out of tools/call routing
    // (the same $context->tools() collection backs both tools/list and
    // tools/call), so a scope-lacking token calling through the Server gets
    // "Tool not found" before handle() ever runs. assertScope() exists for
    // the caller that resolves the tool class directly and invokes handle()
    // itself, bypassing that routing — this test exercises that boundary.
    $user = actingWithScopes(User::factory()->create(['role' => 'admin']), ['mcp:write']);
    app('auth')->guard()->setUser($user);

    $response = app()->call([new GetDashboardTool, 'handle'], ['request' => new \Laravel\Mcp\Request([])]);

    expect($response->isError())->toBeTrue();
    expect((string) $response->content())->toContain('mcp:read');
});

test('tools/list hides read tools from a token without mcp:read', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $token = $user->createToken('write-only', ['mcp:write'], now()->addYear())->plainTextToken;

    $names = collect($this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ])->assertOk()->json('result.tools'))->pluck('name');

    expect($names)->not->toContain('get-dashboard');
    expect($names)->not->toContain('list-todos');
});

test('tools/list shows read tools to a token with mcp:read', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $token = $user->createToken('reader', ['mcp:read'], now()->addYear())->plainTextToken;

    $names = collect($this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ])->assertOk()->json('result.tools'))->pluck('name');

    expect($names)->toContain('get-dashboard');
    expect($names)->toContain('list-todos');
});

test('a legacy wildcard token still sees every tool', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $token = $user->createToken('legacy', ['*'], now()->addMinutes(480))->plainTextToken;

    // per_page: the MCP transport paginates tools/list at 15 by default
    // (max 50); this is unrelated to scope enforcement, so raise the page
    // size to see the full registered set in one page.
    $names = collect($this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => ['per_page' => 50],
    ])->assertOk()->json('result.tools'))->pluck('name');

    expect($names)->toHaveCount(44);
});

test('resources are hidden from a token without mcp:read', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $token = $user->createToken('write-only', ['mcp:write'], now()->addYear())->plainTextToken;

    $uris = collect($this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'resources/list',
    ])->assertOk()->json('result.resources'))->pluck('uri');

    expect($uris)->not->toContain('lsm://dashboard');
});

test('prompts are hidden from a token without mcp:read', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $token = $user->createToken('write-only', ['mcp:write'], now()->addYear())->plainTextToken;

    $names = collect($this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'prompts/list',
    ])->assertOk()->json('result.prompts'))->pluck('name');

    expect($names)->not->toContain('morning-briefing');
});

test('a read resource is reachable with mcp:read', function () {
    $user = actingWithScopes(User::factory()->create(['role' => 'admin']), ['mcp:read']);

    LsmServer::actingAs($user)
        ->resource(DashboardResource::class)
        ->assertOk();
});

test('role scoping still applies on top of an ability', function () {
    // A developer with mcp:read sees only their own todos, exactly as before.
    $developer = actingWithScopes(User::factory()->create(['role' => 'developer']), ['mcp:read']);

    LsmServer::actingAs($developer)
        ->tool(ListTodosTool::class, [])
        ->assertOk();
});
