# MCP Integration Tokens Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Issue long-lived, narrowly-scoped bearer tokens for MCP clients, managed from the web UI, without changing how the web app's session tokens behave.

**Architecture:** Sanctum's global token cap is removed and replaced by an explicit per-token `expires_at` at every mint site, so a token's lifetime becomes a property of the token rather than of the config. Four abilities (`mcp:read`, `mcp:write`, `mcp:wp`, `mcp:wp-destructive`) are enforced by a trait on every MCP primitive via `shouldRegister()`, which hides out-of-scope tools from `tools/list`, plus an `assertScope()` guard at the top of every `handle()` as the actual security boundary. A REST controller mints and revokes these tokens behind a step-up password check.

**Tech Stack:** Laravel 11 / PHP 8.3, Laravel Sanctum, `laravel/mcp`, Pest (SQLite `:memory:`), React 18 + TypeScript + Vite + Ant Design 5 + TanStack Query 5.

## Global Constraints

- **Session token behaviour must stay byte-for-byte identical.** Login, 2FA-verify and refresh tokens keep an 8-hour life. The number stays configurable through `SANCTUM_EXPIRATION`.
- **The backfill migration ships in the same commit as `expiration => null`.** Separating them makes every pre-existing token immortal, including the admin token in `~/.claude.json`.
- **Tests run on SQLite `:memory:`** (`phpunit.xml:26-27`). No raw MySQL SQL in migrations — this repo has been bitten before. Use driver-agnostic PHP-side data manipulation.
- **The baseline suite is green: 263 passed, 2 skipped, 0 failed** (measured on `843c47f`, before any task ran). There are no known-failing tests to excuse. Any red test at any point is a regression you caused — stop and fix it, never explain it away.
- **Scopes narrow, roles bound.** An ability never widens what a role may do. Every tool keeps its existing `Auth::user()` role checks unchanged.
- **Abilities validated against the caller's role at mint time.** `mcp:wp-destructive` is admin/manager only — verified against `WpEmergencyTool.php:43`, `BulkWpActionTool.php:32`, `WpRestoreBackupTool.php:43`.
- **Never log or return a plaintext token** anywhere except the single `POST` create response.
- **Branch:** `feature/mcp-integration-tokens` in `lsm-api` (already checked out, from `main`) and a branch of the same name in `lsm-web` (from `master`). Two PRs.
- **UI language is German**, matching the surrounding `ProfilePage.tsx` / `SettingsPage.tsx` copy.

---

## Deviations from the spec

Three things were verified against the code and did not match the spec. They are folded into the tasks below.

**1. The spec says 44 tools are live. 13 are.** Two server classes both register `/mcp`:

| Class | Tools | Registered by | Middleware |
|---|---|---|---|
| `App\Mcp\LsmServer` | 44 | `McpServiceProvider::boot()` | **none** |
| `App\Mcp\Servers\LsmServer` | 13 | `routes/mcp.php:23` | `auth:sanctum` |

`php artisan route:list --path=mcp` shows only two routes (GET/POST `mcp`) — one registration shadows the other. Route files load after providers boot, so `Servers\LsmServer` wins; a live MCP session exposes exactly its 13 tools. The provider's registration also passes no middleware at all and ignores `config('mcp.route.middleware')`, so any change in registration order would expose all 44 tools unauthenticated. **Task 1 consolidates to one server before anything else.**

**2. `LsmServer::actingAs($user)` does not set an access token.** `vendor/laravel/mcp/src/Server/Testing/PendingTestResponse.php:144` calls `auth()->guard()->setUser($user)` only. `tokenCan()` is `$this->accessToken && $this->accessToken->can($ability)` (`HasApiTokens.php:36-39`), so it returns `false` for a user set this way. Applying the trait unchanged would hide every tool from the nine existing tests in `tests/Feature/Mcp/ManagerMembershipToolsTest.php`. Task 4 attaches a real token in those tests rather than loosening the trait.

Do **not** reach for `Sanctum::actingAs($user, ['mcp:read'])` in scope tests. It builds a Mockery mock with `shouldIgnoreMissing(false)` and stubs `can()` only for the listed abilities (`Sanctum.php:71-81`), so asserting that a *different* ability is denied throws instead of returning false. Use a real `createToken()` — the helper in Task 4, Step 1.

**3. `tests/Feature/TokenExpirationTest.php:5` asserts `config('sanctum.expiration') === 480`** — the exact value Task 2 sets to `null`. It is rewritten in the same commit.

**4. UI placement moved from `SettingsPage.tsx` to `ProfilePage.tsx`.** `src/App.tsx:113-118` wraps `/settings` in `<AdminRoute>`, so the spec's placement would make tokens admin-only — which contradicts the spec's own role-aware scope validation (§1) and per-user isolation (§4). `/profile` is ungated (`App.tsx:93`) and already hosts Change Password, TOTP and Email-2FA cards, which is the right neighbourhood for a personal credential. Say so if you want it in Settings instead; it is a one-line move.

---

## File Structure

### `lsm-api`

**Create:**
- `app/Mcp/Concerns/HasRequiredScope.php` — the trait: `shouldRegister()` for listing, `assertScope()` for the call boundary, `$requiredScope` default `mcp:read`.
- `app/Listeners/RecordTokenUsageIp.php` — writes `last_used_ip` on `Laravel\Sanctum\Events\TokenAuthenticated`, only when the IP changed.
- `app/Http/Requests/StoreIntegrationTokenRequest.php` — validation, scope allow-list, role gate, step-up password rule.
- `app/Http/Controllers/Api/V1/IntegrationTokenController.php` — index/store/destroy, filtered to `type = integration` and `Auth::id()`.
- `app/Http/Resources/IntegrationTokenResource.php` — the safe projection. Never carries a token value.
- `database/migrations/2026_08_04_120000_backfill_session_token_expiry.php`
- `database/migrations/2026_08_04_120100_add_type_and_ip_to_personal_access_tokens.php`
- `tests/Feature/Mcp/McpServerRegistrationTest.php`
- `tests/Feature/Mcp/ScopeEnforcementTest.php`
- `tests/Feature/IntegrationTokenControllerTest.php`

**Modify:**
- `app/Mcp/Servers/LsmServer.php` — becomes the single server, all 44 tools.
- `app/Mcp/LsmServer.php` — **deleted**.
- `app/Providers/McpServiceProvider.php` — **deleted**; `bootstrap/providers.php` loses its entry.
- `routes/mcp.php` — sole registration point, gated on `config('mcp.enabled')`.
- `config/mcp.php` — `route.middleware` corrected to `['auth:sanctum']` (no `web`; that would add session/CSRF to an API endpoint).
- `config/sanctum.php:50` — `expiration => null`, new `session_expiration`.
- `app/Http/Controllers/Api/V1/AuthController.php:69,136` — explicit expiry.
- `app/Http/Controllers/Api/V1/TwoFactorController.php:158` — explicit expiry.
- `app/Models/User.php` — `integrationTokens()` relation.
- `routes/api.php` — three routes inside the existing `auth:sanctum` + `EnsureTwoFactorEnrolled` group at line 111.
- `app/Providers/AppServiceProvider.php` — listener wiring.
- `tests/Pest.php` — `actingWithScopes()` helper.
- `tests/Feature/TokenExpirationTest.php` — rewritten around per-token expiry.
- `tests/Feature/Mcp/ManagerMembershipToolsTest.php` — nine `actingAs` sites gain a real token.
- All 44 `app/Mcp/Tools/*.php`, 6 `app/Mcp/Resources/*.php`, 2 `app/Mcp/Prompts/*.php` — two lines each.

### `lsm-web`

**Create:**
- `src/lib/integration-tokens-api.ts` — API module, matching `ephemeral-secrets-api.ts`.
- `src/features/profile/components/IntegrationTokensCard.tsx` — the table.
- `src/features/profile/components/CreateTokenModal.tsx` — form + one-time reveal.

**Modify:**
- `packages/types/src/index.ts` — `IntegrationToken`, `IntegrationTokenScope`, `CreateIntegrationTokenPayload`, `CreatedIntegrationToken`.
- `src/lib/api.ts` — register the module.
- `src/lib/queryKeys.ts` — `integrationTokens` keys.
- `src/features/profile/pages/ProfilePage.tsx` — mount the card.

`ProfilePage.tsx` is already 728 lines. The new surface goes in its own components rather than growing that file further.

---

## Task 1: Consolidate the MCP server onto one registration

Two `Mcp::web()` calls fight over `/mcp` and the losing one carries no auth middleware. Fix this before scopes exist, so every later test asserts against one server.

