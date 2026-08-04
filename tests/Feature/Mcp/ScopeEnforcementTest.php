<?php

use App\Mcp\Resources\DashboardResource;
use App\Mcp\Servers\LsmServer;
use App\Mcp\Tools\GetDashboardTool;
use App\Mcp\Tools\ListTodosTool;
use App\Mcp\Tools\WpEmergencyTool;
use App\Mcp\Tools\WpRestoreBackupTool;
use App\Models\Todo;
use App\Models\User;

// The full sets this task gates on mcp:read — 13 tools, 6 resources, 2
// prompts. Assert against the whole set, not a sample: sampling two tool
// names left 17 of the 21 gates uncovered (VaultResource among them), so
// deleting the trait from any of the other 17 left the suite green.
$readToolNames = [
    'get-dashboard',
    'get-project',
    'list-projects',
    'list-todos',
    'list-todo-templates',
    'list-time-entries',
    'list-team',
    'get-team-workload',
    'get-team-availability',
    'list-invoices',
    'list-support-tickets',
    'list-tags',
    'list-resources',
];

$readResourceUris = [
    'lsm://dashboard',
    'lsm://todos/mine',
    'lsm://projects',
    'lsm://sites/at-risk',
    'lsm://time/today',
    'lsm://vault',
];

$readPromptNames = [
    'morning-briefing',
    'weekly-status',
];

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

test('tools/list hides read tools from a token without mcp:read', function () use ($readToolNames) {
    $user = User::factory()->create(['role' => 'admin']);
    $token = $user->createToken('write-only', ['mcp:write'], now()->addYear())->plainTextToken;

    // per_page: tools/list paginates at 15 by default (max 50), unrelated to
    // scope enforcement — see the wildcard test below. Without raising it,
    // this assertion would only ever see the first 15 of 44 registered
    // tools, which happened to make it pass on registration-order luck
    // alone (get-dashboard and list-todos sit at positions 1 and 6).
    $names = collect($this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => ['per_page' => 50],
    ])->assertOk()->json('result.tools'))->pluck('name');

    expect($names->intersect($readToolNames))->toBeEmpty();
});

test('tools/list shows read tools to a token with mcp:read', function () use ($readToolNames) {
    // False-deny control: this passes even with the trait removed entirely
    // from every primitive, since an ungated primitive registers
    // unconditionally. It only proves mcp:read isn't wrongly denied — the
    // "hides" test above is what proves the gate exists.
    $user = User::factory()->create(['role' => 'admin']);
    $token = $user->createToken('reader', ['mcp:read'], now()->addYear())->plainTextToken;

    $names = collect($this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => ['per_page' => 50],
    ])->assertOk()->json('result.tools'))->pluck('name');

    foreach ($readToolNames as $name) {
        expect($names)->toContain($name);
    }
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

test('resources are hidden from a token without mcp:read', function () use ($readResourceUris) {
    $user = User::factory()->create(['role' => 'admin']);
    $token = $user->createToken('write-only', ['mcp:write'], now()->addYear())->plainTextToken;

    $uris = collect($this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'resources/list',
    ])->assertOk()->json('result.resources'))->pluck('uri');

    expect($uris->intersect($readResourceUris))->toBeEmpty();
});

test('prompts are hidden from a token without mcp:read', function () use ($readPromptNames) {
    $user = User::factory()->create(['role' => 'admin']);
    $token = $user->createToken('write-only', ['mcp:write'], now()->addYear())->plainTextToken;

    $names = collect($this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'prompts/list',
    ])->assertOk()->json('result.prompts'))->pluck('name');

    expect($names->intersect($readPromptNames))->toBeEmpty();
});

test('a read resource is reachable with mcp:read', function () {
    $user = actingWithScopes(User::factory()->create(['role' => 'admin']), ['mcp:read']);

    LsmServer::actingAs($user)
        ->resource(DashboardResource::class)
        ->assertOk();
});