**Files:**
- Modify: `app/Mcp/Servers/LsmServer.php`
- Modify: `routes/mcp.php`
- Modify: `config/mcp.php:25`
- Modify: `bootstrap/providers.php`
- Modify: `tests/Feature/Mcp/ManagerMembershipToolsTest.php:3`
- Delete: `app/Mcp/LsmServer.php`, `app/Providers/McpServiceProvider.php`
- Test: `tests/Feature/Mcp/McpServerRegistrationTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `App\Mcp\Servers\LsmServer` as the only MCP server class, registering all 44 tools, 6 resources, 2 prompts. Every later task references this class.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Mcp/McpServerRegistrationTest.php`:

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Mcp/McpServerRegistrationTest.php`
Expected: FAIL — `class_exists(App\Mcp\LsmServer)` is still true, and the tool count is 13.

- [ ] **Step 3: Move all 44 tool registrations onto the live server**

`app/Mcp/LsmServer.php` already holds the complete, correct list. Copy its `use` statements and its `$tools`, `$resources` and `$prompts` arrays into `app/Mcp/Servers/LsmServer.php`, replacing the 13-tool array at lines 111-134. Keep `Servers\LsmServer`'s own `$name`, `$version` and `$instructions` — that class is the one with the prompt-injection guardrails added in `fc99bfe`, and those must survive.

The resulting `$tools` array, in full:

```php
    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    protected array $tools = [
        // Dashboard & projects
        GetDashboardTool::class,
        ListProjectsTool::class,
        GetProjectTool::class,
        CreateProjectTool::class,
        UpdateProjectTool::class,

        // Todos
        ListTodosTool::class,
        CreateTodoTool::class,
        UpdateTodoTool::class,
        CompleteTodoTool::class,
        DeleteTodoTool::class,
        ListTodoTemplatesTool::class,
        ApplyTodoTemplateTool::class,

        // Time tracking
        ListTimeEntriesTool::class,
        CreateTimeEntryTool::class,
        StartTimerTool::class,
        StopTimerTool::class,

        // Team
        ListTeamTool::class,
        GetTeamWorkloadTool::class,
        GetTeamAvailabilityTool::class,
        BulkAssignDevelopersTool::class,
        BulkAssignManagersTool::class,

        // Billing, tickets, library, tags
        ListInvoicesTool::class,
        GeneratePdfTool::class,
        ListSupportTicketsTool::class,
        CreateSupportTicketTool::class,
        ListResourcesTool::class,
        ListTagsTool::class,

        // WordPress — reversible
        WpLoginTool::class,
        WpCheckConnectionsTool::class,
        WpClearCacheTool::class,
        WpEnableMaintenanceTool::class,
        WpDisableMaintenanceTool::class,
        WpGetUpdatesTool::class,
        WpUpdatePluginsTool::class,
        WpUpdateCoreTool::class,
        WpOptimizeDatabaseTool::class,
        WpCreateBackupTool::class,
        WpListBackupsTool::class,
        WpGetPhpErrorsTool::class,
        WpClearPhpErrorsTool::class,

        // WordPress — destructive
        WpEmergencyTool::class,
        BulkWpActionTool::class,
        WpRestoreBackupTool::class,
        WpDownloadBackupTool::class,
    ];
```

Add the matching imports for every class not already imported at the top of the file, in the existing alphabetical `App\Mcp\Tools\…` block. `$resources` and `$prompts` are already complete — leave them.

- [ ] **Step 4: Make `routes/mcp.php` the sole registration**

Replace the two registration calls at the bottom of `routes/mcp.php` with:

```php
// Single registration point for the MCP server. Previously this competed with
// App\Providers\McpServiceProvider, which registered the same path with no
// middleware at all — see docs/superpowers/plans/2026-08-04-mcp-integration-tokens.md.
if (config('mcp.enabled', true)) {
    Mcp::web(config('mcp.route.path', '/mcp'), LsmServer::class)
        ->middleware(config('mcp.route.middleware', ['auth:sanctum']));

    // Local stdio transport for CLI clients.
    Mcp::local('lsm', LsmServer::class);
}
```

- [ ] **Step 5: Correct the middleware config**

In `config/mcp.php`, change line 25 from `'middleware' => ['web', 'auth:sanctum'],` to:

```php
        // No 'web' — this is a stateless API endpoint. Adding the web group
        // would pull in session state and CSRF verification.
        'middleware' => ['auth:sanctum'],
```

- [ ] **Step 6: Delete the shadow server and its provider**

```bash
rm app/Mcp/LsmServer.php app/Providers/McpServiceProvider.php
```

Then remove the `App\Providers\McpServiceProvider::class,` line from `bootstrap/providers.php`, leaving:

```php
<?php

return [
    App\Providers\AppServiceProvider::class,
];
```

Finally, in `tests/Feature/Mcp/ManagerMembershipToolsTest.php`, change line 3 from `use App\Mcp\LsmServer;` to:

```php
use App\Mcp\Servers\LsmServer;
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Mcp/`
Expected: PASS — `McpServerRegistrationTest` green, `LsmServerTest` and `ManagerMembershipToolsTest` still green.

Then confirm there is still exactly one route pair:

Run: `php artisan route:list --path=mcp`
Expected: two rows, `GET|HEAD mcp` and `POST mcp`.

- [ ] **Step 8: Commit**

```bash
git add app/Mcp routes/mcp.php config/mcp.php bootstrap/providers.php app/Providers tests/Feature/Mcp
git commit -m "fix: consolidate MCP onto one registered server with auth middleware

McpServiceProvider and routes/mcp.php both registered Mcp::web at /mcp. The
provider's registration carried no middleware and exposed all 44 tools; it was
shadowed only by route-loading order. Delete the provider and its duplicate
server class, register all 44 tools on the surviving auth:sanctum-protected
server, and drop 'web' from the MCP middleware config."
```

---

## Task 2: Per-token expiry — remove the global cap, backfill existing rows

The riskiest change in the feature, done alone. `Guard.php:148` ANDs the global cap with `expires_at`, so a long-lived token is impossible while `expiration` is set — and the moment it is unset, every legacy row with `expires_at IS NULL` becomes immortal.

**Files:**
- Modify: `config/sanctum.php:50`
- Modify: `app/Http/Controllers/Api/V1/AuthController.php:69,136`
- Modify: `app/Http/Controllers/Api/V1/TwoFactorController.php:158`
- Create: `database/migrations/2026_08_04_120000_backfill_session_token_expiry.php`
- Test: `tests/Feature/TokenExpirationTest.php` (rewritten)

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces: `config('sanctum.session_expiration')` — integer minutes, default 480. Task 5's controller uses `config('sanctum.expiration')` being `null` as its precondition for honouring long `expires_at` values.

- [ ] **Step 1: Write the failing test**

Replace the whole contents of `tests/Feature/TokenExpirationTest.php`:

```php
<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('the global sanctum cap is off so per-token expiry governs', function () {
    // Guard::isValidAccessToken ANDs the global cap with expires_at, so any
    // non-null global expiration would silently cap long-lived tokens.
    expect(config('sanctum.expiration'))->toBeNull();
    expect(config('sanctum.session_expiration'))->toBe(480);
});

test('a fresh sanctum token authenticates', function () {
    $user = User::factory()->create();
    $fresh = $user->createToken('fresh', ['*'], now()->addMinutes(480));

    $this->withToken($fresh->plainTextToken)->getJson('/api/v1/user')->assertOk();
});

test('a login token still works at 7 hours 59 minutes', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password123'),
        'two_factor_confirmed_at' => null,
        'two_factor_email_enabled' => false,
    ]);

    $token = $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'password123',
        'device_name' => 'test',
    ])->assertOk()->json('data.token');

    $this->travel(479)->minutes();

    $this->withToken($token)->getJson('/api/v1/user')->assertOk();
});

test('a login token is rejected at 8 hours and 1 minute', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password123'),
        'two_factor_confirmed_at' => null,
        'two_factor_email_enabled' => false,
    ]);

    $token = $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'password123',
        'device_name' => 'test',
    ])->assertOk()->json('data.token');

    $this->travel(481)->minutes();

    $this->withToken($token)->getJson('/api/v1/user')->assertStatus(401);
});

test('refresh-token issues a replacement that also expires in 8 hours', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test', ['*'], now()->addMinutes(480))->plainTextToken;

    $newToken = $this->withToken($token)
        ->postJson('/api/v1/refresh-token')
        ->assertOk()
        ->json('data.token');

    expect($newToken)->toBeString();
    $this->withToken($newToken)->getJson('/api/v1/user')->assertOk();

    $this->travel(481)->minutes();
    $this->withToken($newToken)->getJson('/api/v1/user')->assertStatus(401);
});

test('a long-lived integration token survives well past 8 hours', function () {
    $user = User::factory()->create();
    $token = $user->createToken('integration', ['mcp:read'], now()->addYear())->plainTextToken;

    $this->travel(24)->hours();

    $this->withToken($token)->getJson('/api/v1/user')->assertOk();
});