test('role scoping still applies on top of an ability', function () {
    // The plan's core invariant: an ability intersects with the caller's
    // role, it never widens it. A developer with mcp:read must still see
    // only their own todos — this test would fail if ListTodosTool's
    // assignee_id filter were ever deleted, since it creates one todo
    // assigned to the developer and one assigned to someone else, and
    // checks the response contains only the former.
    $developer = actingWithScopes(User::factory()->create(['role' => 'developer']), ['mcp:read']);
    $stranger = User::factory()->create(['role' => 'developer']);

    Todo::factory()->create([
        'assignee_id' => $developer->id,
        'status' => 'pending',
        'title' => 'My Own Read-Scoped Todo',
    ]);
    Todo::factory()->create([
        'assignee_id' => $stranger->id,
        'status' => 'pending',
        'title' => 'Someone Elses Todo',
    ]);

    LsmServer::actingAs($developer)
        ->tool(ListTodosTool::class, [])
        ->assertOk()
        ->assertSee('My Own Read-Scoped Todo')
        ->assertDontSee('Someone Elses Todo');
});

test('every registered tool declares a required scope', function () {
    $reflection = new ReflectionClass(LsmServer::class);
    $server = $reflection->newInstanceWithoutConstructor();
    $p = $reflection->getProperty('tools');
    $p->setAccessible(true);

    $valid = ['mcp:read', 'mcp:write', 'mcp:wp', 'mcp:wp-destructive'];

    foreach ($p->getValue($server) as $class) {
        $tool = new ReflectionClass($class);

        expect($tool->hasMethod('requiredScope'))
            ->toBeTrue("{$class} does not implement requiredScope()");

        $method = $tool->getMethod('requiredScope');
        $method->setAccessible(true);
        $scope = $method->invoke($tool->newInstanceWithoutConstructor());

        expect($scope)->toBeIn($valid, "{$class} declares an unknown scope: {$scope}");
    }
});

test('the tools split across the four scopes exactly as designed', function () {
    $reflection = new ReflectionClass(LsmServer::class);
    $server = $reflection->newInstanceWithoutConstructor();
    $p = $reflection->getProperty('tools');
    $p->setAccessible(true);

    $counts = collect($p->getValue($server))
        ->map(function (string $class) {
            $reflection = new ReflectionClass($class);
            $method = $reflection->getMethod('requiredScope');
            $method->setAccessible(true);

            return $method->invoke($reflection->newInstanceWithoutConstructor());
        })
        ->countBy()
        ->all();

    expect($counts)->toBe([
        'mcp:read' => 13,
        'mcp:write' => 14,
        'mcp:wp' => 13,
        'mcp:wp-destructive' => 4,
    ]);
});

test('a read-only token cannot see the destructive tools', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $token = $user->createToken('reader', ['mcp:read'], now()->addYear())->plainTextToken;

    $names = collect($this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ])->assertOk()->json('result.tools'))->pluck('name');

    expect($names)->toHaveCount(13);
    expect($names)->not->toContain('wp-emergency');
    expect($names)->not->toContain('bulk-wp-action');
    expect($names)->not->toContain('wp-restore-backup');
});

test('calling a destructive tool with a read-only token is refused', function () {
    // Per the note above ("tools/list hides read tools..."): shouldRegister()
    // filters CallTool's routing too, so a scope-lacking token calling
    // ->tool(WpRestoreBackupTool::class, ...) would get "Tool not found"
    // before handle() ever runs, and the scope message would never appear.
    // This exercises the assertScope() backstop directly instead, the same
    // way the read-tool boundary test above does.
    $user = actingWithScopes(User::factory()->create(['role' => 'admin']), ['mcp:read']);
    app('auth')->guard()->setUser($user);

    $response = app()->call([new WpRestoreBackupTool, 'handle'], [
        'request' => new \Laravel\Mcp\Request(['backup_id' => 1]),
    ]);

    expect($response->isError())->toBeTrue();
    expect((string) $response->content())->toContain('mcp:wp-destructive');
});

test('a wp token does not carry destructive access', function () {
    $user = actingWithScopes(User::factory()->create(['role' => 'admin']), ['mcp:wp']);
    app('auth')->guard()->setUser($user);

    $response = app()->call([new WpEmergencyTool, 'handle'], [
        'request' => new \Laravel\Mcp\Request(['project_id' => 1, 'action' => 'status']),
    ]);

    expect($response->isError())->toBeTrue();
    expect((string) $response->content())->toContain('mcp:wp-destructive');
});