test('the backfill migration bounds legacy tokens that have no expiry', function () {
    $user = User::factory()->create();
    $legacy = $user->createToken('legacy', ['*']);

    // Simulate a pre-migration row: issued three days ago, no expires_at.
    $legacy->accessToken->forceFill([
        'expires_at' => null,
        'created_at' => now()->subDays(3),
    ])->save();

    // A token with no expiry and no global cap would live forever.
    $this->withToken($legacy->plainTextToken)->getJson('/api/v1/user')->assertOk();

    $migration = require database_path('migrations/2026_08_04_120000_backfill_session_token_expiry.php');
    $migration->up();

    expect($legacy->accessToken->fresh()->expires_at)->not->toBeNull();
    $this->withToken($legacy->plainTextToken)->getJson('/api/v1/user')->assertStatus(401);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/TokenExpirationTest.php`
Expected: FAIL — the first test reports `config('sanctum.expiration')` is `480`, not null; the backfill test fails on a missing migration file.

- [ ] **Step 3: Turn off the global cap**

In `config/sanctum.php`, replace line 50 with:

```php
    /*
     * Guard::isValidAccessToken ANDs this global cap with each token's own
     * expires_at, so a non-null value here silently caps every token no matter
     * what expires_at says. It must stay null for integration tokens to work.
     * Session lifetime now lives in session_expiration below, and every
     * createToken() call site passes an explicit expires_at.
     */
    'expiration' => null,

    /*
     * Lifetime, in minutes, of the tokens issued by login, two-factor verify
     * and refresh. Still driven by SANCTUM_EXPIRATION so deployments that set
     * it keep working.
     */
    'session_expiration' => (int) env('SANCTUM_EXPIRATION', 480), // 8 hours
```

- [ ] **Step 4: Give every session mint site an explicit expiry**

`app/Http/Controllers/Api/V1/AuthController.php:69` — in `login()`:

```php
        // Create a new token for the device. The expiry is explicit because the
        // global sanctum cap is off; see config/sanctum.php.
        $deviceName = $request->device_name ?? 'mobile-app';
        $token = $user->createToken(
            $deviceName,
            ['*'],
            now()->addMinutes(config('sanctum.session_expiration', 480))
        )->plainTextToken;
```

`app/Http/Controllers/Api/V1/AuthController.php:136` — in `refresh()`:

```php
        // Create new token
        $token = $user->createToken(
            $deviceName,
            ['*'],
            now()->addMinutes(config('sanctum.session_expiration', 480))
        )->plainTextToken;
```

`app/Http/Controllers/Api/V1/TwoFactorController.php:158` — in `verify()`:

```php
        $deviceName = $request->device_name ?? 'web-browser';
        $token = $user->createToken(
            $deviceName,
            ['*'],
            now()->addMinutes(config('sanctum.session_expiration', 480))
        )->plainTextToken;
```

`TeamController.php:105` is `app('auth.password.broker')->createToken($user)` — a password-reset token, unrelated. Leave it.

- [ ] **Step 5: Write the backfill migration**

Create `database/migrations/2026_08_04_120000_backfill_session_token_expiry.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bound every pre-existing token that has no expires_at.
 *
 * Until now config('sanctum.expiration') capped all tokens at 8 hours, so rows
 * were written with expires_at NULL and were expired by the global rule. That
 * rule is gone as of this commit, which would make every one of those rows
 * immortal. Give each the expiry it effectively already had.
 *
 * Deliberately loops in PHP rather than using SQL date arithmetic: the test
 * suite runs on SQLite and production on MySQL, and their interval syntax
 * differs. The table is small.
 */
return new class extends Migration
{
    public function up(): void
    {
        $minutes = (int) config('sanctum.session_expiration', 480);

        DB::table('personal_access_tokens')
            ->whereNull('expires_at')
            ->orderBy('id')
            ->chunkById(500, function ($tokens) use ($minutes) {
                foreach ($tokens as $token) {
                    $issuedAt = $token->created_at
                        ? Carbon::parse($token->created_at)
                        : Carbon::now();

                    DB::table('personal_access_tokens')
                        ->where('id', $token->id)
                        ->update(['expires_at' => $issuedAt->addMinutes($minutes)]);
                }
            });
    }

    public function down(): void
    {
        // Irreversible by design. Clearing expires_at would resurrect tokens
        // that are meant to be dead.
    }
};
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/TokenExpirationTest.php`
Expected: PASS, 7 tests.

Then run the auth suite to prove session behaviour is unchanged:

Run: `php artisan test tests/Feature/Auth/ tests/Feature/Mcp/`
Expected: PASS, zero failures. This is the change most likely to break session auth for the whole team — if anything in `tests/Feature/Auth/` goes red, stop and fix it before committing.

- [ ] **Step 7: Commit**

```bash
git add config/sanctum.php app/Http/Controllers/Api/V1/AuthController.php \
        app/Http/Controllers/Api/V1/TwoFactorController.php \
        database/migrations/2026_08_04_120000_backfill_session_token_expiry.php \
        tests/Feature/TokenExpirationTest.php
git commit -m "fix: move token expiry from a global cap to per-token expires_at

Sanctum's Guard ANDs config('sanctum.expiration') with each token's expires_at,
so the global 8h cap made long-lived tokens impossible. Set it to null, pass an
explicit 8h expires_at at all three session mint sites via a new
sanctum.session_expiration, and backfill every legacy row whose expires_at is
null so removing the cap does not make them immortal."
```

---

## Task 3: Token type and IP audit columns

**Files:**
- Create: `database/migrations/2026_08_04_120100_add_type_and_ip_to_personal_access_tokens.php`
- Create: `app/Listeners/RecordTokenUsageIp.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/IntegrationTokenControllerTest.php` (first two tests only)

**Interfaces:**
- Consumes: nothing.
- Produces: `personal_access_tokens.type` (string, default `'session'`), `.created_from_ip` (nullable string), `.last_used_ip` (nullable string). `User::integrationTokens(): MorphMany` returning only `type = 'integration'` rows. Tasks 4-6 rely on all four.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/IntegrationTokenControllerTest.php` with just these two tests for now; Task 5 appends to this file.

```php
<?php

use App\Models\User;
use Illuminate\Support\Facades\Schema;

test('personal access tokens carry a type and IP audit columns', function () {
    expect(Schema::hasColumns('personal_access_tokens', [
        'type', 'created_from_ip', 'last_used_ip',
    ]))->toBeTrue();

    $user = User::factory()->create();
    $token = $user->createToken('default', ['*'], now()->addMinutes(480));

    expect($token->accessToken->fresh()->type)->toBe('session');
});

test('authenticating with a token records the calling IP once', function () {
    $user = User::factory()->create();
    $token = $user->createToken('integration', ['*'], now()->addYear());
    $token->accessToken->forceFill(['type' => 'integration'])->save();

    $this->withToken($token->plainTextToken)
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->getJson('/api/v1/user')
        ->assertOk();

    expect($token->accessToken->fresh()->last_used_ip)->toBe('203.0.113.7');
});

test('the user model exposes only integration tokens through the relation', function () {
    $user = User::factory()->create();
    $user->createToken('session-one', ['*'], now()->addMinutes(480));

    $integration = $user->createToken('integration-one', ['mcp:read'], now()->addYear());
    $integration->accessToken->forceFill(['type' => 'integration'])->save();

    expect($user->integrationTokens()->pluck('name')->all())->toBe(['integration-one']);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/IntegrationTokenControllerTest.php`
Expected: FAIL — `Schema::hasColumns` returns false.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_08_04_120100_add_type_and_ip_to_personal_access_tokens.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            // 'session' (issued by login/2FA/refresh) or 'integration'
            // (issued by IntegrationTokenController). The controller filters on
            // this so revoking an integration never logs anybody out.
            $table->string('type', 20)->default('session')->after('abilities')->index();
            $table->string('created_from_ip', 45)->nullable()->after('type');
            $table->string('last_used_ip', 45)->nullable()->after('last_used_at');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn(['type', 'created_from_ip', 'last_used_ip']);
        });
    }
};
```

45 characters holds an IPv6 address with an IPv4-mapped suffix, the longest form that can arrive here.

- [ ] **Step 4: Write the listener**

Create `app/Listeners/RecordTokenUsageIp.php`:

```php
<?php

namespace App\Listeners;

use Illuminate\Http\Request;
use Laravel\Sanctum\Events\TokenAuthenticated;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Record the IP a token is being used from.
 *
 * TokenAuthenticated fires once per authenticated request, so this writes only
 * when the IP actually changed — otherwise every request would cost an UPDATE.
 */
class RecordTokenUsageIp
{
    public function __construct(private Request $request) {}

    public function handle(TokenAuthenticated $event): void
    {
        $token = $event->token;

        // Cookie/session auth yields a TransientToken with nothing to persist.
        if (! $token instanceof PersonalAccessToken) {
            return;
        }

        $ip = $this->request->ip();

        if ($ip === null || $token->last_used_ip === $ip) {
            return;
        }

        $token->forceFill(['last_used_ip' => $ip])->saveQuietly();
    }
}
```

- [ ] **Step 5: Wire the listener and the relation**

In `app/Providers/AppServiceProvider.php`, add to the `boot()` method (and the matching `use` statements at the top of the file):

```php
        Event::listen(TokenAuthenticated::class, RecordTokenUsageIp::class);
```

```php
use App\Listeners\RecordTokenUsageIp;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Events\TokenAuthenticated;
```

In `app/Models/User.php`, add the relation next to the other relationship methods:

```php
    /**
     * Long-lived tokens minted for external integrations (MCP clients).
     * Deliberately excludes session tokens so the management UI can never
     * list or revoke the caller's own login.
     */
    public function integrationTokens(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->tokens()
            ->where('type', 'integration')
            ->orderByDesc('created_at');
    }
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/IntegrationTokenControllerTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_04_120100_add_type_and_ip_to_personal_access_tokens.php \
        app/Listeners/RecordTokenUsageIp.php app/Providers/AppServiceProvider.php \
        app/Models/User.php tests/Feature/IntegrationTokenControllerTest.php
git commit -m "feat: add type and IP audit columns to personal access tokens"
```

---

## Task 4: The scope trait, and the read-scope primitives

**Files:**
- Create: `app/Mcp/Concerns/HasRequiredScope.php`
- Modify: `tests/Pest.php`
- Modify: `tests/Feature/Mcp/ManagerMembershipToolsTest.php` (nine `actingAs` sites)
- Modify: 13 read tools, 6 resources, 2 prompts
- Test: `tests/Feature/Mcp/ScopeEnforcementTest.php`

**Interfaces:**
- Consumes: `App\Mcp\Servers\LsmServer` from Task 1.
- Produces:
  - `App\Mcp\Concerns\HasRequiredScope` — `public function shouldRegister(): bool`, `protected function assertScope(): ?\Laravel\Mcp\Response`, `protected string $requiredScope` (default `'mcp:read'`).
  - `actingWithScopes(User $user, array $scopes): User` in `tests/Pest.php` — attaches a real `PersonalAccessToken` and returns the user. Tasks 5-6 do not use it; Task 5's tests use HTTP tokens directly.

- [ ] **Step 1: Write the failing test**

First add the helper to `tests/Pest.php`, replacing the placeholder `something()` function at the bottom:

```php
/**
 * Attach a real personal access token to a user so tokenCan() works.
 *
 * Do not use Sanctum::actingAs() for scope tests: it builds a Mockery mock that
 * only stubs can() for the abilities you list, so asserting that some *other*
 * ability is denied raises a Mockery error instead of returning false.
 */
function actingWithScopes(\App\Models\User $user, array $scopes): \App\Models\User
{
    $token = $user->createToken('test-token', $scopes, now()->addMinutes(60));

    return $user->withAccessToken($token->accessToken);
}
```

Then create `tests/Feature/Mcp/ScopeEnforcementTest.php`:

```php
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
    $user = actingWithScopes(User::factory()->create(['role' => 'admin']), ['mcp:write']);

    LsmServer::actingAs($user)
        ->tool(GetDashboardTool::class, [])
        ->assertSee('mcp:read');
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

    $names = collect($this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Mcp/ScopeEnforcementTest.php`
Expected: FAIL — nothing enforces scopes yet, so the "hidden" assertions all fail.

- [ ] **Step 3: Write the trait**

Create `app/Mcp/Concerns/HasRequiredScope.php`:

```php
<?php

namespace App\Mcp\Concerns;

use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Response;

/**
 * Gate an MCP primitive on a token ability.
 *
 * shouldRegister() is called from Primitive::eligibleForRegistration(), so an
 * out-of-scope primitive never appears in tools/list, resources/list or
 * prompts/list. A client that cannot see a tool will not try it, reason about
 * it, or explain its absence — which is the point.
 *
 * That is ergonomics, not security. assertScope() at the top of handle() is the
 * boundary: a client that skips listing and calls the method directly is still
 * refused.
 *
 * Abilities intersect with the caller's role, they never widen it. Every tool
 * keeps its own Auth::user() role checks.
 */
trait HasRequiredScope
{
    /**
     * The token ability required to see and call this primitive.
     */
    protected string $requiredScope = 'mcp:read';

    public function shouldRegister(): bool
    {
        return $this->tokenHasRequiredScope();
    }

    /**
     * A wildcard token ('*') satisfies every scope, which is what keeps the web
     * app and any pre-existing token working unchanged.
     */
    protected function tokenHasRequiredScope(): bool
    {
        return Auth::user()?->tokenCan($this->requiredScope) ?? false;
    }

    /**
     * Returns null when the call is permitted, or the error to return when it
     * is not. Callers use: if ($denied = $this->assertScope()) return $denied;
     */
    protected function assertScope(): ?Response
    {
        if ($this->tokenHasRequiredScope()) {
            return null;
        }

        return Response::error(
            "This token lacks the required scope: {$this->requiredScope}. "
            . 'Create a token with that scope under Profil → API & Integrationen.'
        );
    }
}
```

- [ ] **Step 4: Apply it to the 13 read tools**

For each of `GetDashboardTool`, `GetProjectTool`, `ListProjectsTool`, `ListTodosTool`, `ListTodoTemplatesTool`, `ListTimeEntriesTool`, `ListTeamTool`, `GetTeamWorkloadTool`, `GetTeamAvailabilityTool`, `ListInvoicesTool`, `ListSupportTicketsTool`, `ListTagsTool`, `ListResourcesTool` in `app/Mcp/Tools/`:

Add the import below the existing `use` block:

```php
use App\Mcp\Concerns\HasRequiredScope;
```

Add the trait and the scope as the first lines of the class body, before `$name`:

```php
class GetDashboardTool extends Tool
{
    use HasRequiredScope;

    protected string $requiredScope = 'mcp:read';

    protected string $name = 'get-dashboard';
```

And make `handle()` check first:

```php
    public function handle(Request $request): Response
    {
        if ($denied = $this->assertScope()) {
            return $denied;
        }

        $user = Auth::user();
        // … existing body unchanged
```

`mcp:read` is the trait's default, so the explicit `protected string $requiredScope` line is redundant here — write it anyway. Every primitive stating its own scope means a reader never has to know what the default is, and a future change to the default cannot silently reclassify 13 tools.

- [ ] **Step 5: Apply it to the 6 resources and 2 prompts**

Same three edits in `app/Mcp/Resources/DashboardResource.php`, `MyTodosResource.php`, `ProjectsResource.php`, `SitesAtRiskResource.php`, `TimeTodayResource.php`, `VaultResource.php` and `app/Mcp/Prompts/MorningBriefingPrompt.php`, `WeeklyStatusPrompt.php`. All eight take `mcp:read`.

The prompts return static text and do not touch `Auth::user()`, but they still get the `assertScope()` guard — they describe the tool surface, and a token that cannot use the tools should not be handed the playbook.

- [ ] **Step 6: Give the existing MCP tests a real token**

`LsmServer::actingAs()` sets the guard user but no access token, so `tokenCan()` is false and every tool would now be hidden from `tests/Feature/Mcp/ManagerMembershipToolsTest.php`. At each of its nine call sites, wrap the user. For example, at line 31:

```php
    LsmServer::actingAs(actingWithScopes($manager, ['*']))
        ->tool(CompleteTodoTool::class, ['todo_id' => $todo->id])
        ->assertOk()
        ->assertSee('Todo Completed');
```

Apply the same wrapping at lines 46, 60, 78, 93, 107, 128, 153 and 176, using whichever user that test already passes (`$manager`, `$stranger`, `$demoted`, `$admin`). `['*']` is right here — these tests are about role scoping, not abilities, and a wildcard keeps them testing exactly what they tested before.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Mcp/`
Expected: PASS. `ScopeEnforcementTest` will still fail its `toHaveCount(44)` assertion for the wildcard token only if some tool is missing the trait — at this point all 44 are registered but only 21 primitives are gated, and a wildcard token sees everything either way, so this should be green.

- [ ] **Step 8: Commit**

```bash
git add app/Mcp/Concerns/HasRequiredScope.php app/Mcp/Tools app/Mcp/Resources \
        app/Mcp/Prompts tests/Pest.php tests/Feature/Mcp
git commit -m "feat: gate MCP read primitives on the mcp:read token ability

shouldRegister() hides out-of-scope primitives from tools/list, resources/list
and prompts/list; assertScope() in handle() is the real boundary for clients
that skip listing. Wildcard tokens satisfy every scope, so existing sessions are
unaffected."
```

---

## Task 5: The remaining three scopes

**Files:**
- Modify: 14 write tools, 13 wp tools, 4 wp-destructive tools in `app/Mcp/Tools/`
- Modify: `tests/Feature/Mcp/ScopeEnforcementTest.php`

**Interfaces:**
- Consumes: `HasRequiredScope` from Task 4.
- Produces: every one of the 44 tools declares a `$requiredScope`. Task 6's validation allow-list must match these four strings exactly.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Mcp/ScopeEnforcementTest.php`:

```php
test('every registered tool declares a required scope', function () {
    $reflection = new ReflectionClass(LsmServer::class);
    $server = $reflection->newInstanceWithoutConstructor();
    $p = $reflection->getProperty('tools');
    $p->setAccessible(true);

    $valid = ['mcp:read', 'mcp:write', 'mcp:wp', 'mcp:wp-destructive'];

    foreach ($p->getValue($server) as $class) {
        $tool = new ReflectionClass($class);

        expect($tool->hasProperty('requiredScope'))
            ->toBeTrue("{$class} does not declare requiredScope");

        $property = $tool->getProperty('requiredScope');
        $property->setAccessible(true);
        $scope = $property->getValue($tool->newInstanceWithoutConstructor());

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
            $property = (new ReflectionClass($class))->getProperty('requiredScope');
            $property->setAccessible(true);

            return $property->getValue((new ReflectionClass($class))->newInstanceWithoutConstructor());
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
    $user = actingWithScopes(User::factory()->create(['role' => 'admin']), ['mcp:read']);

    LsmServer::actingAs($user)
        ->tool(\App\Mcp\Tools\WpRestoreBackupTool::class, ['backup_id' => 1])
        ->assertSee('mcp:wp-destructive');
});

test('a wp token does not carry destructive access', function () {
    $user = actingWithScopes(User::factory()->create(['role' => 'admin']), ['mcp:wp']);

    LsmServer::actingAs($user)
        ->tool(\App\Mcp\Tools\WpEmergencyTool::class, ['project_id' => 1, 'action' => 'status'])
        ->assertSee('mcp:wp-destructive');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Mcp/ScopeEnforcementTest.php`
Expected: FAIL — 31 tools have no `requiredScope`, and the count map shows `mcp:read => 13` with the rest missing.

- [ ] **Step 3: Declare `mcp:write` on the 14 write tools**

`CreateTodoTool`, `UpdateTodoTool`, `CompleteTodoTool`, `DeleteTodoTool`, `ApplyTodoTemplateTool`, `CreateTimeEntryTool`, `StartTimerTool`, `StopTimerTool`, `CreateProjectTool`, `UpdateProjectTool`, `BulkAssignDevelopersTool`, `BulkAssignManagersTool`, `CreateSupportTicketTool`, `GeneratePdfTool`.

Same three edits as Task 4, Step 4 — import, trait + scope, `handle()` guard — with:

```php
    use HasRequiredScope;

    protected string $requiredScope = 'mcp:write';
```

- [ ] **Step 4: Declare `mcp:wp` on the 13 reversible WordPress tools**

`WpLoginTool`, `WpCheckConnectionsTool`, `WpClearCacheTool`, `WpEnableMaintenanceTool`, `WpDisableMaintenanceTool`, `WpGetUpdatesTool`, `WpUpdatePluginsTool`, `WpUpdateCoreTool`, `WpOptimizeDatabaseTool`, `WpCreateBackupTool`, `WpListBackupsTool`, `WpGetPhpErrorsTool`, `WpClearPhpErrorsTool`.

```php
    use HasRequiredScope;

    protected string $requiredScope = 'mcp:wp';
```

- [ ] **Step 5: Declare `mcp:wp-destructive` on the 4 high-blast-radius tools**

`WpEmergencyTool`, `BulkWpActionTool`, `WpRestoreBackupTool`, `WpDownloadBackupTool`.

```php
    use HasRequiredScope;

    protected string $requiredScope = 'mcp:wp-destructive';
```

The `assertScope()` guard goes **above** each tool's existing role check, so a token that lacks the scope gets the scope message rather than leaking whether the caller's role would have been sufficient. In `WpEmergencyTool::handle()` that means inserting before line 42:

```php
    public function handle(Request $request): Response
    {
        if ($denied = $this->assertScope()) {
            return $denied;
        }

        $user = Auth::user();

        // Only admins and managers can use emergency tools
        if (!in_array($user->role, ['admin', 'manager'])) {
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Mcp/`
Expected: PASS, including the 13/14/13/4 count map.

- [ ] **Step 7: Commit**

```bash
git add app/Mcp/Tools tests/Feature/Mcp/ScopeEnforcementTest.php
git commit -m "feat: declare write, wp and wp-destructive scopes on the remaining tools

All 44 MCP tools now carry a required scope, grouped by blast radius rather
than feature area. The scope check runs above each tool's existing role check
so a scope failure never reveals whether the role would have passed."
```

---

## Task 6: The integration token API

**Files:**
- Create: `app/Http/Requests/StoreIntegrationTokenRequest.php`
- Create: `app/Http/Controllers/Api/V1/IntegrationTokenController.php`
- Create: `app/Http/Resources/IntegrationTokenResource.php`
- Modify: `routes/api.php` (inside the group at line 111)
- Test: `tests/Feature/IntegrationTokenControllerTest.php` (appended)

**Interfaces:**
- Consumes: the `type`, `created_from_ip`, `last_used_ip` columns and `User::integrationTokens()` from Task 3; the four scope strings from Task 5.
- Produces: `GET|POST /api/v1/integration-tokens`, `DELETE /api/v1/integration-tokens/{id}`, route names `api.v1.integration-tokens.index|store|destroy`. The JSON shapes below are what Task 7 types in TypeScript.

**Response shapes.** Index returns `{ success: true, data: IntegrationTokenResource[] }` where each element is:

```json
{
  "id": 42,
  "name": "Claude Code — MacBook",
  "scopes": ["mcp:read", "mcp:write"],
  "created_at": "2026-08-04T18:10:00.000000Z",
  "expires_at": "2026-11-02T18:10:00.000000Z",
  "last_used_at": null,
  "last_used_ip": null,
  "created_from_ip": "203.0.113.7",
  "is_expired": false
}
```

Store returns `{ success: true, message: "…", data: { token: "42|plaintext…", integration_token: { …the object above… } } }`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/IntegrationTokenControllerTest.php`:

```php
test('a user can mint an integration token with the right password', function () {
    $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('secret-pw')]);

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/integration-tokens', [
        'name' => 'Claude Code — MacBook',
        'scopes' => ['mcp:read', 'mcp:write'],
        'expires_in' => '90d',
        'password' => 'secret-pw',
    ])->assertCreated();

    expect($response->json('data.token'))->toBeString();
    expect($response->json('data.integration_token.scopes'))->toBe(['mcp:read', 'mcp:write']);

    $row = $user->integrationTokens()->first();
    expect($row->name)->toBe('Claude Code — MacBook');
    expect($row->abilities)->toBe(['mcp:read', 'mcp:write']);
    // A window, not an exact diff: Carbon 3's diffInDays is signed and returns
    // a float, so an equality assertion here would be quietly fragile.
    expect($row->expires_at->isBetween(now()->addDays(89), now()->addDays(91)))->toBeTrue();
});

test('the minted token actually authenticates and outlives a session', function () {
    $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('secret-pw')]);

    $token = $this->actingAs($user, 'sanctum')->postJson('/api/v1/integration-tokens', [
        'name' => 'Long lived',
        'scopes' => ['mcp:read'],
        'expires_in' => '1y',
        'password' => 'secret-pw',
    ])->assertCreated()->json('data.token');

    $this->travel(30)->days();

    $this->withToken($token)->getJson('/api/v1/user')->assertOk();
});

test('a wrong password mints nothing', function () {
    $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('secret-pw')]);

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/integration-tokens', [
        'name' => 'Nope',
        'scopes' => ['mcp:read'],
        'expires_in' => '90d',
        'password' => 'wrong-pw',
    ])->assertStatus(422)->assertJsonValidationErrors('password');

    expect($user->integrationTokens()->count())->toBe(0);
});

test('a developer cannot mint a destructive scope', function () {
    $user = User::factory()->create(['role' => 'developer', 'password' => Hash::make('secret-pw')]);

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/integration-tokens', [
        'name' => 'Too much',
        'scopes' => ['mcp:read', 'mcp:wp-destructive'],
        'expires_in' => '90d',
        'password' => 'secret-pw',
    ])->assertStatus(422)->assertJsonValidationErrors('scopes');

    expect($user->integrationTokens()->count())->toBe(0);
});

test('a manager may mint a destructive scope', function () {
    $user = User::factory()->create(['role' => 'manager', 'password' => Hash::make('secret-pw')]);

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/integration-tokens', [
        'name' => 'Manager token',
        'scopes' => ['mcp:wp-destructive'],
        'expires_in' => '30d',
        'password' => 'secret-pw',
    ])->assertCreated();
});

test('an unknown scope is rejected', function () {
    $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('secret-pw')]);

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/integration-tokens', [
        'name' => 'Bad scope',
        'scopes' => ['mcp:read', 'mcp:everything'],
        'expires_in' => '90d',
        'password' => 'secret-pw',
    ])->assertStatus(422)->assertJsonValidationErrors('scopes.1');
});

test('the index never returns a token value and never lists session tokens', function () {
    $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('secret-pw')]);
    $user->createToken('a-session-token', ['*'], now()->addMinutes(480));

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/integration-tokens', [
        'name' => 'Visible one',
        'scopes' => ['mcp:read'],
        'expires_in' => '90d',
        'password' => 'secret-pw',
    ])->assertCreated();

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/integration-tokens')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.name'))->toBe('Visible one');
    expect($response->json('data.0'))->not->toHaveKey('token');
    expect($response->json('data.0'))->not->toHaveKey('plain_text_token');
});

test('one user cannot see or revoke another user\'s tokens', function () {
    $owner = User::factory()->create(['role' => 'admin', 'password' => Hash::make('secret-pw')]);
    $stranger = User::factory()->create(['role' => 'admin']);

    $id = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/integration-tokens', [
        'name' => 'Private',
        'scopes' => ['mcp:read'],
        'expires_in' => '90d',
        'password' => 'secret-pw',
    ])->assertCreated()->json('data.integration_token.id');

    $this->actingAs($stranger, 'sanctum')
        ->getJson('/api/v1/integration-tokens')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs($stranger, 'sanctum')
        ->deleteJson("/api/v1/integration-tokens/{$id}")
        ->assertStatus(404);

    expect($owner->integrationTokens()->count())->toBe(1);
});

test('revoking an integration token leaves the session token working', function () {
    $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('secret-pw')]);
    $session = $user->createToken('session', ['*'], now()->addMinutes(480))->plainTextToken;

    $created = $this->withToken($session)->postJson('/api/v1/integration-tokens', [
        'name' => 'Disposable',
        'scopes' => ['mcp:read'],
        'expires_in' => '30d',
        'password' => 'secret-pw',
    ])->assertCreated();

    $integrationToken = $created->json('data.token');
    $id = $created->json('data.integration_token.id');

    $this->withToken($session)
        ->deleteJson("/api/v1/integration-tokens/{$id}")
        ->assertOk();

    $this->withToken($integrationToken)->getJson('/api/v1/user')->assertStatus(401);
    $this->withToken($session)->getJson('/api/v1/user')->assertOk();
});

test('a never-expiring token is stored with a null expiry', function () {
    $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('secret-pw')]);

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/integration-tokens', [
        'name' => 'Forever',
        'scopes' => ['mcp:read'],
        'expires_in' => 'never',
        'password' => 'secret-pw',
    ])->assertCreated();

    expect($user->integrationTokens()->first()->expires_at)->toBeNull();
});
```

Add `use Illuminate\Support\Facades\Hash;` to the file's imports.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/IntegrationTokenControllerTest.php`
Expected: FAIL — 404 on every route.

- [ ] **Step 3: Write the form request**

Create `app/Http/Requests/StoreIntegrationTokenRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreIntegrationTokenRequest extends FormRequest
{
    /**
     * Every MCP ability, in the order the UI shows them.
     */
    public const SCOPES = ['mcp:read', 'mcp:write', 'mcp:wp', 'mcp:wp-destructive'];

    /**
     * Abilities a role may never mint. mcp:wp-destructive is gated at the tool
     * level to admins and managers (WpEmergencyTool, BulkWpActionTool,
     * WpRestoreBackupTool), so a developer's token carrying it would be a lie.
     */
    public const ROLE_SCOPES = [
        'admin' => ['mcp:read', 'mcp:write', 'mcp:wp', 'mcp:wp-destructive'],
        'manager' => ['mcp:read', 'mcp:write', 'mcp:wp', 'mcp:wp-destructive'],
        'developer' => ['mcp:read', 'mcp:write', 'mcp:wp'],
    ];

    public const EXPIRY_OPTIONS = ['30d', '90d', '1y', 'never'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(self::SCOPES)],
            'expires_in' => ['required', Rule::in(self::EXPIRY_OPTIONS)],
            'password' => ['required', 'string'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                // Step-up: a stolen session must not be enough to mint a
                // long-lived, portfolio-wide credential.
                if (! Hash::check($this->input('password', ''), $this->user()->password)) {
                    $validator->errors()->add('password', 'The password is incorrect.');
                }

                $allowed = self::ROLE_SCOPES[$this->user()->role] ?? self::ROLE_SCOPES['developer'];

                foreach ((array) $this->input('scopes', []) as $scope) {
                    if (in_array($scope, self::SCOPES, true) && ! in_array($scope, $allowed, true)) {
                        $validator->errors()->add(
                            'scopes',
                            "Your role cannot issue a token with the scope {$scope}."
                        );
                    }
                }
            },
        ];
    }

    /**
     * Absolute expiry for the requested option, or null for 'never'.
     */
    public function expiresAt(): ?\Illuminate\Support\Carbon
    {
        return match ($this->input('expires_in')) {
            '30d' => now()->addDays(30),
            '90d' => now()->addDays(90),
            '1y' => now()->addYear(),
            default => null,
        };
    }

    /**
     * Deduplicated, canonically ordered scopes.
     */
    public function scopes(): array
    {
        return array_values(array_intersect(self::SCOPES, (array) $this->input('scopes', [])));
    }
}
```

- [ ] **Step 4: Write the API resource**

Create `app/Http/Resources/IntegrationTokenResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The safe projection of a personal access token.
 *
 * Deliberately hand-listed rather than built from the model's attributes: the
 * row holds a hashed token value, and an accidental ->toArray() would ship it.
 *
 * @mixin \Laravel\Sanctum\PersonalAccessToken
 */
class IntegrationTokenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'scopes' => $this->abilities ?? [],
            'created_at' => $this->created_at,
            'expires_at' => $this->expires_at,
            'last_used_at' => $this->last_used_at,
            'last_used_ip' => $this->last_used_ip,
            'created_from_ip' => $this->created_from_ip,
            'is_expired' => $this->expires_at !== null && $this->expires_at->isPast(),
        ];
    }
}
```

- [ ] **Step 5: Write the controller**

Create `app/Http/Controllers/Api/V1/IntegrationTokenController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreIntegrationTokenRequest;
use App\Http\Resources\IntegrationTokenResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Long-lived, scoped bearer tokens for external integrations (MCP clients).
 *
 * Every query filters on type = 'integration' and the caller's own id, so a
 * user can neither reach another user's tokens nor revoke their own login.
 */
class IntegrationTokenController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return $this->successResponse(
            IntegrationTokenResource::collection($request->user()->integrationTokens()->get())
        );
    }

    public function store(StoreIntegrationTokenRequest $request): JsonResponse
    {
        $user = $request->user();

        $token = $user->createToken(
            $request->input('name'),
            $request->scopes(),
            $request->expiresAt()
        );

        $token->accessToken->forceFill([
            'type' => 'integration',
            'created_from_ip' => $request->ip(),
        ])->save();

        // The only place a plaintext token is ever returned.
        return $this->createdResponse([
            'token' => $token->plainTextToken,
            'integration_token' => new IntegrationTokenResource($token->accessToken->fresh()),
        ], 'Integration token created. Copy it now — it will not be shown again.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $token = $request->user()->integrationTokens()->find($id);

        // 404 rather than 403: a stranger learns nothing about whether the id exists.
        if (! $token) {
            return $this->notFoundResponse('Integration token not found.');
        }

        $token->delete();

        return $this->successResponse(null, 'Integration token revoked.');
    }
}
```

- [ ] **Step 6: Register the routes**

In `routes/api.php`, inside the `Route::middleware(['auth:sanctum', \App\Http\Middleware\EnsureTwoFactorEnrolled::class])->group(...)` that opens at line 111, add:

```php
        // INTEGRATION TOKENS (long-lived scoped bearer tokens for MCP clients).
        // Creation is throttled because it takes the account password.
        Route::get('/integration-tokens', [V1\IntegrationTokenController::class, 'index'])
            ->name('integration-tokens.index');
        Route::post('/integration-tokens', [V1\IntegrationTokenController::class, 'store'])
            ->middleware('throttle:5,1')
            ->name('integration-tokens.store');
        Route::delete('/integration-tokens/{id}', [V1\IntegrationTokenController::class, 'destroy'])
            ->whereNumber('id')
            ->name('integration-tokens.destroy');
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/IntegrationTokenControllerTest.php`
Expected: PASS, 13 tests.

Then the whole backend suite:

Run: `php artisan test`
Expected: PASS, zero failures. The baseline before this branch was 263 passed / 2 skipped / 0 failed; the count should now be higher and the failure count still zero.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Api/V1/IntegrationTokenController.php \
        app/Http/Requests/StoreIntegrationTokenRequest.php \
        app/Http/Resources/IntegrationTokenResource.php \
        routes/api.php tests/Feature/IntegrationTokenControllerTest.php
git commit -m "feat: integration token API with step-up auth and role-gated scopes

Create returns the plaintext token exactly once and requires the account
password, so a borrowed session cannot silently mint a long-lived credential.
Every query is filtered to the caller's own integration tokens, so revoking one
never logs anybody out and nobody can enumerate another user's."
```

---

## Task 7: `lsm-web` — types, API module, query keys

**Files:**
- Modify: `packages/types/src/index.ts`
- Create: `src/lib/integration-tokens-api.ts`
- Modify: `src/lib/api.ts`
- Modify: `src/lib/queryKeys.ts`

**Interfaces:**
- Consumes: the JSON shapes from Task 6.
- Produces:
  - `IntegrationTokenScope = 'mcp:read' | 'mcp:write' | 'mcp:wp' | 'mcp:wp-destructive'`
  - `IntegrationToken`, `CreateIntegrationTokenPayload`, `CreatedIntegrationToken`
  - `api.integrationTokens.list() | create(payload) | revoke(id)`
  - `queryKeys.integrationTokens.all()`

Task 8's components consume all of these.

- [ ] **Step 1: Add the types**

Append to `packages/types/src/index.ts`:

```ts
// ---------------------------------------------------------------------------
// Integration tokens — long-lived scoped bearer tokens for MCP clients
// ---------------------------------------------------------------------------

export type IntegrationTokenScope =
  | 'mcp:read'
  | 'mcp:write'
  | 'mcp:wp'
  | 'mcp:wp-destructive';

export type IntegrationTokenExpiry = '30d' | '90d' | '1y' | 'never';

export interface IntegrationToken {
  id: number;
  name: string;
  scopes: IntegrationTokenScope[];
  created_at: string;
  /** null means the token never expires. */
  expires_at: string | null;
  last_used_at: string | null;
  last_used_ip: string | null;
  created_from_ip: string | null;
  is_expired: boolean;
}

export interface CreateIntegrationTokenPayload {
  name: string;
  scopes: IntegrationTokenScope[];
  expires_in: IntegrationTokenExpiry;
  /** The caller's current account password — step-up confirmation. */
  password: string;
}

export interface CreatedIntegrationToken {
  /** Plaintext. Returned exactly once, by the create call only. */
  token: string;
  integration_token: IntegrationToken;
}
```

- [ ] **Step 2: Write the API module**

Create `src/lib/integration-tokens-api.ts`:

```ts
import type { AxiosInstance } from 'axios';
import type {
  CreateIntegrationTokenPayload,
  CreatedIntegrationToken,
  IntegrationToken,
} from '@lsm/types';

export function createIntegrationTokensApi(client: AxiosInstance) {
  return {
    list: () =>
      client.get<{ data: IntegrationToken[] }>('/integration-tokens'),
    create: (payload: CreateIntegrationTokenPayload) =>
      client.post<{ data: CreatedIntegrationToken }>('/integration-tokens', payload),
    revoke: (id: number) =>
      client.delete<{ success: boolean }>(`/integration-tokens/${id}`),
  };
}
```

- [ ] **Step 3: Register the module and the query keys**

In `src/lib/api.ts`, add the import next to the other local modules:

```ts
import { createIntegrationTokensApi } from './integration-tokens-api';
```

and the entry inside the exported `api` object:

```ts
  integrationTokens: createIntegrationTokensApi(client),
```

In `src/lib/queryKeys.ts`, add a top-level group alongside `projects`:

```ts
  integrationTokens: {
    all: () => ['integration-tokens'] as const,
  },
```

- [ ] **Step 4: Verify it compiles**

Run: `npm run typecheck`
Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add packages/types/src/index.ts src/lib/integration-tokens-api.ts \
        src/lib/api.ts src/lib/queryKeys.ts
git commit -m "feat: types and API client for integration tokens"
```

---

## Task 8: `lsm-web` — the token management UI

**Files:**
- Create: `src/features/profile/components/IntegrationTokensCard.tsx`
- Create: `src/features/profile/components/CreateTokenModal.tsx`
- Modify: `src/features/profile/pages/ProfilePage.tsx`

**Interfaces:**
- Consumes: everything Task 7 produced.
- Produces: `<IntegrationTokensCard />`, a default export-free named component taking no props.

- [ ] **Step 1: Write the create modal**

Create `src/features/profile/components/CreateTokenModal.tsx`:

```tsx
/**
 * Create an integration token, then show it once.
 *
 * The reveal step is deliberately a separate mode of the same modal: the user
 * must not be able to dismiss the form and lose the only copy of the token by
 * accident, and the copyable `claude mcp add` line turns "I have a token" into
 * "I have a working client" without a trip to the docs.
 */

import { useState } from 'react';
import {
  Modal,
  Form,
  Input,
  Select,
  Checkbox,
  Typography,
  Alert,
  Space,
  Button,
  App,
} from 'antd';
import { CopyOutlined, KeyOutlined } from '@ant-design/icons';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import type {
  CreateIntegrationTokenPayload,
  IntegrationTokenExpiry,
  IntegrationTokenScope,
} from '@lsm/types';
import { api } from '@/lib/api';
import { queryKeys } from '@/lib/queryKeys';
import { useAuthStore } from '@/stores/auth';

const { Text, Paragraph } = Typography;

const MCP_URL = `${window.location.origin.replace('app.', 'api.')}/mcp`;

interface ScopeOption {
  value: IntegrationTokenScope;
  label: string;
  hint: string;
  /** Roles allowed to select it. Mirrors StoreIntegrationTokenRequest::ROLE_SCOPES. */
  roles: string[];
}

const SCOPE_OPTIONS: ScopeOption[] = [
  {
    value: 'mcp:read',
    label: 'Lesen',
    hint: 'Projekte, Todos, Zeiten und Team einsehen. Ändert nichts.',
    roles: ['admin', 'manager', 'developer'],
  },
  {
    value: 'mcp:write',
    label: 'Schreiben',
    hint: 'Todos, Zeiterfassung und Projektdaten anlegen und ändern.',
    roles: ['admin', 'manager', 'developer'],
  },
  {
    value: 'mcp:wp',
    label: 'WordPress',
    hint: 'Wartungsmodus, Cache, Updates und Backups auf Kundenseiten. Umkehrbar.',
    roles: ['admin', 'manager', 'developer'],
  },
  {
    value: 'mcp:wp-destructive',
    label: 'WordPress — kritisch',
    hint: 'Notfall-Wiederherstellung, Backup-Restore und Massenaktionen über alle Seiten. Nicht umkehrbar.',
    roles: ['admin', 'manager'],
  },
];

const EXPIRY_OPTIONS: { value: IntegrationTokenExpiry; label: string }[] = [
  { value: '30d', label: '30 Tage' },
  { value: '90d', label: '90 Tage' },
  { value: '1y', label: '1 Jahr' },
  { value: 'never', label: 'Läuft nie ab' },
];

interface Props {
  open: boolean;
  onClose: () => void;
}

export function CreateTokenModal({ open, onClose }: Props) {
  const [form] = Form.useForm<CreateIntegrationTokenPayload>();
  const [revealed, setRevealed] = useState<string | null>(null);
  const { message } = App.useApp();
  const queryClient = useQueryClient();
  const role = useAuthStore((state) => state.user?.role ?? 'developer');

  const createMutation = useMutation({
    mutationFn: (payload: CreateIntegrationTokenPayload) =>
      api.integrationTokens.create(payload),
    onSuccess: (response) => {
      setRevealed(response.data.data.token);
      form.resetFields();
      queryClient.invalidateQueries({ queryKey: queryKeys.integrationTokens.all() });
    },
    onError: () => {
      message.error('Token konnte nicht erstellt werden. Passwort korrekt?');
    },
  });

  const handleClose = () => {
    setRevealed(null);
    form.resetFields();
    onClose();
  };

  const copy = async (value: string) => {
    await navigator.clipboard.writeText(value);
    message.success('In die Zwischenablage kopiert');
  };

  const connectCommand = revealed
    ? `claude mcp add --transport http lsm ${MCP_URL} \\\n  --header "Authorization: Bearer ${revealed}" --scope user`
    : '';

  return (
    <Modal
      open={open}
      onCancel={handleClose}
      title={revealed ? 'Token erstellt' : 'Neuen Integrations-Token erstellen'}
      footer={
        revealed
          ? [
              <Button key="done" type="primary" onClick={handleClose}>
                Fertig — ich habe den Token gespeichert
              </Button>,
            ]
          : [
              <Button key="cancel" onClick={handleClose}>
                Abbrechen
              </Button>,
              <Button
                key="submit"
                type="primary"
                icon={<KeyOutlined />}
                loading={createMutation.isPending}
                onClick={() => form.submit()}
              >
                Token erstellen
              </Button>,
            ]
      }
      width={640}
      destroyOnClose={false}
      maskClosable={!revealed}
      closable={!revealed}
    >
      {revealed ? (
        <Space direction="vertical" size="middle" style={{ width: '100%' }}>
          <Alert
            type="warning"
            showIcon
            message="Dieser Token wird nur einmal angezeigt."
            description="Kopiere ihn jetzt. Danach lässt er sich nicht wieder anzeigen — nur widerrufen und neu erstellen."
          />

          <div>
            <Text strong>Token</Text>
            <Input.TextArea
              value={revealed}
              readOnly
              autoSize
              style={{ fontFamily: 'monospace', marginTop: 8 }}
            />
            <Button
              icon={<CopyOutlined />}
              onClick={() => copy(revealed)}
              style={{ marginTop: 8 }}
            >
              Token kopieren
            </Button>
          </div>

          <div>
            <Text strong>Client verbinden</Text>
            <Paragraph type="secondary" style={{ marginBottom: 8 }}>
              Diesen Befehl im Terminal ausführen:
            </Paragraph>
            <Input.TextArea
              value={connectCommand}
              readOnly
              autoSize
              style={{ fontFamily: 'monospace' }}
            />
            <Button
              icon={<CopyOutlined />}
              onClick={() => copy(connectCommand)}
              style={{ marginTop: 8 }}
            >
              Befehl kopieren
            </Button>
          </div>
        </Space>
      ) : (
        <Form
          form={form}
          layout="vertical"
          initialValues={{ scopes: ['mcp:read'], expires_in: '90d' }}
          onFinish={(values) => createMutation.mutate(values)}
        >
          <Form.Item
            name="name"
            label="Name"
            rules={[{ required: true, message: 'Bitte einen Namen angeben' }]}
            extra="Wofür ist dieser Token? z. B. „Claude Code — MacBook“"
          >
            <Input maxLength={100} placeholder="Claude Code — MacBook" />
          </Form.Item>

          <Form.Item
            name="scopes"
            label="Berechtigungen"
            rules={[{ required: true, message: 'Mindestens eine Berechtigung wählen' }]}
          >
            <Checkbox.Group style={{ width: '100%' }}>
              <Space direction="vertical" size="small" style={{ width: '100%' }}>
                {SCOPE_OPTIONS.map((scope) => {
                  const disabled = !scope.roles.includes(role);

                  return (
                    <Checkbox key={scope.value} value={scope.value} disabled={disabled}>
                      <Text strong={!disabled} type={disabled ? 'secondary' : undefined}>
                        {scope.label}
                      </Text>
                      <br />
                      <Text type="secondary" style={{ fontSize: 12 }}>
                        {scope.hint}
                        {disabled && ' — für deine Rolle nicht verfügbar'}
                      </Text>
                    </Checkbox>
                  );
                })}
              </Space>
            </Checkbox.Group>
          </Form.Item>

          <Form.Item name="expires_in" label="Gültigkeit" rules={[{ required: true }]}>
            <Select options={EXPIRY_OPTIONS} />
          </Form.Item>

          <Form.Item
            name="password"
            label="Aktuelles Passwort"
            rules={[{ required: true, message: 'Passwort zur Bestätigung eingeben' }]}
            extra="Zur Bestätigung, dass du das wirklich bist."
          >
            <Input.Password autoComplete="current-password" />
          </Form.Item>
        </Form>
      )}
    </Modal>
  );
}
```

- [ ] **Step 2: Write the token table card**

Create `src/features/profile/components/IntegrationTokensCard.tsx`:

```tsx
/**
 * Lists the current user's integration tokens.
 *
 * "Nie verwendet" is the interesting state, not an empty one: a token that was
 * minted and never used is the one worth cleaning up.
 */

import { useState } from 'react';
import { Card, Table, Tag, Button, Typography, Modal, Space, Empty, App } from 'antd';
import { ApiOutlined, PlusOutlined, DeleteOutlined } from '@ant-design/icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import dayjs from 'dayjs';
import type { IntegrationToken, IntegrationTokenScope } from '@lsm/types';
import { api } from '@/lib/api';
import { queryKeys } from '@/lib/queryKeys';
import { CreateTokenModal } from './CreateTokenModal';

const { Text, Title } = Typography;

const SCOPE_LABELS: Record<IntegrationTokenScope, { label: string; color: string }> = {
  'mcp:read': { label: 'Lesen', color: 'blue' },
  'mcp:write': { label: 'Schreiben', color: 'green' },
  'mcp:wp': { label: 'WordPress', color: 'purple' },
  'mcp:wp-destructive': { label: 'WP kritisch', color: 'red' },
};

export function IntegrationTokensCard() {
  const [createOpen, setCreateOpen] = useState(false);
  const { message, modal } = App.useApp();
  const queryClient = useQueryClient();

  const { data, isLoading } = useQuery({
    queryKey: queryKeys.integrationTokens.all(),
    queryFn: async () => (await api.integrationTokens.list()).data.data,
  });

  const revokeMutation = useMutation({
    mutationFn: (id: number) => api.integrationTokens.revoke(id),
    onSuccess: () => {
      message.success('Token widerrufen');
      queryClient.invalidateQueries({ queryKey: queryKeys.integrationTokens.all() });
    },
    onError: () => message.error('Token konnte nicht widerrufen werden'),
  });

  const confirmRevoke = (token: IntegrationToken) => {
    modal.confirm({
      title: `Token „${token.name}“ widerrufen?`,
      content:
        'Jeder Client, der diesen Token verwendet, verliert sofort den Zugriff. Das lässt sich nicht rückgängig machen.',
      okText: 'Widerrufen',
      okButtonProps: { danger: true },
      cancelText: 'Abbrechen',
      onOk: () => revokeMutation.mutateAsync(token.id),
    });
  };

  const columns = [
    {
      title: 'Name',
      dataIndex: 'name',
      key: 'name',
      render: (name: string, row: IntegrationToken) => (
        <Text delete={row.is_expired} type={row.is_expired ? 'secondary' : undefined}>
          {name}
        </Text>
      ),
    },
    {
      title: 'Berechtigungen',
      dataIndex: 'scopes',
      key: 'scopes',
      render: (scopes: IntegrationTokenScope[]) => (
        <Space size={4} wrap>
          {scopes.map((scope) => (
            <Tag key={scope} color={SCOPE_LABELS[scope]?.color ?? 'default'}>
              {SCOPE_LABELS[scope]?.label ?? scope}
            </Tag>
          ))}
        </Space>
      ),
    },
    {
      title: 'Gültigkeit',
      dataIndex: 'expires_at',
      key: 'expires_at',
      render: (expiresAt: string | null, row: IntegrationToken) => {
        if (expiresAt === null) return <Text type="secondary">Läuft nie ab</Text>;
        if (row.is_expired) return <Tag color="red">Abgelaufen</Tag>;

        return <Text>läuft {dayjs(expiresAt).fromNow()} ab</Text>;
      },
    },
    {
      title: 'Zuletzt verwendet',
      dataIndex: 'last_used_at',
      key: 'last_used_at',
      render: (lastUsedAt: string | null, row: IntegrationToken) =>
        lastUsedAt === null ? (
          <Tag>Nie verwendet</Tag>
        ) : (
          <Space direction="vertical" size={0}>
            <Text>{dayjs(lastUsedAt).fromNow()}</Text>
            {row.last_used_ip && (
              <Text type="secondary" style={{ fontSize: 12 }}>
                {row.last_used_ip}
              </Text>
            )}
          </Space>
        ),
    },
    {
      title: '',
      key: 'actions',
      align: 'right' as const,
      render: (_: unknown, row: IntegrationToken) => (
        <Button
          danger
          type="text"
          icon={<DeleteOutlined />}
          onClick={() => confirmRevoke(row)}
        >
          Widerrufen
        </Button>
      ),
    },
  ];

  return (
    <Card
      title={
        <Space>
          <ApiOutlined />
          <Title level={5} style={{ margin: 0 }}>
            API &amp; Integrationen
          </Title>
        </Space>
      }
      extra={
        <Button type="primary" icon={<PlusOutlined />} onClick={() => setCreateOpen(true)}>
          Token erstellen
        </Button>
      }
      style={{ borderRadius: 12, marginBottom: 24 }}
    >
      <Text type="secondary">
        Langlebige Tokens für KI-Clients über MCP. Jeder Token gilt nur für die gewählten
        Berechtigungen — und nie für mehr, als deine Rolle ohnehin darf.
      </Text>

      <Table<IntegrationToken>
        rowKey="id"
        loading={isLoading}
        dataSource={data ?? []}
        columns={columns}
        pagination={false}
        style={{ marginTop: 16 }}
        locale={{
          emptyText: (
            <Empty
              image={Empty.PRESENTED_IMAGE_SIMPLE}
              description="Noch keine Integrations-Tokens"
            />
          ),
        }}
      />

      <CreateTokenModal open={createOpen} onClose={() => setCreateOpen(false)} />
    </Card>
  );
}
```

`dayjs().fromNow()` needs the relativeTime plugin, and this project extends it per file rather than globally (`ProjectsPage.tsx:15,62`, `SupportPage.tsx:36,38`, `SupportTicketsTab.tsx:40,42`). Follow that convention — add to the imports at the top of `IntegrationTokensCard.tsx`, immediately after the `dayjs` import:

```ts
import relativeTime from 'dayjs/plugin/relativeTime';

dayjs.extend(relativeTime);
```

Put the `dayjs.extend()` call at module scope, below the import block and above `SCOPE_LABELS`, exactly as those three files do.

- [ ] **Step 3: Mount the card on the profile page**

In `src/features/profile/pages/ProfilePage.tsx`, add the import:

```tsx
import { IntegrationTokensCard } from '../components/IntegrationTokensCard';
```

and render it in the main column, after the Email-2FA card that closes at line 604 and before the recovery-codes card:

```tsx
          <IntegrationTokensCard />
```

- [ ] **Step 4: Verify it compiles and lints**

Run: `npm run typecheck && npm run lint`
Expected: no errors, no warnings (`lint` runs with `--max-warnings 0`).

- [ ] **Step 5: Check it in the browser**

Run the dev server, log in, open `/profile`, and confirm:
- the card renders with an empty state
- "Token erstellen" opens the modal; `WordPress — kritisch` is disabled when logged in as a developer
- creating with a wrong password shows the error and no row appears
- creating with the right password reveals the token and the `claude mcp add` command, both copyable
- the new row shows "Nie verwendet"
- revoking asks for confirmation and removes the row

- [ ] **Step 6: Commit**

```bash
git add src/features/profile
git commit -m "feat: integration token management on the profile page

Placed on /profile rather than /settings: settings is wrapped in AdminRoute,
and these are per-user credentials that developers and managers need too. The
reveal step carries a prefilled claude mcp add command so a new token becomes a
working client without a detour through the docs."
```

---

## Task 9: Replace the live admin token

Manual, and the only step that touches a real credential. **Do not run these commands on the user's behalf** — the shell classifier blocks commands carrying a bearer token, and rotating a live credential is the user's call.

- [ ] **Step 1: Deploy both branches**

Merge and deploy `lsm-api` first — `lsm-web` calls routes that must already exist. Run the migrations on production:

```bash
php artisan migrate --force
```

Confirm the backfill landed before anything else:

```bash
php artisan tinker --execute="echo DB::table('personal_access_tokens')->whereNull('expires_at')->count();"
```

Expected: `0`. Any other number means legacy tokens are now immortal — investigate before proceeding.

- [ ] **Step 2: Mint a scoped replacement**

In the browser: `/profile` → API & Integrationen → Token erstellen. Name it `Claude Code — MacBook`, scopes `Lesen` + `Schreiben`, 90 days. That is what the day-to-day task-tracking use case needs, and it touches no client site.

- [ ] **Step 3: Swap the client over**

The user runs, in their own terminal:

```bash
claude mcp remove lsm
claude mcp add --transport http lsm https://api.wartung-ls.com/mcp \
  --header "Authorization: Bearer <the new token>" --scope user
```

- [ ] **Step 4: Verify the new token is narrower than the old one**

In a fresh session, confirm `list-todos` and `create-todo` work and that no `wp-*` tool appears in the tool list at all. The old wildcard admin token in `~/.claude.json` is now replaced; revoke any leftover session tokens from the profile page if they are still listed.

---

## Self-review notes

Checked against the spec, section by section.

- **§1 Scopes** — Tasks 4-5 cover all 44 tools and the 13/14/13/4 split is asserted, not assumed. The role gate lives in `StoreIntegrationTokenRequest::ROLE_SCOPES` and is verified against the tools' own role checks.
- **§2 Enforcement** — trait in Task 4; `shouldRegister()` hides, `assertScope()` refuses. Wildcard backwards compatibility tested.
- **§3 Data model** — Task 3. The expiration fix is Task 2, alone, with tests either side of the 8-hour boundary and a test that the backfill actually bounds a legacy row.
- **§4 API** — Task 6, including step-up, throttling, `type` filtering and 404-not-403 for strangers.
- **§5 UI** — Tasks 7-8, including the never-used badge, muted expired rows, the disabled destructive checkbox, the one-time reveal and the prefilled connect command. Placement moved to `/profile`; see Deviations.
- **§6 Testing** — every listed case has a test, except "a legacy `*` token sees all 44 tools", which is now meaningful only because Task 1 makes 44 the real number.
- **Implementation order** — the spec's step 1 is this plan's Task 2; a new Task 1 precedes it because the server consolidation changes which primitives the scope tests can even see.
