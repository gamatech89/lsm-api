# Managed .htaccess Hardening (lsm-api) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the platform a status endpoint and three guarded write endpoints for the plugin's managed `.htaccess` hardening rules, plus a write-ahead pause log and a scheduled backstop that re-enables the archive rule when a "pause for download" does not end on its own.

**Architecture:** A raw `LsmService::hardeningRequest()` returns the plugin's HTTP status and untouched JSON (never through `handleResponse()`), and one small `HardeningResponseMapper` turns that into the spec's response-mapping table for both the new `HardeningController` and the `hardening:resume-expired` command. Pauses are recorded in `project_hardening_pauses` *before* the plugin is called, so a lost response still leaves a row the 10-minute backstop can reconcile. Authorization is three new `ProjectPolicy` abilities; the rule endpoint picks enable/disable from the cast boolean before validating, so garbage input fails closed.

**Tech Stack:** Laravel 12.44 / PHP 8.3, Pest 4 feature tests on SQLite `:memory:` with `Http::fake`, Laravel scheduler, database + mail notifications.

**Spec:** /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api/docs/superpowers/specs/2026-09-21-htaccess-hardening-design.md

## Global Constraints

- **Branch:** `feature/htaccess-hardening` (from `main` in lsm-api) is already checked out; the only untracked files are the plan documents under `docs/superpowers/plans/` — never `git add -A`, `git add .` or `git add docs`; every commit step below names its files. Work there directly. No worktree. Never run `git checkout`, `git switch`, `git stash` or `git reset` — in this repo or in `lsm-web` / `lsm-wp` (both carry someone's uncommitted work).
- **Repo root for every command:** `/Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api`. Shell cwd is not preserved between calls, so every command below starts with `cd` into it.
- **Plugin release:** `2.10.0`. `min_version` in API responses is the string `'2.10.0'`.
- **Rule keys (fixed):** `block_archives`, `block_debug_log`, `block_uploads_php`.
- **Plugin endpoints (namespace `lsm/v1`, auth header `X-LSM-Key`):** `GET /hardening/status`, `POST /hardening/rule` `{rule, enabled}`, `POST /hardening/pause` `{minutes}`, `POST /hardening/resume`.
- **Plugin response shape:** HTTP 200, top level, **no `data` wrapper**: `{success, reason, message, warnings, status}` with `status = {plugin_version, server, rules: {<rule>: {state, desired, unsupported_reason, last_failure}}, pause_until, pause_overdue, archive_attachments, last_result}`. `pause_until` is a unix int or null.
- **Rule states (fixed):** `unsupported`, `paused`, `on`, `manual`, `drift`, `off`.
- **Plugin `reason` codes (fixed, passed through unchanged):** `busy`, `invalid_rule`, `invalid_minutes`, `not_enabled`, `unsupported`, `loopback_blocked`, `markers_corrupt`, `snapshot_failed`, `write_failed`, `asset_broken`, `rule_ineffective`, `pause_ineffective_foreign_rule`, `rollback_failed`. Warnings: `already_blocked_elsewhere`, `unverified`.
- **Platform `reason` codes (fixed):** `plugin_outdated`, `unauthorized`, `unreachable`.
- **`LsmService::hardeningRequest(string $method, string $endpoint, array $data = [], int $timeout = 30): array`** → `['http' => int|null, 'json' => array|null]`. It does **not** go through `handleResponse()`. GETs append `_=<microtime>`. POSTs use 120 s (the controller passes it); the command uses 60 s. Redirects are followed in Guzzle's strict mode (`'allow_redirects' => ['strict' => true]`), so a POST that is redirected (http → https, non-www → www) stays a POST with its body.
- **API routes (fixed paths):** `GET /hardening`, `POST /hardening/rule`, `POST /hardening/pause`, `POST /hardening/resume`, inside the existing `projects/{project}/lsm` group, after `security-headers/snippets`; POSTs additionally behind `throttle:12,1,hardening` (spec text: `throttle:12,1`; the third parameter only gives the limiter its own per-user bucket — do not remove it, see Deviation 1).
- **Abilities (fixed names and bodies):** `pauseHardening` → `return $this->update($user, $project);` · `enableHardening` → `return $user->role === 'manager' && $this->managesProject($user, $project);` · `disableHardening` → `return false;`. Admins (role `admin` **or** `is_admin` flag) pass everything through `ProjectPolicy::before()`. GET is authorized with `view`.
- **`POST /hardening/rule` order:** `$enabled = $request->boolean('enabled');` → `Gate::authorize($enabled ? 'enableHardening' : 'disableHardening', $project)` → then validate (`rule` in the three keys, `enabled` required boolean). Missing/garbage `enabled` = disable (fail closed).
- **Response mapping (fixed):**

| Plugin result | API response |
|---|---|
| 200 `success:true` | 200 `{success:true, message, warnings, status, open_pause, pause_overdue, can}` |
| 200 `success:false`, reason `busy` | 409 `{success:false, reason:'busy', message, status, …}` |
| 200 `success:false`, other | 422 `{success:false, reason, message, status, …}` |
| 404 (route missing) | 409 `{success:false, reason:'plugin_outdated', min_version:'2.10.0', message}` |
| 401 / 403 | 502 `{success:false, reason:'unauthorized', message}` |
| no response / timeout / 5xx | 502 `{success:false, reason:'unreachable', message:'Outcome unknown — refresh status'}` |

- **`GET /hardening` always answers 200:** `{reachable, plugin_outdated, min_version, status|null, open_pause|null, pause_overdue, can:{pause,enable,disable}}`. `can` comes from `Gate::allows(...)`. `pause_overdue` = an open pause row with `paused_until < now()`, or the plugin's own `pause_overdue`.
- **Table `project_hardening_pauses`:** `project_id` (constrained, cascade), `user_id` (nullable, constrained, nullOnDelete), `paused_until`, `resumed_at` nullable, `failed_at` nullable, `overdue_notified_at` nullable, `note` nullable string, timestamps. Model `ProjectHardeningPause`.
- **Write-ahead pause:** close any open row for the project, insert the new row **before** calling the plugin. Explicit `success:false` → delete the row. No response → keep the row. Success → set `paused_until` from the plugin's `pause_until`. Resume success, or turning `block_archives` off → close open rows. GET closes an open row opportunistically when a reachable plugin status shows `block_archives` not `paused`. Platform refinement (Deviations 3 and 4): 404 and 401/403 also delete the row, and so does a project without an LSM key (nothing was sent); every such definite non-pause reopens the rows this request superseded. Only an `unreachable` result from a configured project keeps the row.
- **Pause minutes:** `15`, `30`, `60` only.
- **Command:** `hardening:resume-expired`, scheduled exactly `->everyTenMinutes()->withoutOverlapping(15)->runInBackground()->name('hardening-resume-expired')->onOneServer()`. Per open row past `paused_until`, inside its own try/catch: project trashed/missing/not configured → close with a note; otherwise **POST** resume (never decide from a GET), 60 s timeout; close only when the response shows `block_archives` state `on`; `plugin_outdated`/`unauthorized` → `failed_at`; row older than 24 h → `failed_at`; more than 30 min overdue and `overdue_notified_at` null → one `HardeningPauseOverdueNotification` to the pausing user and the admins. A row that is marked `failed_at` is notified in the same run (it is never visited again — Deviation 5). `overdue_notified_at` is stamped **before** the notify loop and every recipient is notified inside its own try/catch, so a failing mail transport can never cause a second notification on the next run.
- **Audit line:** every enable/disable/pause/resume call writes one `Log::info('hardening', [...])` with user id, project id, action, rule, outcome — the controller for user calls (Task 13), the backstop command for its own resume calls with `'user_id' => null` (Task 11; the schedule entry runs in the background, so console output is discarded and this line is the only trace).
- **Never** route a hardening call through `LsmService::get()` / `post()` / `handleResponse()`, never use the `role:admin` middleware (`CheckRole` ignores `is_admin`), never assert admin rights with `new ProjectPolicy` (that skips `before()`).
- **Tests run on SQLite `:memory:`** (`phpunit.xml:26-27`): string columns instead of enums, no raw SQL, date comparisons through Eloquent/Carbon. `phpunit.xml` pins `MANAGERS_VIEW_ALL_PROJECTS=true` (forced, line 42) — the "unassigned manager GET 200" tests depend on it; `MFA_ENFORCED_ROLES=""` is **not** forced (line 35), so test users are created with `two_factor_confirmed_at`.
- **Queue is `sync` in production** (`routes/console.php:144-146`): the command does its work inline, no queued jobs.
- **Baseline suite: 346 passed, 2 skipped, 0 failed** (measured on `e1ff102`). After Task 13 it must be **516 passed, 2 skipped** (513 planned + 1 review-mandated test in Task 5 + 2 extra audit-outcome tests in Task 13). Run the whole suite with `php -d memory_limit=1G vendor/bin/pest` — plain `php artisan test` without a path runs out of the 128 MB CLI memory limit on this machine even on the untouched baseline. Single files and the `tests/Feature/Hardening` directory run fine with `php artisan test <path>`.
- **Rollout order:** plugin 2.10.0 first (inert: everything off), then API, then web. This plan executes no deploy: **Tasks 1-13 are the whole job.** Appendix A is a runbook for the human who deploys — it is not a task, and no agent executes, verifies or is dispatched with any part of it.

---

## File Structure

**Create:**
- `app/Http/Controllers/Api/V1/HardeningController.php` — the four endpoints (`show`, `setRule`, `pause`, `resume`), the pause-row lifecycle, the `can` block, the audit log line. A dedicated controller like `SecurityScanController` in the same route group; `LsmController` is already 1080 lines.
- `app/Services/HardeningResponseMapper.php` — one static `map()`: raw `hardeningRequest()` result → `{outcome, code, body}` per the response-mapping table. Shared by controller and command.
- `app/Models/ProjectHardeningPause.php` — the pause row: fillable, datetime casts, `project()`, `user()`, `scopeOpen()`.
- `database/migrations/2026_09_21_120000_create_project_hardening_pauses_table.php` — the table.
- `database/factories/ProjectHardeningPauseFactory.php` — an open row, one hour in the future.
- `app/Notifications/HardeningPauseOverdueNotification.php` — database (+ mail when the project has e-mail alerts) alert for a pause that did not end.
- `app/Console/Commands/ResumeExpiredHardeningPauses.php` — `hardening:resume-expired`.
- `tests/Feature/Hardening/HardeningRequestTest.php` — `hardeningRequest()`.
- `tests/Feature/Hardening/HardeningPolicyTest.php` — ability matrix through the Gate.
- `tests/Feature/Hardening/ProjectHardeningPauseTest.php` — model, scope, foreign keys.
- `tests/Unit/HardeningResponseMapperTest.php` — mapping table row by row.
- `tests/Feature/Hardening/HardeningStatusEndpointTest.php` — `GET /hardening`.
- `tests/Feature/Hardening/HardeningRuleEndpointTest.php` — `POST /hardening/rule`, mapping table through HTTP.
- `tests/Feature/Hardening/HardeningPauseEndpointTest.php` — write-ahead row lifecycle.
- `tests/Feature/Hardening/HardeningResumeEndpointTest.php` — `POST /hardening/resume`.
- `tests/Feature/Hardening/HardeningRoutesTest.php` — route registration and throttle.
- `tests/Feature/Hardening/HardeningPauseOverdueNotificationTest.php` — notification channels and payload.
- `tests/Feature/Hardening/ResumeExpiredHardeningPausesTest.php` — every branch of the command.
- `tests/Feature/Hardening/HardeningScheduleTest.php` — the scheduler chain.
- `tests/Feature/Hardening/HardeningAuditLogTest.php` — the `Log::info('hardening', …)` line.

**Modify:**
- `app/Services/LsmService.php` — add `hardeningRequest()` after `getSecurityHeaderSnippets()` (after line 493).
- `app/Policies/ProjectPolicy.php` — three abilities after `assignTeam()` (after line 147).
- `routes/api.php` — four routes after line 288 (`security-headers/snippets`), before the `// Security Scanning` comment (line 290).
- `routes/console.php` — scheduler entry appended after line 232.
- `tests/Pest.php` — four global test helpers appended after line 59 (`hardeningProject`, `hardeningUser`, `hardeningPluginStatus`, `hardeningPluginBody`). They live here because Pest helper functions are global: a helper declared in one test file is missing when another file is run on its own.
- `tests/Feature/Emails/AllNotificationsRenderTest.php` — this existing test fails as soon as a notification class exists that is not in its dataset; Task 10 adds the entry.

## Deviations from the spec

0. **Opportunistic close keys on state `on` only** (changed after the plugin's final review): a status GET taken mid-operation reports `drift` with `pause_until` not yet committed; closing the row then would leave a finisher-less host (no `fastcgi_finish_request`) with no resumer. Rows for sites that end up `off`/`manual` (e.g. after a deactivate/reactivate cycle) are closed by the rule endpoint or by `hardening:resume-expired` (24 h → `failed_at` + one notification). The quoted test counts in Tasks 5-13 and the full-suite total are therefore +1 (one dataset row became 1 + 3 cases: 24 → 26 in Task 5, and every later cumulative count +2).


All five are platform-internal; none changes a shared name, route, JSON key or reason code.

1. **`throttle:12,1,hardening`, not bare `throttle:12,1`.** `ThrottleRequests::handle()` builds the counter key as `$prefix . sha1(user id)` (`vendor/laravel/framework/src/Illuminate/Routing/Middleware/ThrottleRequests.php:85-106, 222-231`), so every un-prefixed `throttle:N,M` route shares **one per-user counter**. `/search` (`routes/api.php:459-461`, `throttle:30,1`) is hit repeatedly while someone uses the global search; 13 of those in a minute would lock the same user out of every hardening POST. Verified with a test in a scratch copy: it fails with `throttle:12,1` and passes with the third parameter. The limit stays 12 per minute.
2. **The opportunistic close in `GET /hardening` skips rows younger than 180 s.** The panel refetches on window focus; a GET that lands while a pause POST (up to 120 s) is still running would see `on`, close the write-ahead row, and the pause would then succeed with no open row — exactly what write-ahead exists to prevent.
3. **A pause that definitely did not happen reopens the row it superseded.** Spec order is "close open rows, insert, call". A double click does: call 1 in flight (row A) → call 2 closes A, inserts B, plugin answers `busy` → B deleted → A stays closed → call 1 succeeds with no open row. On a definite non-pause the controller deletes the new row **and** reopens the rows this request closed.
4. **404 and 401/403 on a pause delete the row too.** The spec names only "explicit `success:false` → delete" and "no response → keep". An old plugin has no route and a rejected key never reaches the callback, so nothing was paused; keeping the row would only produce a false overdue alert later. The same holds for a project without an LSM key: `hardeningRequest()` returns `['http' => null, 'json' => null]` without sending anything, which maps to `unreachable`, but nothing can have been paused — the controller checks `isConfigured()` and treats it as a definite non-pause. Only an `unreachable` result from a configured project keeps the row.
5. **A row that is marked `failed_at` is notified immediately** (once, via `overdue_notified_at`), not only after 30 minutes. A failed row is never visited again, so a terminal failure at the first attempt (10 minutes overdue) would otherwise never alert anyone. The "more than 30 min overdue → one notification" rule is unchanged for rows that stay open.

Also: `paused_until` is a `dateTime` column, the nullable ones are `timestamp`. On MySQL/MariaDB without `explicit_defaults_for_timestamp` the first `NOT NULL` timestamp column silently gets `ON UPDATE CURRENT_TIMESTAMP`, and this row is updated several times after `paused_until` is set.

Two implementation details the spec leaves open, both forced by how the platform really runs:

- **`hardeningRequest()` follows redirects in strict mode.** The existing `get()` / `post()` helpers use `'allow_redirects' => true`; with that default Guzzle replays a redirected POST as a body-less GET, WordPress answers a GET on a POST-only route with 404 `rest_no_route`, and this plan newly maps 404 to `plugin_outdated` — on a project whose stored URL redirects (http → https, non-www → www) the status GET would work and every button would say "update the plugin". `['strict' => true]` replays the POST as a POST.
- **The overdue notification is at-most-once.** The queue is `sync`, the notification has a mail channel, and a mail transport error is thrown straight out of `notify()`. The command therefore stamps `overdue_notified_at` first and catches per recipient: one broken SMTP run costs the e-mail, never the database notification of the other recipients, and never a repeat every ten minutes.

---

### Task 1: `LsmService::hardeningRequest()`

**Files:**
- Modify: `tests/Pest.php` (append after line 59)
- Modify: `app/Services/LsmService.php` (insert after line 493)
- Test: `tests/Feature/Hardening/HardeningRequestTest.php` (create)

**Interfaces:**
- Consumes: existing `LsmService::for(Project $project): self`, `isConfigured(): bool`, properties `$apiKey`, `$baseUrl` (`= rtrim($project->url, '/') . '/wp-json/lsm/v1'`).
- Produces: `LsmService::hardeningRequest(string $method, string $endpoint, array $data = [], int $timeout = 30): array` returning `['http' => int|null, 'json' => array|null]`. `http` is null when nothing answered (not configured, timeout, connection error). `json` is the decoded body **as sent** (the `success` key is never stripped) or null when the body is not a JSON array/object. Redirects are followed strictly: a redirected POST is replayed as a POST with the same JSON body and `X-LSM-Key` header, and the result is the final response.
- Produces: global test helper `hardeningProject(array $attrs = []): \App\Models\Project` — a project with `url = 'https://client.example.com'` and `health_check_secret = 'SECRETKEY123'`.

- [ ] **Step 1: Add the `hardeningProject()` test helper**

Append to the end of `tests/Pest.php` (the file currently ends at line 59 with the closing brace of `actingWithScopes()`):

```php

/**
 * A project the LSM plugin counts as "configured" (url + health_check_secret),
 * for the .htaccess hardening tests. ProjectFactory sets no secret on its own.
 */
function hardeningProject(array $attrs = []): \App\Models\Project
{
    return \App\Models\Project::factory()->create(array_merge([
        'url' => 'https://client.example.com',
        'health_check_secret' => 'SECRETKEY123',
    ], $attrs));
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Hardening/HardeningRequestTest.php`:

```php
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
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan test tests/Feature/Hardening/HardeningRequestTest.php`
Expected: FAIL, 9 failed — `Call to undefined method App\Services\LsmService::hardeningRequest()`.

- [ ] **Step 4: Write `hardeningRequest()`**

In `app/Services/LsmService.php`, directly after this existing method (lines 487-493) and before the `// SECURITY SCANNING` banner (line 495):

```php
    /**
     * Get security header configuration snippets for Apache, Nginx, and PHP.
     */
    public function getSecurityHeaderSnippets(): ?array
    {
        return $this->get('/security/headers/snippets');
    }
```

insert (keep one blank line above and below):

```php
    /**
     * Raw call to the plugin's /hardening/* endpoints (plugin 2.10.0+).
     *
     * Deliberately NOT routed through handleResponse(): the caller needs the
     * HTTP status (404 = plugin too old, 401/403 = key rejected) and the
     * untouched `success` flag. `http` is null when there was no response at
     * all (not configured, timeout, DNS, connection refused).
     *
     * @return array{http: int|null, json: array|null}
     */
    public function hardeningRequest(string $method, string $endpoint, array $data = [], int $timeout = 30): array
    {
        if (!$this->isConfigured()) {
            return ['http' => null, 'json' => null];
        }

        try {
            $request = Http::timeout($timeout)
                ->withHeaders(['X-LSM-Key' => $this->apiKey])
                // strict: a 301/302 (http -> https, non-www -> www) must replay a POST
                // as a POST. Guzzle's default replays it as a GET, WordPress answers
                // that with 404 rest_no_route, and the site would read as plugin_outdated.
                ->withOptions(['allow_redirects' => ['strict' => true]]);

            if (strtoupper($method) === 'GET') {
                // One host caches plugin REST GETs for 28 days — bust it on every call.
                $response = $request->get($this->baseUrl . $endpoint, array_merge($data, ['_' => sprintf('%.6F', microtime(true))]));
            } else {
                $response = $request->asJson()->post($this->baseUrl . $endpoint, $data);
            }

            $json = $response->json();

            return ['http' => $response->status(), 'json' => is_array($json) ? $json : null];
        } catch (\Exception $e) {
            Log::error("LSM API Error ({$endpoint}): {$e->getMessage()}");
            return ['http' => null, 'json' => null];
        }
    }
```

`Http` and `Log` are already imported at the top of the file (lines 6-7). Do not touch `get()`, `post()` or `handleResponse()`. Do not "align" the options line with the `['allow_redirects' => true]` the other helpers in this file use: with the non-strict default the redirect test from Step 2 fails with `GET` and an empty body where `POST` and `{"minutes":15}` are expected.

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan test tests/Feature/Hardening/HardeningRequestTest.php`
Expected: PASS, 9 tests.

Then prove the existing service contract is untouched:

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan test tests/Feature/LsmServiceAuthTest.php`
Expected: PASS, 1 test.

- [ ] **Step 6: Commit**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api
git add app/Services/LsmService.php tests/Pest.php tests/Feature/Hardening/HardeningRequestTest.php
git commit -m "feat(hardening): raw LsmService::hardeningRequest with status code and cache-buster" \
  -m "handleResponse() turns every non-2xx into null and strips the success key, so
an old plugin (404), a rejected key (401) and a timeout were indistinguishable.
hardeningRequest() returns ['http' => int|null, 'json' => array|null] untouched
and appends _=<microtime> to GETs because one host caches plugin REST GETs for
28 days. Redirects are followed strictly so a redirected POST stays a POST;
replayed as a GET it would come back 404 and read as an outdated plugin." \
  -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 2: Policy abilities and the permission matrix

**Files:**
- Modify: `tests/Pest.php` (append)
- Modify: `app/Policies/ProjectPolicy.php` (insert after line 147)
- Test: `tests/Feature/Hardening/HardeningPolicyTest.php` (create)

**Interfaces:**
- Consumes: `hardeningProject(array $attrs = []): \App\Models\Project` (Task 1). Existing `ProjectPolicy::before()`, `update()`, private `managesProject()`.
- Produces: Gate abilities `pauseHardening`, `enableHardening`, `disableHardening` on `Project`.
- Produces: global test helper `hardeningUser(string $role, array $attrs = []): \App\Models\User` — role is one of `admin|manager|developer|viewer`; pass `['is_admin' => true]` for a flag holder.

- [ ] **Step 1: Add the `hardeningUser()` test helper**

Append to the end of `tests/Pest.php`:

```php

/**
 * A user for the hardening permission tests. two_factor_confirmed_at keeps
 * EnsureTwoFactorEnrolled out of the way even when a developer's shell exports
 * MFA_ENFORCED_ROLES (phpunit.xml does not force that variable).
 */
function hardeningUser(string $role, array $attrs = []): \App\Models\User
{
    return \App\Models\User::factory()->create(array_merge([
        'role' => $role,
        'two_factor_confirmed_at' => now(),
    ], $attrs));
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Hardening/HardeningPolicyTest.php`:

```php
<?php

// Every assertion goes through the Gate ($user->can()), never through
// `new ProjectPolicy`: admins are granted by ProjectPolicy::before(), which a
// directly instantiated policy never runs.

test('an admin by role may pause, enable and disable', function () {
    $admin = hardeningUser('admin');
    $project = hardeningProject();

    expect($admin->can('pauseHardening', $project))->toBeTrue();
    expect($admin->can('enableHardening', $project))->toBeTrue();
    expect($admin->can('disableHardening', $project))->toBeTrue();
});

test('a manager holding the is_admin flag may pause, enable and disable without being assigned', function () {
    $flagHolder = hardeningUser('manager', ['is_admin' => true]);
    $project = hardeningProject();

    expect($flagHolder->can('pauseHardening', $project))->toBeTrue();
    expect($flagHolder->can('enableHardening', $project))->toBeTrue();
    expect($flagHolder->can('disableHardening', $project))->toBeTrue();
});

test('a manager who manages the project via the legacy column may pause and enable but not disable', function () {
    $manager = hardeningUser('manager');
    $project = hardeningProject(['manager_id' => $manager->id]);

    expect($manager->can('pauseHardening', $project))->toBeTrue();
    expect($manager->can('enableHardening', $project))->toBeTrue();
    expect($manager->can('disableHardening', $project))->toBeFalse();
});

test('a manager who manages the project via the pivot may pause and enable but not disable', function () {
    $manager = hardeningUser('manager');
    $project = hardeningProject();
    $project->managers()->attach($manager->id);

    expect($manager->can('pauseHardening', $project))->toBeTrue();
    expect($manager->can('enableHardening', $project))->toBeTrue();
    expect($manager->can('disableHardening', $project))->toBeFalse();
});

test('an assigned developer may pause but neither enable nor disable', function () {
    $developer = hardeningUser('developer');
    $project = hardeningProject();
    $project->developers()->attach($developer->id);

    expect($developer->can('pauseHardening', $project))->toBeTrue();
    expect($developer->can('enableHardening', $project))->toBeFalse();
    expect($developer->can('disableHardening', $project))->toBeFalse();
});

test('an unassigned manager can view the project but has none of the three abilities', function () {
    $manager = hardeningUser('manager');
    $project = hardeningProject();

    // MANAGERS_VIEW_ALL_PROJECTS is pinned to true in phpunit.xml.
    expect($manager->can('view', $project))->toBeTrue();
    expect($manager->can('pauseHardening', $project))->toBeFalse();
    expect($manager->can('enableHardening', $project))->toBeFalse();
    expect($manager->can('disableHardening', $project))->toBeFalse();
});

test('an unassigned developer has none of the three abilities', function () {
    $developer = hardeningUser('developer');
    $project = hardeningProject();

    expect($developer->can('pauseHardening', $project))->toBeFalse();
    expect($developer->can('enableHardening', $project))->toBeFalse();
    expect($developer->can('disableHardening', $project))->toBeFalse();
});

test('a viewer has none of the three abilities and cannot even view', function () {
    $viewer = hardeningUser('viewer');
    $project = hardeningProject();

    expect($viewer->can('view', $project))->toBeFalse();
    expect($viewer->can('pauseHardening', $project))->toBeFalse();
    expect($viewer->can('enableHardening', $project))->toBeFalse();
    expect($viewer->can('disableHardening', $project))->toBeFalse();
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan test tests/Feature/Hardening/HardeningPolicyTest.php`
Expected: FAIL, 5 failed, 3 passed. The three all-false tests already pass: the Gate denies an ability that has no policy method, and it does not even consult `before()` for it — which is also why both admin tests fail now.

- [ ] **Step 4: Add the three abilities**

In `app/Policies/ProjectPolicy.php`, directly after this existing method (lines 141-147) and before the class's closing brace (line 148):

```php
    /**
     * Determine whether the user can assign team members to the project.
     */
    public function assignTeam(User $user, Project $project): bool
    {
        return $user->role === 'manager' && $this->managesProject($user, $project);
    }
```

insert (one blank line above):

```php
    /**
     * Pause / resume the .htaccess archive rule: anyone who can update the project.
     */
    public function pauseHardening(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }

    /**
     * Turn a hardening rule on (or adopt a manual one): managers of the project.
     */
    public function enableHardening(User $user, Project $project): bool
    {
        return $user->role === 'manager' && $this->managesProject($user, $project);
    }

    /**
     * Turn a hardening rule off.
     */
    public function disableHardening(User $user, Project $project): bool
    {
        return false; // Only admins via before()
    }
```

Do not copy `manageCredentials()`'s inline `$user->role === 'admin' || $user->is_admin` check — under `before()` it is dead code.

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan test tests/Feature/Hardening/HardeningPolicyTest.php tests/Feature/ProjectManagerLegacyPolicyTest.php`
Expected: PASS, 13 tests (8 new + 5 existing).

- [ ] **Step 6: Commit**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api
git add app/Policies/ProjectPolicy.php tests/Pest.php tests/Feature/Hardening/HardeningPolicyTest.php
git commit -m "feat(hardening): pauseHardening, enableHardening and disableHardening abilities" \
  -m "pause = anyone with update, enable = managers of the project, disable = admins
only (granted exclusively by before(), so the is_admin flag is covered). The
matrix is tested through the Gate because a directly instantiated policy never
runs before()." \
  -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 3: `project_hardening_pauses` table, model and factory

**Files:**
- Create: `database/migrations/2026_09_21_120000_create_project_hardening_pauses_table.php`
- Create: `app/Models/ProjectHardeningPause.php`
- Create: `database/factories/ProjectHardeningPauseFactory.php`
- Test: `tests/Feature/Hardening/ProjectHardeningPauseTest.php` (create)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `App\Models\ProjectHardeningPause` with fillable `project_id, user_id, paused_until, resumed_at, failed_at, overdue_notified_at, note`; `paused_until`, `resumed_at`, `failed_at`, `overdue_notified_at` cast to Carbon; `project(): BelongsTo`, `user(): BelongsTo`; query scope `ProjectHardeningPause::open()` = `resumed_at IS NULL AND failed_at IS NULL`. "Closing" a row means setting `resumed_at`; "giving up" means setting `failed_at`.
- Produces: `ProjectHardeningPause::factory()` — an open row, `paused_until = now()+1h`, creating its own `Project` and `User` unless `project_id` / `user_id` are passed.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Hardening/ProjectHardeningPauseTest.php`:

```php
<?php

use App\Models\Project;
use App\Models\ProjectHardeningPause;
use App\Models\User;
use Illuminate\Support\Carbon;

test('the factory creates an open pause row with datetime casts and both relations', function () {
    $pause = ProjectHardeningPause::factory()->create();

    expect($pause->paused_until)->toBeInstanceOf(Carbon::class);
    expect($pause->resumed_at)->toBeNull();
    expect($pause->failed_at)->toBeNull();
    expect($pause->overdue_notified_at)->toBeNull();
    expect($pause->note)->toBeNull();
    expect($pause->project)->toBeInstanceOf(Project::class);
    expect($pause->user)->toBeInstanceOf(User::class);
});

test('the open scope leaves out resumed rows and failed rows', function () {
    $open = ProjectHardeningPause::factory()->create();
    ProjectHardeningPause::factory()->create(['resumed_at' => now()]);
    ProjectHardeningPause::factory()->create(['failed_at' => now()]);

    expect(ProjectHardeningPause::open()->pluck('id')->all())->toBe([$open->id]);
});

test('nullable columns round-trip through mass assignment', function () {
    $pause = ProjectHardeningPause::factory()->create();

    $pause->update([
        'resumed_at' => now(),
        'failed_at' => now(),
        'overdue_notified_at' => now(),
        'note' => 'closed by test',
    ]);

    $fresh = $pause->fresh();
    expect($fresh->resumed_at)->toBeInstanceOf(Carbon::class);
    expect($fresh->failed_at)->toBeInstanceOf(Carbon::class);
    expect($fresh->overdue_notified_at)->toBeInstanceOf(Carbon::class);
    expect($fresh->note)->toBe('closed by test');
});

test('force-deleting the project removes its pause rows', function () {
    $pause = ProjectHardeningPause::factory()->create();

    $pause->project->forceDelete();

    expect(ProjectHardeningPause::count())->toBe(0);
});

test('force-deleting the pausing user keeps the row and nulls user_id', function () {
    $pause = ProjectHardeningPause::factory()->create();

    $pause->user->forceDelete();

    expect($pause->fresh())->not->toBeNull();
    expect($pause->fresh()->user_id)->toBeNull();
});

test('a soft-deleted project leaves the row in place with a null project relation', function () {
    $pause = ProjectHardeningPause::factory()->create();

    $pause->project->delete();

    expect($pause->fresh())->not->toBeNull();
    expect($pause->fresh()->project)->toBeNull();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan test tests/Feature/Hardening/ProjectHardeningPauseTest.php`
Expected: FAIL, 6 failed — `Class "App\Models\ProjectHardeningPause" not found`.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_09_21_120000_create_project_hardening_pauses_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_hardening_pauses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // dateTime, not timestamp: on MySQL/MariaDB without explicit_defaults_for_timestamp
            // the first NOT NULL timestamp column silently gets ON UPDATE CURRENT_TIMESTAMP,
            // and this row is updated several times after paused_until is set.
            $table->dateTime('paused_until');
            $table->timestamp('resumed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['resumed_at', 'paused_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_hardening_pauses');
    }
};
```

The generated index name `project_hardening_pauses_resumed_at_paused_until_index` is 55 characters, under MySQL's 64 limit.

- [ ] **Step 4: Write the model**

Create `app/Models/ProjectHardeningPause.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One "pause for download" of the block_archives .htaccess rule.
 *
 * Written BEFORE the plugin is called (write-ahead), so a pause whose response
 * got lost still has a row the hardening:resume-expired backstop can find.
 * A row is open while both resumed_at and failed_at are null.
 */
class ProjectHardeningPause extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'user_id',
        'paused_until',
        'resumed_at',
        'failed_at',
        'overdue_notified_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'paused_until' => 'datetime',
            'resumed_at' => 'datetime',
            'failed_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Rows that are neither resumed nor given up on.
     */
    public function scopeOpen($query)
    {
        return $query->whereNull('resumed_at')->whereNull('failed_at');
    }
}
```

- [ ] **Step 5: Write the factory**

Create `database/factories/ProjectHardeningPauseFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectHardeningPause;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectHardeningPauseFactory extends Factory
{
    protected $model = ProjectHardeningPause::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'user_id' => User::factory(),
            'paused_until' => now()->addHour(),
            'resumed_at' => null,
            'failed_at' => null,
            'overdue_notified_at' => null,
            'note' => null,
        ];
    }
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan test tests/Feature/Hardening/ProjectHardeningPauseTest.php`
Expected: PASS, 6 tests.

- [ ] **Step 7: Commit**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api
git add database/migrations/2026_09_21_120000_create_project_hardening_pauses_table.php \
        app/Models/ProjectHardeningPause.php \
        database/factories/ProjectHardeningPauseFactory.php \
        tests/Feature/Hardening/ProjectHardeningPauseTest.php
git commit -m "feat(hardening): project_hardening_pauses table, model and factory" \
  -m "One row per 'pause for download'. A row is open while resumed_at and failed_at
are both null. paused_until is a dateTime so MySQL never attaches ON UPDATE
CURRENT_TIMESTAMP to it." \
  -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 4: `HardeningResponseMapper` — the response-mapping table

**Files:**
- Modify: `tests/Pest.php` (append)
- Create: `app/Services/HardeningResponseMapper.php`
- Test: `tests/Unit/HardeningResponseMapperTest.php` (create)

**Interfaces:**
- Consumes: the result shape of `LsmService::hardeningRequest()` (Task 1): `['http' => int|null, 'json' => array|null]`.
- Produces: `App\Services\HardeningResponseMapper::map(array $result): array` returning `['outcome' => string, 'code' => int, 'body' => array]`.
  - `outcome` ∈ `ok | busy | failed | plugin_outdated | unauthorized | unreachable` (platform-internal, never sent to the client).
  - `code` is the HTTP status the API answers with: 200 / 409 / 422 / 409 / 502 / 502.
  - `body` for `ok`: `success, message, warnings, status`; for `busy`/`failed`: `success, reason, message, warnings, status`; for `plugin_outdated`: `success, reason, min_version, message`; for `unauthorized`/`unreachable`: `success, reason, message`. Only `ok`/`busy`/`failed` bodies have a `status` key (it may be null).
- Produces: `HardeningResponseMapper::MIN_PLUGIN_VERSION = '2.10.0'`.
- Produces: global test helpers `hardeningPluginStatus(string $archivesState = 'on', ?int $pauseUntil = null, bool $pauseOverdue = false): array` (the plugin's `status` object) and `hardeningPluginBody(bool $success = true, ?string $reason = null, ?array $status = null, array $warnings = [], string $message = 'Applied and verified'): array` (the whole plugin response body).

- [ ] **Step 1: Add the plugin-response test helpers**

Append to the end of `tests/Pest.php`:

```php

/**
 * The plugin's `status` object (spec 2026-09-21, REST endpoints) with a
 * chosen block_archives state.
 */
function hardeningPluginStatus(string $archivesState = 'on', ?int $pauseUntil = null, bool $pauseOverdue = false): array
{
    $rule = fn (string $state, bool $desired) => [
        'state' => $state,
        'desired' => $desired,
        'unsupported_reason' => null,
        'last_failure' => null,
    ];

    return [
        'plugin_version' => '2.10.0',
        'server' => 'Apache',
        'rules' => [
            'block_archives' => $rule($archivesState, true),
            'block_debug_log' => $rule('off', false),
            'block_uploads_php' => $rule('off', false),
        ],
        'pause_until' => $pauseUntil,
        'pause_overdue' => $pauseOverdue,
        'archive_attachments' => 0,
        'last_result' => null,
    ];
}

/**
 * A full plugin hardening response body: top level, no `data` wrapper.
 */
function hardeningPluginBody(bool $success = true, ?string $reason = null, ?array $status = null, array $warnings = [], string $message = 'Applied and verified'): array
{
    return [
        'success' => $success,
        'reason' => $reason,
        'message' => $message,
        'warnings' => $warnings,
        'status' => $status ?? hardeningPluginStatus(),
    ];
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/HardeningResponseMapperTest.php` (the `Unit` suite extends `Tests\TestCase` without `RefreshDatabase`, see `tests/Pest.php:18-19` — the mapper needs no database):

```php
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
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan test tests/Unit/HardeningResponseMapperTest.php`
Expected: FAIL, 18 failed — `Class "App\Services\HardeningResponseMapper" not found`.

- [ ] **Step 4: Write the mapper**

Create `app/Services/HardeningResponseMapper.php`:

```php
<?php

namespace App\Services;

/**
 * Turns a raw LsmService::hardeningRequest() result into the API's response
 * contract for the .htaccess hardening endpoints.
 *
 * Shared by HardeningController (HTTP responses) and the
 * hardening:resume-expired command (which only needs `outcome` and `status`).
 */
class HardeningResponseMapper
{
    public const MIN_PLUGIN_VERSION = '2.10.0';

    /**
     * @param  array{http: int|null, json: array|null}  $result
     * @return array{outcome: string, code: int, body: array}
     *         outcome: ok | busy | failed | plugin_outdated | unauthorized | unreachable
     */
    public static function map(array $result): array
    {
        $http = $result['http'] ?? null;
        $json = $result['json'] ?? null;

        if ($http === 404) {
            return [
                'outcome' => 'plugin_outdated',
                'code' => 409,
                'body' => [
                    'success' => false,
                    'reason' => 'plugin_outdated',
                    'min_version' => self::MIN_PLUGIN_VERSION,
                    'message' => 'Requires plugin ' . self::MIN_PLUGIN_VERSION . ' or newer — update the plugin',
                ],
            ];
        }

        if ($http === 401 || $http === 403) {
            return [
                'outcome' => 'unauthorized',
                'code' => 502,
                'body' => [
                    'success' => false,
                    'reason' => 'unauthorized',
                    'message' => 'The site rejected the platform API key',
                ],
            ];
        }

        // Strict bool check: the plugin contract is a real boolean, and anything
        // else (HTML error page, proxy JSON, "true" as a string) is not an answer.
        if ($http === 200 && is_array($json) && is_bool($json['success'] ?? null)) {
            $status = is_array($json['status'] ?? null) ? $json['status'] : null;
            $warnings = is_array($json['warnings'] ?? null) ? $json['warnings'] : [];

            if ($json['success']) {
                return [
                    'outcome' => 'ok',
                    'code' => 200,
                    'body' => [
                        'success' => true,
                        'message' => $json['message'] ?? 'Done',
                        'warnings' => $warnings,
                        'status' => $status,
                    ],
                ];
            }

            $busy = ($json['reason'] ?? null) === 'busy';

            return [
                'outcome' => $busy ? 'busy' : 'failed',
                'code' => $busy ? 409 : 422,
                'body' => [
                    'success' => false,
                    'reason' => $json['reason'] ?? null,
                    'message' => $json['message'] ?? 'The site could not apply the change',
                    'warnings' => $warnings,
                    'status' => $status,
                ],
            ];
        }

        // No response, timeout, 5xx, or anything we cannot read as a plugin answer.
        return [
            'outcome' => 'unreachable',
            'code' => 502,
            'body' => [
                'success' => false,
                'reason' => 'unreachable',
                'message' => 'Outcome unknown — refresh status',
            ],
        ];
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan test tests/Unit/HardeningResponseMapperTest.php`
Expected: PASS, 18 tests.

- [ ] **Step 6: Commit**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api
git add app/Services/HardeningResponseMapper.php tests/Pest.php tests/Unit/HardeningResponseMapperTest.php
git commit -m "feat(hardening): map plugin results to the API response contract" \
  -m "success -> 200, busy -> 409, other failure -> 422, 404 -> 409 plugin_outdated,
401/403 -> 502 unauthorized, anything unreadable -> 502 unreachable. One class so
the controller and the resume-expired command read a plugin answer the same way." \
  -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 5: `GET /hardening` — status, `can`, open pause, opportunistic close

**Files:**
- Create: `app/Http/Controllers/Api/V1/HardeningController.php`
- Modify: `routes/api.php` (insert after line 288)
- Test: `tests/Feature/Hardening/HardeningStatusEndpointTest.php` (create)

**Interfaces:**
- Consumes: `LsmService::hardeningRequest('GET', '/hardening/status')` (Task 1); abilities `pauseHardening` / `enableHardening` / `disableHardening` (Task 2); `ProjectHardeningPause::open()` and its factory (Task 3); `HardeningResponseMapper::MIN_PLUGIN_VERSION` (Task 4); test helpers `hardeningProject()`, `hardeningUser()`, `hardeningPluginStatus()`, `hardeningPluginBody()` (Tasks 1, 2, 4).
- Produces: route `GET /api/v1/projects/{project}/lsm/hardening`, name `api.v1.projects.lsm.hardening`, handled by `HardeningController::show(Project $project): JsonResponse`. Always 200 for an authorized user: `{reachable, plugin_outdated, min_version, status, open_pause, pause_overdue, can}`.
- Produces (private, used by Tasks 6-8): `platformState(Project $project, ?array $status): array` → `['open_pause' => array|null, 'pause_overdue' => bool, 'can' => ['pause' => bool, 'enable' => bool, 'disable' => bool]]`; `closeOpenPauses(Project $project, string $note, bool $skipInFlight = false): array` → the ids it closed (sets `resumed_at = now()` and `note`); `private const IN_FLIGHT_SECONDS = 180`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Hardening/HardeningStatusEndpointTest.php`:

```php
<?php

use App\Models\ProjectHardeningPause;
use Illuminate\Support\Facades\Http;

function fakeHardeningStatus(array $status): void
{
    Http::fake([
        '*/wp-json/lsm/v1/hardening/status*' => Http::response(hardeningPluginBody(true, null, $status, [], 'OK'), 200),
    ]);
}

test('a managing manager gets the full status shape with pause and enable allowed', function () {
    $status = hardeningPluginStatus('on');
    fakeHardeningStatus($status);
    $manager = hardeningUser('manager');
    $project = hardeningProject(['manager_id' => $manager->id]);

    $this->actingAs($manager)
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk()
        ->assertExactJson([
            'reachable' => true,
            'plugin_outdated' => false,
            'min_version' => '2.10.0',
            'status' => $status,
            'open_pause' => null,
            'pause_overdue' => false,
            'can' => ['pause' => true, 'enable' => true, 'disable' => false],
        ]);

    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && str_contains($request->url(), '/wp-json/lsm/v1/hardening/status?_='));
});

test('admins by role and by is_admin flag are allowed everything', function (string $role, array $extra) {
    fakeHardeningStatus(hardeningPluginStatus('on'));
    $project = hardeningProject();

    $this->actingAs(hardeningUser($role, $extra))
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk()
        ->assertJsonPath('can', ['pause' => true, 'enable' => true, 'disable' => true]);
})->with([
    'admin role' => ['admin', []],
    'manager with is_admin flag' => ['manager', ['is_admin' => true]],
]);

test('an assigned developer may only pause', function () {
    fakeHardeningStatus(hardeningPluginStatus('on'));
    $developer = hardeningUser('developer');
    $project = hardeningProject();
    $project->developers()->attach($developer->id);

    $this->actingAs($developer)
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk()
        ->assertJsonPath('can', ['pause' => true, 'enable' => false, 'disable' => false]);
});

test('an unassigned manager sees the status with every can flag false', function () {
    fakeHardeningStatus(hardeningPluginStatus('on'));
    $project = hardeningProject();

    $this->actingAs(hardeningUser('manager'))
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk()
        ->assertJsonPath('reachable', true)
        ->assertJsonPath('status.rules.block_archives.state', 'on')
        ->assertJsonPath('can', ['pause' => false, 'enable' => false, 'disable' => false]);
});

test('a viewer is refused and the plugin is never called', function () {
    Http::fake();
    $project = hardeningProject();

    $this->actingAs(hardeningUser('viewer'))
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertForbidden();

    Http::assertNothingSent();
});

test('a guest gets 401', function () {
    $project = hardeningProject();

    $this->getJson("/api/v1/projects/{$project->id}/lsm/hardening")->assertUnauthorized();
});

test('a plugin older than 2.10.0 renders a quiet plugin_outdated state, still HTTP 200', function () {
    Http::fake(['*' => Http::response(['code' => 'rest_no_route'], 404)]);
    $project = hardeningProject();

    $this->actingAs(hardeningUser('admin'))
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk()
        ->assertExactJson([
            'reachable' => true,
            'plugin_outdated' => true,
            'min_version' => '2.10.0',
            'status' => null,
            'open_pause' => null,
            'pause_overdue' => false,
            'can' => ['pause' => true, 'enable' => true, 'disable' => true],
        ]);
});

test('an unreachable site renders reachable:false, still HTTP 200', function (string $failure) {
    Http::fake(['*' => match ($failure) {
        'connection failure' => Http::failedConnection(),
        'http 500' => Http::response('server error', 500),
        'key rejected (401)' => Http::response(['code' => 'rest_forbidden'], 401),
        'html instead of json' => Http::response('<html>maintenance</html>', 200),
    }]);
    $project = hardeningProject();

    $this->actingAs(hardeningUser('admin'))
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk()
        ->assertJsonPath('reachable', false)
        ->assertJsonPath('plugin_outdated', false)
        ->assertJsonPath('status', null);
})->with(['connection failure', 'http 500', 'key rejected (401)', 'html instead of json']);

test('a project without an LSM key answers 200 reachable:false without calling anything', function () {
    Http::fake();
    $project = hardeningProject(['health_check_secret' => null]);

    $this->actingAs(hardeningUser('admin'))
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk()
        ->assertJsonPath('reachable', false);

    Http::assertNothingSent();
});

test('a running pause is reported as open_pause and is not overdue', function () {
    $until = now()->addMinutes(40);
    fakeHardeningStatus(hardeningPluginStatus('paused', $until->timestamp));
    $project = hardeningProject();
    $pause = ProjectHardeningPause::factory()->create([
        'project_id' => $project->id,
        'paused_until' => $until,
        'created_at' => now()->subMinutes(20),
    ]);

    $this->actingAs(hardeningUser('admin'))
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk()
        ->assertJsonPath('open_pause.id', $pause->id)
        ->assertJsonPath('open_pause.user_id', $pause->user_id)
        ->assertJsonPath('open_pause.paused_until', $pause->fresh()->paused_until->toJSON())
        ->assertJsonPath('pause_overdue', false);

    expect($pause->fresh()->resumed_at)->toBeNull();
});

test('an open row past paused_until makes pause_overdue true even when the site is unreachable', function () {
    Http::fake(['*' => Http::failedConnection()]);
    $project = hardeningProject();
    $pause = ProjectHardeningPause::factory()->create([
        'project_id' => $project->id,
        'paused_until' => now()->subMinutes(5),
        'created_at' => now()->subMinutes(65),
    ]);

    $this->actingAs(hardeningUser('admin'))
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk()
        ->assertJsonPath('reachable', false)
        ->assertJsonPath('open_pause.id', $pause->id)
        ->assertJsonPath('pause_overdue', true);

    expect($pause->fresh()->resumed_at)->toBeNull();
});

test('the plugin own pause_overdue flag is passed on when the platform has no row', function () {
    fakeHardeningStatus(hardeningPluginStatus('paused', now()->subMinutes(3)->timestamp, true));
    $project = hardeningProject();

    $this->actingAs(hardeningUser('admin'))
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk()
        ->assertJsonPath('open_pause', null)
        ->assertJsonPath('pause_overdue', true);
});

test('an open row is closed opportunistically only when the plugin confirms the rule is on', function (string $state) {
    fakeHardeningStatus(hardeningPluginStatus($state));
    $project = hardeningProject();
    $pause = ProjectHardeningPause::factory()->create([
        'project_id' => $project->id,
        'paused_until' => now()->subMinutes(5),
        'created_at' => now()->subMinutes(65),
    ]);

    $this->actingAs(hardeningUser('admin'))
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk()
        ->assertJsonPath('open_pause', null)
        ->assertJsonPath('pause_overdue', false);

    expect($pause->fresh()->resumed_at)->not->toBeNull();
    expect($pause->fresh()->note)->toBe('Closed from status: site no longer paused');
})->with(['on']);

test('a status other than a confirmed on does not close the row', function (string $state) {
    fakeHardeningStatus(hardeningPluginStatus($state));
    $project = hardeningProject();
    $pause = ProjectHardeningPause::factory()->create([
        'project_id' => $project->id,
        'paused_until' => now()->subMinutes(5),
        'created_at' => now()->subMinutes(65),
    ]);

    $this->actingAs(hardeningUser('admin'))
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk()
        ->assertJsonPath('open_pause.id', $pause->id);

    expect($pause->fresh()->resumed_at)->toBeNull();
})->with(['off', 'drift', 'manual']);

test('an unsupported rule whose pause_until is still set does not close the row', function () {
    fakeHardeningStatus(hardeningPluginStatus('unsupported', now()->subMinutes(5)->timestamp, true));
    $project = hardeningProject();
    $pause = ProjectHardeningPause::factory()->create([
        'project_id' => $project->id,
        'paused_until' => now()->subMinutes(5),
        'created_at' => now()->subMinutes(65),
    ]);

    $this->actingAs(hardeningUser('admin'))
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk()
        ->assertJsonPath('open_pause.id', $pause->id)
        ->assertJsonPath('pause_overdue', true);

    expect($pause->fresh()->resumed_at)->toBeNull();
});

test('a status that does not name a block_archives state closes nothing', function () {
    $status = hardeningPluginStatus('on');
    unset($status['rules']);
    fakeHardeningStatus($status);
    $project = hardeningProject();
    $pause = ProjectHardeningPause::factory()->create([
        'project_id' => $project->id,
        'paused_until' => now()->subMinutes(5),
        'created_at' => now()->subMinutes(65),
    ]);

    $this->actingAs(hardeningUser('admin'))
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk()
        ->assertJsonPath('open_pause.id', $pause->id);

    expect($pause->fresh()->resumed_at)->toBeNull();
});

test('a row younger than three minutes is left alone because its pause call may still be running', function () {
    fakeHardeningStatus(hardeningPluginStatus('on'));
    $project = hardeningProject();
    $pause = ProjectHardeningPause::factory()->create([
        'project_id' => $project->id,
        'paused_until' => now()->addHour(),
        'created_at' => now()->subSeconds(30),
    ]);

    $this->actingAs(hardeningUser('admin'))
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk()
        ->assertJsonPath('open_pause.id', $pause->id);

    expect($pause->fresh()->resumed_at)->toBeNull();
});

test('a pause row of another project is never touched or reported', function () {
    fakeHardeningStatus(hardeningPluginStatus('on'));
    $project = hardeningProject();
    $foreign = ProjectHardeningPause::factory()->create([
        'paused_until' => now()->subMinutes(5),
        'created_at' => now()->subMinutes(65),
    ]);

    $this->actingAs(hardeningUser('admin'))
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk()
        ->assertJsonPath('open_pause', null)
        ->assertJsonPath('pause_overdue', false);

    expect($foreign->fresh()->resumed_at)->toBeNull();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan test tests/Feature/Hardening/HardeningStatusEndpointTest.php`
Expected: FAIL, 24 failed — the route does not exist, every request answers 404.

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/Api/V1/HardeningController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectHardeningPause;
use App\Services\HardeningResponseMapper;
use App\Services\LsmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Managed .htaccess hardening (plugin 2.10.0+).
 *
 * Every plugin call goes through LsmService::hardeningRequest() and
 * HardeningResponseMapper — never through the unwrapping get()/post() helpers.
 */
class HardeningController extends Controller
{
    /**
     * A write-ahead pause row younger than this may belong to a pause call that
     * is still running (the POST timeout is 120 s), so status reads leave it alone.
     */
    private const IN_FLIGHT_SECONDS = 180;

    /**
     * Hardening status for the Security panel. Always answers 200 so an old
     * plugin or an unreachable site renders a quiet state, not an error.
     */
    public function show(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $result = LsmService::for($project)->hardeningRequest('GET', '/hardening/status');

        $status = $result['http'] === 200 && is_array($result['json']['status'] ?? null)
            ? $result['json']['status']
            : null;
        $pluginOutdated = $result['http'] === 404;

        // Opportunistic close: the plugin resumed on its own (or the rule was
        // changed on the site) and the platform row is the only thing still open.
        $archives = $status['rules']['block_archives']['state'] ?? null;
        // `unsupported` outranks `paused` in the plugin's state order, so a pause can
        // still be pending behind another state: only a cleared pause_until proves it ended.
        // Only a confirmed `on` proves the pause ended: mid-operation the plugin can report
        // `drift` (rule out of the file, pause_until not yet committed) — closing then would
        // leave a finisher-less host with no resumer at all.
        $stillPaused = $archives !== 'on' || ($status['pause_until'] ?? null) !== null;
        if (is_string($archives) && !$stillPaused) {
            $this->closeOpenPauses($project, 'Closed from status: site no longer paused', true);
        }

        return response()->json(array_merge([
            'reachable' => $status !== null || $pluginOutdated,
            'plugin_outdated' => $pluginOutdated,
            'min_version' => HardeningResponseMapper::MIN_PLUGIN_VERSION,
            'status' => $status,
        ], $this->platformState($project, $status)));
    }

    /**
     * The keys the platform adds next to the plugin status.
     */
    private function platformState(Project $project, ?array $status): array
    {
        $open = ProjectHardeningPause::open()
            ->where('project_id', $project->id)
            ->latest('id')
            ->first();

        return [
            'open_pause' => $open?->toArray(),
            'pause_overdue' => ($open !== null && $open->paused_until->isPast())
                || (bool) ($status['pause_overdue'] ?? false),
            'can' => [
                'pause' => Gate::allows('pauseHardening', $project),
                'enable' => Gate::allows('enableHardening', $project),
                'disable' => Gate::allows('disableHardening', $project),
            ],
        ];
    }

    /**
     * Close the project's open pause rows and return the ids that were closed.
     */
    private function closeOpenPauses(Project $project, string $note, bool $skipInFlight = false): array
    {
        $query = ProjectHardeningPause::open()->where('project_id', $project->id);

        if ($skipInFlight) {
            $query->where('created_at', '<', now()->subSeconds(self::IN_FLIGHT_SECONDS));
        }

        $ids = $query->pluck('id')->all();

        if ($ids !== []) {
            ProjectHardeningPause::whereIn('id', $ids)->update(['resumed_at' => now(), 'note' => $note]);
        }

        return $ids;
    }
}
```

Notes for the implementer: `use App\Http\Controllers\Controller;` is what `LsmController` and `SecurityScanController` import (not the `V1\Controller` in the same namespace). `$open?->toArray()` is the whole row — `open_pause` is deliberately the plain table row, see "Contract assumptions".

- [ ] **Step 4: Register the route**

In `routes/api.php`, inside the `projects/{project}/lsm` group (opens at line 227), directly after line 288:

```php
            Route::get('/security-headers/snippets', [V1\LsmController::class, 'getSecurityHeaderSnippets'])->name('security-headers.snippets');
```

and before the blank line + `// Security Scanning` comment (lines 289-290), insert:

```php

            // Server hardening (.htaccess)
            Route::get('/hardening', [V1\HardeningController::class, 'show'])->name('hardening');
```

The group already carries `auth:sanctum`, `RejectIntegrationTokens` and `EnsureTwoFactorEnrolled` (line 121). Do not add a `role:` middleware.

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan test tests/Feature/Hardening/HardeningStatusEndpointTest.php`
Expected: PASS, 24 tests.

- [ ] **Step 6: Commit**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api
git add app/Http/Controllers/Api/V1/HardeningController.php routes/api.php \
        tests/Feature/Hardening/HardeningStatusEndpointTest.php
git commit -m "feat(hardening): GET /hardening status endpoint that always answers 200" \
  -m "An old plugin (404) and an unreachable site render quiet states instead of a
red error. can{pause,enable,disable} comes from the Gate per user and project,
pause_overdue from the open pause row or the plugin's own flag, and an open row
is closed when a fresh status no longer shows block_archives paused — except
rows younger than 180 s, whose pause call may still be running." \
  -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 6: `POST /hardening/rule` — direction-dependent authorization, mapping table over HTTP

**Files:**
- Modify: `app/Http/Controllers/Api/V1/HardeningController.php`
- Modify: `routes/api.php` (the block added in Task 5)
- Test: `tests/Feature/Hardening/HardeningRuleEndpointTest.php` (create)

**Interfaces:**
- Consumes: `LsmService::hardeningRequest('POST', '/hardening/rule', ['rule' => string, 'enabled' => bool], 120)` (Task 1); `HardeningResponseMapper::map(array $result): array{outcome, code, body}` (Task 4); from Task 5 the private helpers `platformState(Project $project, ?array $status): array` and `closeOpenPauses(Project $project, string $note, bool $skipInFlight = false): array`; test helpers from Tasks 1, 2, 4; `ProjectHardeningPause::factory()` (Task 3).
- Produces: route `POST /api/v1/projects/{project}/lsm/hardening/rule`, name `api.v1.projects.lsm.hardening.rule`, handled by `HardeningController::setRule(Project $project, Request $request): JsonResponse`.
- Produces (private, used by Tasks 7, 8, 13): `respond(Project $project, array $mapped): JsonResponse` — sends `$mapped['body']` with `$mapped['code']`, adding `open_pause`, `pause_overdue`, `can` when the body has a `status` key; `private const RULES`; `private const POST_TIMEOUT = 120`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Hardening/HardeningRuleEndpointTest.php`:

```php
<?php

use App\Models\Project;
use App\Models\ProjectHardeningPause;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function fakeHardeningRule(array $body, int $http = 200): void
{
    Http::fake(['*/wp-json/lsm/v1/hardening/rule' => Http::response($body, $http)]);
}

/** @return array{0: User, 1: Project} */
function managedHardeningProject(): array
{
    $manager = hardeningUser('manager');

    return [$manager, hardeningProject(['manager_id' => $manager->id])];
}

// ---------------------------------------------------------------------------
// Authorization: direction-dependent, before validation, fail closed
// ---------------------------------------------------------------------------

test('a managing manager can turn a rule on and the plugin receives real booleans with a 120 s timeout', function () {
    $seenTimeout = null;
    Http::fake(function ($request, array $options) use (&$seenTimeout) {
        $seenTimeout = $options['timeout'] ?? null;

        return Http::response(hardeningPluginBody(true, null, hardeningPluginStatus('on')), 200);
    });
    [$manager, $project] = managedHardeningProject();

    $this->actingAs($manager)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_archives', 'enabled' => true])
        ->assertOk();

    expect($seenTimeout)->toBe(120);
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://client.example.com/wp-json/lsm/v1/hardening/rule'
        && $request['rule'] === 'block_archives'
        && $request['enabled'] === true);
});

test('a managing manager cannot turn a rule off', function () {
    Http::fake();
    [$manager, $project] = managedHardeningProject();

    $this->actingAs($manager)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_archives', 'enabled' => false])
        ->assertForbidden();

    Http::assertNothingSent();
});

test('an assigned developer cannot turn a rule on', function () {
    Http::fake();
    $developer = hardeningUser('developer');
    $project = hardeningProject();
    $project->developers()->attach($developer->id);

    $this->actingAs($developer)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_archives', 'enabled' => true])
        ->assertForbidden();

    Http::assertNothingSent();
});

test('an unassigned manager cannot turn a rule on', function () {
    Http::fake();
    $project = hardeningProject();

    $this->actingAs(hardeningUser('manager'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_archives', 'enabled' => true])
        ->assertForbidden();

    Http::assertNothingSent();
});

test('admins by role and by is_admin flag can turn a rule off', function (string $role, array $extra) {
    fakeHardeningRule(hardeningPluginBody(true, null, hardeningPluginStatus('off')));
    $project = hardeningProject();

    $this->actingAs(hardeningUser($role, $extra))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_debug_log', 'enabled' => false])
        ->assertOk();

    Http::assertSent(fn ($request) => $request['rule'] === 'block_debug_log' && $request['enabled'] === false);
})->with([
    'admin role' => ['admin', []],
    'manager with is_admin flag' => ['manager', ['is_admin' => true]],
]);

test('a missing or garbage enabled counts as a disable: 403 for a manager, never a 422', function (array $payload) {
    Http::fake();
    [$manager, $project] = managedHardeningProject();

    $this->actingAs($manager)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", $payload)
        ->assertForbidden();

    Http::assertNothingSent();
})->with([
    'enabled missing' => [['rule' => 'block_archives']],
    'enabled garbage' => [['rule' => 'block_archives', 'enabled' => 'banana']],
    'enabled null' => [['rule' => 'block_archives', 'enabled' => null]],
    'rule also invalid' => [['rule' => 'nope']],
]);

test('an admin with a missing enabled gets the validation 422', function () {
    Http::fake();
    $project = hardeningProject();

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_archives'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['enabled']);

    Http::assertNothingSent();
});

test('a truthy string passes the enable gate but is rejected by validation before anything is sent', function () {
    Http::fake();
    [$manager, $project] = managedHardeningProject();

    $this->actingAs($manager)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_archives', 'enabled' => 'yes'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['enabled']);

    Http::assertNothingSent();
});

test('an unknown rule key is rejected by validation before anything is sent', function () {
    Http::fake();
    [$manager, $project] = managedHardeningProject();

    $this->actingAs($manager)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_everything', 'enabled' => true])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rule']);

    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------
// Response mapping, one test per row of the spec table
// ---------------------------------------------------------------------------

test('mapping row 1: plugin success becomes 200 with status and the platform keys', function () {
    $status = hardeningPluginStatus('on');
    fakeHardeningRule(hardeningPluginBody(true, null, $status, ['already_blocked_elsewhere']));
    [$manager, $project] = managedHardeningProject();

    $this->actingAs($manager)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_archives', 'enabled' => true])
        ->assertOk()
        ->assertExactJson([
            'success' => true,
            'message' => 'Applied and verified',
            'warnings' => ['already_blocked_elsewhere'],
            'status' => $status,
            'open_pause' => null,
            'pause_overdue' => false,
            'can' => ['pause' => true, 'enable' => true, 'disable' => false],
        ]);
});

test('mapping row 2: plugin busy becomes 409 with reason busy, status and the platform keys', function () {
    $status = hardeningPluginStatus('off');
    fakeHardeningRule(hardeningPluginBody(false, 'busy', $status, [], 'Another hardening operation is running'));
    [$manager, $project] = managedHardeningProject();

    $this->actingAs($manager)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_archives', 'enabled' => true])
        ->assertStatus(409)
        ->assertExactJson([
            'success' => false,
            'reason' => 'busy',
            'message' => 'Another hardening operation is running',
            'warnings' => [],
            'status' => $status,
            'open_pause' => null,
            'pause_overdue' => false,
            'can' => ['pause' => true, 'enable' => true, 'disable' => false],
        ]);
});

test('mapping row 3: any other plugin failure becomes 422 with the plugin reason and no validation errors key', function () {
    $status = hardeningPluginStatus('off');
    fakeHardeningRule(hardeningPluginBody(false, 'rule_ineffective', $status, [], 'The .zip probe was served by a front-end nginx'));
    [$manager, $project] = managedHardeningProject();

    $this->actingAs($manager)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_archives', 'enabled' => true])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('reason', 'rule_ineffective')
        ->assertJsonPath('message', 'The .zip probe was served by a front-end nginx')
        ->assertJsonPath('status', $status)
        ->assertJsonPath('can.enable', true)
        ->assertJsonMissingPath('errors');
});

test('mapping row 4: a 404 from the plugin becomes 409 plugin_outdated', function () {
    fakeHardeningRule(['code' => 'rest_no_route'], 404);
    [$manager, $project] = managedHardeningProject();

    $this->actingAs($manager)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_archives', 'enabled' => true])
        ->assertStatus(409)
        ->assertExactJson([
            'success' => false,
            'reason' => 'plugin_outdated',
            'min_version' => '2.10.0',
            'message' => 'Requires plugin 2.10.0 or newer — update the plugin',
        ]);
});

test('mapping row 5: a 401 or 403 from the plugin becomes 502 unauthorized', function (int $http) {
    fakeHardeningRule(['code' => 'rest_forbidden'], $http);
    [$manager, $project] = managedHardeningProject();

    $this->actingAs($manager)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_archives', 'enabled' => true])
        ->assertStatus(502)
        ->assertExactJson([
            'success' => false,
            'reason' => 'unauthorized',
            'message' => 'The site rejected the platform API key',
        ]);
})->with([401, 403]);

test('mapping row 6: no response, a timeout or a 5xx becomes 502 unreachable', function (string $failure) {
    Http::fake(['*' => match ($failure) {
        'connection failure' => Http::failedConnection(),
        'http 500' => Http::response('server error', 500),
        'http 504' => Http::response('gateway timeout', 504),
    }]);
    [$manager, $project] = managedHardeningProject();

    $this->actingAs($manager)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_archives', 'enabled' => true])
        ->assertStatus(502)
        ->assertExactJson([
            'success' => false,
            'reason' => 'unreachable',
            'message' => 'Outcome unknown — refresh status',
        ]);
})->with(['connection failure', 'http 500', 'http 504']);

// ---------------------------------------------------------------------------
// Pause rows
// ---------------------------------------------------------------------------

test('turning block_archives off closes the open pause row, even a fresh one', function () {
    fakeHardeningRule(hardeningPluginBody(true, null, hardeningPluginStatus('off')));
    $project = hardeningProject();
    $pause = ProjectHardeningPause::factory()->create(['project_id' => $project->id, 'created_at' => now()->subSeconds(20)]);

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_archives', 'enabled' => false])
        ->assertOk()
        ->assertJsonPath('open_pause', null);

    expect($pause->fresh()->resumed_at)->not->toBeNull();
    expect($pause->fresh()->note)->toBe('Closed: block_archives turned off');
});

test('a failed attempt to turn block_archives off leaves the pause row open', function () {
    fakeHardeningRule(hardeningPluginBody(false, 'loopback_blocked', hardeningPluginStatus('paused', now()->addMinutes(10)->timestamp)));
    $project = hardeningProject();
    $pause = ProjectHardeningPause::factory()->create(['project_id' => $project->id]);

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_archives', 'enabled' => false])
        ->assertStatus(422)
        ->assertJsonPath('open_pause.id', $pause->id);

    expect($pause->fresh()->resumed_at)->toBeNull();
});

test('turning a different rule off leaves the pause row open', function () {
    fakeHardeningRule(hardeningPluginBody(true, null, hardeningPluginStatus('paused', now()->addMinutes(10)->timestamp)));
    $project = hardeningProject();
    $pause = ProjectHardeningPause::factory()->create(['project_id' => $project->id]);

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_debug_log', 'enabled' => false])
        ->assertOk();

    expect($pause->fresh()->resumed_at)->toBeNull();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan test tests/Feature/Hardening/HardeningRuleEndpointTest.php`
Expected: FAIL, 25 failed — the route does not exist (404).

- [ ] **Step 3: Add the import and the constants**

In `app/Http/Controllers/Api/V1/HardeningController.php` add below `use Illuminate\Http\JsonResponse;`:

```php
use Illuminate\Http\Request;
```

and directly below the line `    private const IN_FLIGHT_SECONDS = 180;` add (one blank line between):

```php

    private const RULES = ['block_archives', 'block_debug_log', 'block_uploads_php'];

    /** The plugin's apply procedure self-tests over HTTP; worst case is about 60 s. */
    private const POST_TIMEOUT = 120;
```

- [ ] **Step 4: Add `setRule()` and `respond()`**

In the same file, directly **above** the docblock that starts with `* The keys the platform adds next to the plugin status.` (the `platformState()` method), insert:

```php
    /**
     * Turn one rule on or off.
     */
    public function setRule(Project $project, Request $request): JsonResponse
    {
        // The ability depends on the direction, so authorize on the cast bool
        // BEFORE validating: a missing or garbage `enabled` counts as a disable
        // (fail closed) — a non-admin gets 403, an admin gets the 422 below.
        $enabled = $request->boolean('enabled');
        Gate::authorize($enabled ? 'enableHardening' : 'disableHardening', $project);

        $validated = $request->validate([
            'rule' => 'required|string|in:' . implode(',', self::RULES),
            'enabled' => 'required|boolean',
        ]);

        $mapped = HardeningResponseMapper::map(
            LsmService::for($project)->hardeningRequest('POST', '/hardening/rule', [
                'rule' => $validated['rule'],
                'enabled' => $enabled,
            ], self::POST_TIMEOUT)
        );

        if ($mapped['outcome'] === 'ok' && $validated['rule'] === 'block_archives' && !$enabled) {
            $this->closeOpenPauses($project, 'Closed: block_archives turned off');
        }

        return $this->respond($project, $mapped);
    }

    /**
     * Send a mapped plugin result. Bodies that carry a plugin status (ok, busy,
     * failed) also get open_pause, pause_overdue and can.
     */
    private function respond(Project $project, array $mapped): JsonResponse
    {
        $body = $mapped['body'];

        if (array_key_exists('status', $body)) {
            $body = array_merge($body, $this->platformState($project, $body['status']));
        }

        return response()->json($body, $mapped['code']);
    }

```

`Gate::authorize()` must stay above `$request->validate()`: it is the only place in this codebase where the ability depends on the payload, and the order is what makes a missing `enabled` a 403 instead of a 422 for non-admins.

- [ ] **Step 5: Register the route**

In `routes/api.php`, directly below the line added in Task 5

```php
            Route::get('/hardening', [V1\HardeningController::class, 'show'])->name('hardening');
```

add:

```php
            Route::post('/hardening/rule', [V1\HardeningController::class, 'setRule'])->name('hardening.rule');
```

(The throttle is added to all three POST routes in Task 9.)

- [ ] **Step 6: Run the tests to verify they pass**

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan test tests/Feature/Hardening/HardeningRuleEndpointTest.php tests/Feature/Hardening/HardeningStatusEndpointTest.php`
Expected: PASS, 49 tests.

- [ ] **Step 7: Commit**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api
git add app/Http/Controllers/Api/V1/HardeningController.php routes/api.php \
        tests/Feature/Hardening/HardeningRuleEndpointTest.php
git commit -m "feat(hardening): POST /hardening/rule with fail-closed enable/disable authorization" \
  -m "The ability is chosen from the cast boolean before validation, so a missing or
garbage 'enabled' is treated as a disable: 403 for non-admins, 422 for admins.
Only the cast bool is forwarded to the plugin. Turning block_archives off
closes the project's open pause rows." \
  -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 7: `POST /hardening/pause` — write-ahead pause row

**Files:**
- Modify: `app/Http/Controllers/Api/V1/HardeningController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Hardening/HardeningPauseEndpointTest.php` (create)

**Interfaces:**
- Consumes: `LsmService::hardeningRequest('POST', '/hardening/pause', ['minutes' => int], 120)` (Task 1) and the existing `LsmService::isConfigured(): bool` on the same instance — an unconfigured project yields `['http' => null, 'json' => null]` without anything being sent; ability `pauseHardening` (Task 2); `ProjectHardeningPause` model, `open()` scope, factory (Task 3); `HardeningResponseMapper::map()` — `outcome` ∈ `ok|busy|failed|plugin_outdated|unauthorized|unreachable`, `body['status']['pause_until']` is a unix int on success (Task 4); from the controller: `closeOpenPauses(Project $project, string $note, bool $skipInFlight = false): array` (returns closed ids), `respond(Project $project, array $mapped): JsonResponse`, `self::POST_TIMEOUT` (Tasks 5, 6).
- Produces: route `POST /api/v1/projects/{project}/lsm/hardening/pause`, name `api.v1.projects.lsm.hardening.pause`, handled by `HardeningController::pause(Project $project, Request $request): JsonResponse`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Hardening/HardeningPauseEndpointTest.php`:

```php
<?php

use App\Models\ProjectHardeningPause;
use Illuminate\Support\Facades\Http;

function fakeHardeningPause(array $body, int $http = 200): void
{
    Http::fake(['*/wp-json/lsm/v1/hardening/pause' => Http::response($body, $http)]);
}

// ---------------------------------------------------------------------------
// Authorization and validation
// ---------------------------------------------------------------------------

test('an assigned developer can pause', function () {
    fakeHardeningPause(hardeningPluginBody(true, null, hardeningPluginStatus('paused', now()->addMinutes(15)->timestamp)));
    $developer = hardeningUser('developer');
    $project = hardeningProject();
    $project->developers()->attach($developer->id);

    $this->actingAs($developer)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 15])
        ->assertOk()
        ->assertJsonPath('can', ['pause' => true, 'enable' => false, 'disable' => false]);
});

test('an unassigned manager cannot pause: 403, no row, nothing sent', function () {
    Http::fake();
    $project = hardeningProject();

    $this->actingAs(hardeningUser('manager'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 60])
        ->assertForbidden();

    expect(ProjectHardeningPause::count())->toBe(0);
    Http::assertNothingSent();
});

test('authorization comes before validation on pause', function () {
    Http::fake();
    $project = hardeningProject();

    $this->actingAs(hardeningUser('viewer'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 45])
        ->assertForbidden();
});

test('minutes must be 15, 30 or 60: no row, nothing sent', function (array $payload) {
    Http::fake();
    $project = hardeningProject();

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['minutes']);

    expect(ProjectHardeningPause::count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'missing' => [[]],
    '45' => [['minutes' => 45]],
    '0' => [['minutes' => 0]],
    'text' => [['minutes' => 'an hour']],
    'float' => [['minutes' => 15.5]],
]);

// ---------------------------------------------------------------------------
// Write-ahead row lifecycle
// ---------------------------------------------------------------------------

test('the pause row is written before the plugin is called', function () {
    $openRowsDuringCall = null;
    $seenTimeout = null;
    Http::fake(function ($request, array $options) use (&$openRowsDuringCall, &$seenTimeout) {
        $openRowsDuringCall = ProjectHardeningPause::open()->count();
        $seenTimeout = $options['timeout'] ?? null;

        return Http::response(hardeningPluginBody(true, null, hardeningPluginStatus('paused', now()->addHour()->timestamp)), 200);
    });
    $project = hardeningProject();

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 60])
        ->assertOk();

    expect($openRowsDuringCall)->toBe(1);
    expect($seenTimeout)->toBe(120);
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://client.example.com/wp-json/lsm/v1/hardening/pause'
        && $request['minutes'] === 60);
});

test('on success the row takes paused_until from the plugin pause_until and is reported as open_pause', function () {
    $pluginUntil = now()->addMinutes(30)->addSeconds(7)->timestamp;
    fakeHardeningPause(hardeningPluginBody(true, null, hardeningPluginStatus('paused', $pluginUntil)));
    $admin = hardeningUser('admin');
    $project = hardeningProject();

    $response = $this->actingAs($admin)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 30])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('pause_overdue', false);

    $pause = ProjectHardeningPause::sole();
    expect($pause->project_id)->toBe($project->id);
    expect($pause->user_id)->toBe($admin->id);
    expect($pause->paused_until->timestamp)->toBe($pluginUntil);
    expect($pause->resumed_at)->toBeNull();
    $response->assertJsonPath('open_pause.id', $pause->id);
});

test('on success without a usable pause_until the write-ahead value stays', function () {
    $this->freezeTime();
    fakeHardeningPause(hardeningPluginBody(true, null, hardeningPluginStatus('paused', null)));
    $project = hardeningProject();

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 15])
        ->assertOk();

    expect(ProjectHardeningPause::sole()->paused_until->timestamp)->toBe(now()->addMinutes(15)->timestamp);
});

test('an explicit success:false deletes the row', function (string $reason, int $expectedHttp) {
    fakeHardeningPause(hardeningPluginBody(false, $reason, hardeningPluginStatus('on'), [], 'Pause failed'));
    $project = hardeningProject();

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 60])
        ->assertStatus($expectedHttp)
        ->assertJsonPath('reason', $reason)
        ->assertJsonPath('open_pause', null);

    expect(ProjectHardeningPause::count())->toBe(0);
})->with([
    'foreign rule still blocks' => ['pause_ineffective_foreign_rule', 422],
    'rule not on' => ['not_enabled', 422],
    'lock busy' => ['busy', 409],
]);

test('no response keeps the row so the backstop can reconcile', function (string $failure) {
    $this->freezeTime();
    Http::fake(['*' => match ($failure) {
        'connection failure' => Http::failedConnection(),
        'http 500' => Http::response('server error', 500),
    }]);
    $project = hardeningProject();

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 60])
        ->assertStatus(502)
        ->assertJsonPath('reason', 'unreachable');

    $pause = ProjectHardeningPause::sole();
    expect($pause->resumed_at)->toBeNull();
    expect($pause->paused_until->timestamp)->toBe(now()->addMinutes(60)->timestamp);
})->with(['connection failure', 'http 500']);

test('a site that answered without pausing (old plugin, rejected key) leaves no row behind', function (int $http, int $expectedHttp, string $reason) {
    fakeHardeningPause(['code' => 'rest_error'], $http);
    $project = hardeningProject();

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 60])
        ->assertStatus($expectedHttp)
        ->assertJsonPath('reason', $reason);

    expect(ProjectHardeningPause::count())->toBe(0);
})->with([
    'old plugin' => [404, 409, 'plugin_outdated'],
    'key rejected' => [401, 502, 'unauthorized'],
]);

test('pausing while paused closes the earlier row and leaves exactly one open row', function () {
    $pluginUntil = now()->addMinutes(60)->timestamp;
    fakeHardeningPause(hardeningPluginBody(true, null, hardeningPluginStatus('paused', $pluginUntil)));
    $project = hardeningProject();
    $earlier = ProjectHardeningPause::factory()->create(['project_id' => $project->id, 'paused_until' => now()->addMinutes(5)]);

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 60])
        ->assertOk();

    expect($earlier->fresh()->resumed_at)->not->toBeNull();
    expect($earlier->fresh()->note)->toBe('Superseded by a new pause');

    $open = ProjectHardeningPause::open()->where('project_id', $project->id)->get();
    expect($open)->toHaveCount(1);
    expect($open->first()->id)->not->toBe($earlier->id);
    expect($open->first()->paused_until->timestamp)->toBe($pluginUntil);
});

test('a failed pause-while-paused reopens the row it superseded', function () {
    fakeHardeningPause(hardeningPluginBody(false, 'busy', hardeningPluginStatus('paused', now()->addMinutes(5)->timestamp)));
    $project = hardeningProject();
    $earlier = ProjectHardeningPause::factory()->create(['project_id' => $project->id, 'paused_until' => now()->addMinutes(5)]);

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 60])
        ->assertStatus(409)
        ->assertJsonPath('open_pause.id', $earlier->id);

    expect(ProjectHardeningPause::count())->toBe(1);
    expect($earlier->fresh()->resumed_at)->toBeNull();
    expect($earlier->fresh()->note)->toBeNull();
});

test('an open row of another project is not superseded', function () {
    fakeHardeningPause(hardeningPluginBody(true, null, hardeningPluginStatus('paused', now()->addHour()->timestamp)));
    $project = hardeningProject();
    $foreign = ProjectHardeningPause::factory()->create();

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 60])
        ->assertOk();

    expect($foreign->fresh()->resumed_at)->toBeNull();
});

test('a project without an LSM key leaves no row behind: nothing was sent, so nothing was paused', function () {
    Http::fake();
    $project = hardeningProject(['health_check_secret' => null]);
    $earlier = ProjectHardeningPause::factory()->create(['project_id' => $project->id]);

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 15])
        ->assertStatus(502)
        ->assertJsonPath('reason', 'unreachable');

    expect(ProjectHardeningPause::count())->toBe(1);
    expect($earlier->fresh()->resumed_at)->toBeNull();
    Http::assertNothingSent();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan test tests/Feature/Hardening/HardeningPauseEndpointTest.php`
Expected: FAIL, 22 failed — the route does not exist (404).

- [ ] **Step 3: Add the import**

In `app/Http/Controllers/Api/V1/HardeningController.php` add below `use Illuminate\Http\Request;`:

```php
use Illuminate\Support\Carbon;
```

- [ ] **Step 4: Add `pause()`**

In the same file, directly **above** the docblock that starts with `* Send a mapped plugin result.` (the `respond()` method), insert:

```php
    /**
     * Pause the archive rule for a download (15, 30 or 60 minutes).
     */
    public function pause(Project $project, Request $request): JsonResponse
    {
        Gate::authorize('pauseHardening', $project);

        $validated = $request->validate([
            'minutes' => 'required|integer|in:15,30,60',
        ]);
        $minutes = (int) $validated['minutes'];

        // Write-ahead: the row exists before the plugin is called, so a pause
        // whose response gets lost is still found by hardening:resume-expired.
        $superseded = $this->closeOpenPauses($project, 'Superseded by a new pause');
        $pause = ProjectHardeningPause::create([
            'project_id' => $project->id,
            'user_id' => auth()->id(),
            'paused_until' => now()->addMinutes($minutes),
        ]);

        $lsm = LsmService::for($project);
        $mapped = HardeningResponseMapper::map(
            $lsm->hardeningRequest('POST', '/hardening/pause', ['minutes' => $minutes], self::POST_TIMEOUT)
        );

        if ($mapped['outcome'] === 'ok') {
            $until = $mapped['body']['status']['pause_until'] ?? null;
            if (is_int($until)) {
                $pause->update(['paused_until' => Carbon::createFromTimestamp($until)]);
            }
        } elseif ($mapped['outcome'] !== 'unreachable' || !$lsm->isConfigured()) {
            // The site answered and did not pause (success:false, old plugin, key
            // rejected), or nothing was sent at all (no LSM key): drop the row and
            // reopen whatever this call superseded.
            $pause->delete();
            ProjectHardeningPause::whereIn('id', $superseded)->update(['resumed_at' => null, 'note' => null]);
        }
        // unreachable: outcome unknown — the row stays and the backstop reconciles.

        return $this->respond($project, $mapped);
    }

```

The order inside the method is the feature: close earlier rows → insert → call the plugin → reconcile. The `!$lsm->isConfigured()` half of the `elseif` is not redundant: for a project without an LSM key `hardeningRequest()` sends nothing and returns `['http' => null, 'json' => null]`, which maps to `unreachable` — without the check the write-ahead row would stay, the superseded row would stay closed, and the panel would show a pause (and later an overdue alert) for a call that never left the platform. `auth()->id()` is how this codebase reads the acting user in controllers (`BackupController.php:87`, `SecurityScanController.php:73`).

- [ ] **Step 5: Register the route**

In `routes/api.php`, directly below

```php
            Route::post('/hardening/rule', [V1\HardeningController::class, 'setRule'])->name('hardening.rule');
```

add:

```php
            Route::post('/hardening/pause', [V1\HardeningController::class, 'pause'])->name('hardening.pause');
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan test tests/Feature/Hardening/HardeningPauseEndpointTest.php tests/Feature/Hardening/HardeningRuleEndpointTest.php tests/Feature/Hardening/HardeningStatusEndpointTest.php`
Expected: PASS, 71 tests.

- [ ] **Step 7: Commit**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api
git add app/Http/Controllers/Api/V1/HardeningController.php routes/api.php \
        tests/Feature/Hardening/HardeningPauseEndpointTest.php
git commit -m "feat(hardening): POST /hardening/pause with a write-ahead pause row" \
  -m "The row is inserted before the plugin is called. A site that answered without
pausing (success:false, old plugin, rejected key) deletes it and reopens the
row it superseded, and so does a project without an LSM key, where nothing is
sent at all; no response keeps it for the backstop; success takes paused_until
from the plugin's pause_until." \
  -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 8: `POST /hardening/resume`

**Files:**
- Modify: `app/Http/Controllers/Api/V1/HardeningController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Hardening/HardeningResumeEndpointTest.php` (create)

**Interfaces:**
- Consumes: `LsmService::hardeningRequest('POST', '/hardening/resume', [], 120)` (Task 1); ability `pauseHardening` — resume uses the same ability as pause (Task 2); `HardeningResponseMapper::map()` (Task 4); from the controller: `closeOpenPauses(Project $project, string $note, bool $skipInFlight = false): array`, `respond(Project $project, array $mapped): JsonResponse`, `self::POST_TIMEOUT` (Tasks 5, 6).
- Produces: route `POST /api/v1/projects/{project}/lsm/hardening/resume`, name `api.v1.projects.lsm.hardening.resume`, handled by `HardeningController::resume(Project $project): JsonResponse`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Hardening/HardeningResumeEndpointTest.php`:

```php
<?php

use App\Models\ProjectHardeningPause;
use Illuminate\Support\Facades\Http;

function fakeHardeningResume(array $body, int $http = 200): void
{
    Http::fake(['*/wp-json/lsm/v1/hardening/resume' => Http::response($body, $http)]);
}

test('an assigned developer can resume and the plugin gets a POST with a 120 s timeout', function () {
    $seenTimeout = null;
    Http::fake(function ($request, array $options) use (&$seenTimeout) {
        $seenTimeout = $options['timeout'] ?? null;

        return Http::response(hardeningPluginBody(true, null, hardeningPluginStatus('on')), 200);
    });
    $developer = hardeningUser('developer');
    $project = hardeningProject();
    $project->developers()->attach($developer->id);

    $this->actingAs($developer)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/resume")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('status.rules.block_archives.state', 'on');

    expect($seenTimeout)->toBe(120);
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://client.example.com/wp-json/lsm/v1/hardening/resume');
});

test('an unassigned manager cannot resume: 403 and nothing sent', function () {
    Http::fake();
    $project = hardeningProject();

    $this->actingAs(hardeningUser('manager'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/resume")
        ->assertForbidden();

    Http::assertNothingSent();
});

test('a successful resume closes the open row, even a fresh one', function () {
    fakeHardeningResume(hardeningPluginBody(true, null, hardeningPluginStatus('on')));
    $project = hardeningProject();
    $pause = ProjectHardeningPause::factory()->create(['project_id' => $project->id, 'created_at' => now()->subSeconds(20)]);

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/resume")
        ->assertOk()
        ->assertJsonPath('open_pause', null)
        ->assertJsonPath('pause_overdue', false);

    expect($pause->fresh()->resumed_at)->not->toBeNull();
    expect($pause->fresh()->note)->toBe('Closed: resumed from the platform');
});

test('resume is an idempotent no-op when nothing is paused', function () {
    fakeHardeningResume(hardeningPluginBody(true, null, hardeningPluginStatus('on'), [], 'Nothing to resume'));
    $project = hardeningProject();

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/resume")
        ->assertOk()
        ->assertJsonPath('message', 'Nothing to resume');

    expect(ProjectHardeningPause::count())->toBe(0);
});

test('a resume that did not succeed leaves the row open and reports it', function (string $case, int $expectedHttp, string $reason) {
    Http::fake(['*' => match ($case) {
        'explicit failure' => Http::response(hardeningPluginBody(false, 'asset_broken', hardeningPluginStatus('paused', now()->subMinutes(5)->timestamp, true)), 200),
        'busy' => Http::response(hardeningPluginBody(false, 'busy', hardeningPluginStatus('paused', now()->subMinutes(5)->timestamp, true)), 200),
        'unreachable' => Http::failedConnection(),
        'old plugin' => Http::response(['code' => 'rest_no_route'], 404),
        'key rejected' => Http::response(['code' => 'rest_forbidden'], 403),
    }]);
    $project = hardeningProject();
    $pause = ProjectHardeningPause::factory()->create([
        'project_id' => $project->id,
        'paused_until' => now()->subMinutes(5),
        'created_at' => now()->subMinutes(65),
    ]);

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/resume")
        ->assertStatus($expectedHttp)
        ->assertJsonPath('success', false)
        ->assertJsonPath('reason', $reason);

    expect($pause->fresh()->resumed_at)->toBeNull();
    expect($pause->fresh()->failed_at)->toBeNull();
})->with([
    ['explicit failure', 422, 'asset_broken'],
    ['busy', 409, 'busy'],
    ['unreachable', 502, 'unreachable'],
    ['old plugin', 409, 'plugin_outdated'],
    ['key rejected', 502, 'unauthorized'],
]);

test('a failed resume still tells the panel that the pause is overdue', function () {
    fakeHardeningResume(hardeningPluginBody(false, 'asset_broken', hardeningPluginStatus('paused', now()->subMinutes(5)->timestamp, true)));
    $project = hardeningProject();
    $pause = ProjectHardeningPause::factory()->create([
        'project_id' => $project->id,
        'paused_until' => now()->subMinutes(5),
        'created_at' => now()->subMinutes(65),
    ]);

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/resume")
        ->assertStatus(422)
        ->assertJsonPath('open_pause.id', $pause->id)
        ->assertJsonPath('pause_overdue', true);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan test tests/Feature/Hardening/HardeningResumeEndpointTest.php`
Expected: FAIL, 10 failed — the route does not exist (404).

- [ ] **Step 3: Add `resume()`**

In `app/Http/Controllers/Api/V1/HardeningController.php`, directly **above** the docblock that starts with `* Send a mapped plugin result.` (the `respond()` method, i.e. below `pause()`), insert:

```php
    /**
     * End a pause early ("Re-enable now"). A no-op on the site when nothing is paused.
     */
    public function resume(Project $project): JsonResponse
    {
        Gate::authorize('pauseHardening', $project);

        $mapped = HardeningResponseMapper::map(
            LsmService::for($project)->hardeningRequest('POST', '/hardening/resume', [], self::POST_TIMEOUT)
        );

        if ($mapped['outcome'] === 'ok') {
            $this->closeOpenPauses($project, 'Closed: resumed from the platform');
        }

        return $this->respond($project, $mapped);
    }

```

- [ ] **Step 4: Register the route**

In `routes/api.php`, directly below

```php
            Route::post('/hardening/pause', [V1\HardeningController::class, 'pause'])->name('hardening.pause');
```

add:

```php
            Route::post('/hardening/resume', [V1\HardeningController::class, 'resume'])->name('hardening.resume');
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan test tests/Feature/Hardening/HardeningResumeEndpointTest.php tests/Feature/Hardening/HardeningPauseEndpointTest.php tests/Feature/Hardening/HardeningRuleEndpointTest.php tests/Feature/Hardening/HardeningStatusEndpointTest.php`
Expected: PASS, 81 tests.

- [ ] **Step 6: Commit**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api
git add app/Http/Controllers/Api/V1/HardeningController.php routes/api.php \
        tests/Feature/Hardening/HardeningResumeEndpointTest.php
git commit -m "feat(hardening): POST /hardening/resume closes open pause rows on success" \
  -m "Authorized with pauseHardening. Any outcome other than plugin success leaves
the row open so the panel keeps showing the pause and the backstop keeps
retrying." \
  -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 9: Throttle the three POST routes

**Files:**
- Modify: `routes/api.php` (the hardening block)
- Test: `tests/Feature/Hardening/HardeningRoutesTest.php` (create)

**Interfaces:**
- Consumes: the four routes from Tasks 5-8 with names `api.v1.projects.lsm.hardening`, `.hardening.rule`, `.hardening.pause`, `.hardening.resume`; test helpers from Tasks 1, 2, 4.
- Produces: middleware `throttle:12,1,hardening` on the three POST routes — 12 requests per minute per user, counted in a bucket of its own (see "Deviations from the spec", item 1). The GET stays unthrottled.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Hardening/HardeningRoutesTest.php`:

```php
<?php

use App\Http\Middleware\EnsureTwoFactorEnrolled;
use App\Http\Middleware\RejectIntegrationTokens;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

test('the four hardening routes are registered with the expected verb, uri and middleware', function (string $name, string $verb, string $uri, bool $throttled) {
    $route = Route::getRoutes()->getByName($name);

    expect($route)->not->toBeNull();
    expect($route->methods())->toContain($verb);
    expect($route->uri())->toBe($uri);

    $middleware = $route->gatherMiddleware();
    expect($middleware)->toContain('auth:sanctum', RejectIntegrationTokens::class, EnsureTwoFactorEnrolled::class);
    expect(in_array('throttle:12,1,hardening', $middleware, true))->toBe($throttled);
})->with([
    ['api.v1.projects.lsm.hardening', 'GET', 'api/v1/projects/{project}/lsm/hardening', false],
    ['api.v1.projects.lsm.hardening.rule', 'POST', 'api/v1/projects/{project}/lsm/hardening/rule', true],
    ['api.v1.projects.lsm.hardening.pause', 'POST', 'api/v1/projects/{project}/lsm/hardening/pause', true],
    ['api.v1.projects.lsm.hardening.resume', 'POST', 'api/v1/projects/{project}/lsm/hardening/resume', true],
]);

test('the 13th hardening POST within a minute is throttled, across the three POST routes', function () {
    Http::fake(['*' => Http::response(hardeningPluginBody(true, null, hardeningPluginStatus('on')), 200)]);
    $admin = hardeningUser('admin');
    $project = hardeningProject();
    $base = "/api/v1/projects/{$project->id}/lsm/hardening";

    for ($i = 0; $i < 4; $i++) {
        $this->actingAs($admin)->postJson("{$base}/rule", ['rule' => 'block_debug_log', 'enabled' => true])->assertOk();
        $this->actingAs($admin)->postJson("{$base}/pause", ['minutes' => 15])->assertOk();
        $this->actingAs($admin)->postJson("{$base}/resume")->assertOk();
    }

    $this->actingAs($admin)->postJson("{$base}/resume")->assertStatus(429);
});

test('the status GET is not throttled', function () {
    Http::fake(['*' => Http::response(hardeningPluginBody(true, null, hardeningPluginStatus('on')), 200)]);
    $admin = hardeningUser('admin');
    $project = hardeningProject();

    for ($i = 0; $i < 15; $i++) {
        $this->actingAs($admin)->getJson("/api/v1/projects/{$project->id}/lsm/hardening")->assertOk();
    }
});

test('the hardening counter is its own bucket: other throttled routes do not use it up', function () {
    Http::fake(['*' => Http::response(hardeningPluginBody(true, null, hardeningPluginStatus('on')), 200)]);
    $admin = hardeningUser('admin');
    $project = hardeningProject();

    // /search is behind throttle:30,1. Unprefixed throttles share one per-user
    // counter, so without the `hardening` prefix these 13 hits would already
    // exceed the hardening limit of 12.
    for ($i = 0; $i < 13; $i++) {
        $this->actingAs($admin)->getJson('/api/v1/search?q=client')->assertOk();
    }

    $this->actingAs($admin)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/resume")
        ->assertOk();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan test tests/Feature/Hardening/HardeningRoutesTest.php`
Expected: FAIL, 4 failed, 3 passed — the three POST rows of the registration test and the 13th-POST test fail; the GET row, the unthrottled-GET test and the own-bucket test pass already because nothing is throttled yet.

- [ ] **Step 3: Add the throttle**

In `routes/api.php` replace the hardening block written in Tasks 5-8:

```php
            // Server hardening (.htaccess)
            Route::get('/hardening', [V1\HardeningController::class, 'show'])->name('hardening');
            Route::post('/hardening/rule', [V1\HardeningController::class, 'setRule'])->name('hardening.rule');
            Route::post('/hardening/pause', [V1\HardeningController::class, 'pause'])->name('hardening.pause');
            Route::post('/hardening/resume', [V1\HardeningController::class, 'resume'])->name('hardening.resume');
```

with (per-route `->middleware()` is the house style — see the `/integration-tokens` store route at lines 127-129 and the `/search` route further down):

```php
            // Server hardening (.htaccess)
            Route::get('/hardening', [V1\HardeningController::class, 'show'])->name('hardening');
            Route::post('/hardening/rule', [V1\HardeningController::class, 'setRule'])
                ->middleware('throttle:12,1,hardening')
                ->name('hardening.rule');
            Route::post('/hardening/pause', [V1\HardeningController::class, 'pause'])
                ->middleware('throttle:12,1,hardening')
                ->name('hardening.pause');
            Route::post('/hardening/resume', [V1\HardeningController::class, 'resume'])
                ->middleware('throttle:12,1,hardening')
                ->name('hardening.resume');
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan test tests/Feature/Hardening`
Expected: PASS, 111 tests (9 + 8 + 6 + 24 + 25 + 22 + 10 + 7).

The third parameter is not optional decoration: with a bare `throttle:12,1` the test "the hardening counter is its own bucket" fails with a 429 (verified while writing this plan).

- [ ] **Step 5: Check the route table**

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan route:list --path=hardening`
Expected: exactly four routes — `GET|HEAD api/v1/projects/{project}/lsm/hardening` and `POST …/hardening/pause`, `POST …/hardening/resume`, `POST …/hardening/rule`.

- [ ] **Step 6: Commit**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api
git add routes/api.php tests/Feature/Hardening/HardeningRoutesTest.php
git commit -m "feat(hardening): throttle the hardening POST routes to 12 per minute" \
  -m "Each call rewrites .htaccess and fires up to a dozen loopbacks on shared
hosting. The third throttle parameter gives the limiter its own key: unprefixed
throttle:N,M routes share one per-user counter, so a minute of /search
keystrokes would otherwise lock the user out of pause and resume." \
  -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 10: `HardeningPauseOverdueNotification`

**Files:**
- Create: `app/Notifications/HardeningPauseOverdueNotification.php`
- Modify: `tests/Feature/Emails/AllNotificationsRenderTest.php` (lines 11, 20, 52 and the dataset between lines 115 and 117 — numbers of the untouched file; Step 6 edits bottom-up so they stay valid)
- Test: `tests/Feature/Hardening/HardeningPauseOverdueNotificationTest.php` (create)

**Interfaces:**
- Consumes: `App\Models\ProjectHardeningPause` and its factory (Task 3); test helpers `hardeningProject()`, `hardeningUser()` (Tasks 1, 2).
- Produces: `new App\Notifications\HardeningPauseOverdueNotification(Project $project, ProjectHardeningPause $pause)`. Channels: always `database`, plus `mail` when `$project->notification_preferences['email_alerts_enabled']` is truthy (the `MalwareDetectedNotification` convention). Database payload `type` = `hardening_pause_overdue`; it carries a ready-made `message` because the web notification list renders `data.message` when present and has no case for this type.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Hardening/HardeningPauseOverdueNotificationTest.php`:

```php
<?php

use App\Models\ProjectHardeningPause;
use App\Notifications\HardeningPauseOverdueNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

function overdueHardeningPause(array $projectAttrs = []): ProjectHardeningPause
{
    return ProjectHardeningPause::factory()->create([
        'project_id' => hardeningProject(array_merge(['name' => 'Praxis Dr. Jung'], $projectAttrs))->id,
        'paused_until' => now()->subMinutes(45),
        'note' => null,
    ]);
}

test('it is queueable like the other alert notifications', function () {
    $pause = overdueHardeningPause();

    expect(new HardeningPauseOverdueNotification($pause->project, $pause))->toBeInstanceOf(ShouldQueue::class);
});

test('it always stores a database notification and mails only when the project has email alerts on', function () {
    $quiet = overdueHardeningPause(['notification_preferences' => ['email_alerts_enabled' => false]]);
    $loud = overdueHardeningPause(['notification_preferences' => ['email_alerts_enabled' => true]]);
    $user = hardeningUser('admin');

    expect((new HardeningPauseOverdueNotification($quiet->project, $quiet))->via($user))->toBe(['database']);
    expect((new HardeningPauseOverdueNotification($loud->project, $loud))->via($user))->toBe(['database', 'mail']);
});

test('the database payload identifies the project, the pause and who paused', function () {
    $pause = overdueHardeningPause();

    $data = (new HardeningPauseOverdueNotification($pause->project, $pause))->toArray(hardeningUser('admin'));

    expect($data)->toBe([
        'type' => 'hardening_pause_overdue',
        'project_id' => $pause->project_id,
        'project_name' => 'Praxis Dr. Jung',
        'project_url' => 'https://client.example.com',
        'pause_id' => $pause->id,
        'paused_by' => $pause->user_id,
        'paused_until' => $pause->paused_until->toISOString(),
        'message' => '⚠️ Praxis Dr. Jung — the archive rule pause is overdue, backups may be publicly downloadable',
        'severity' => 'critical',
    ]);
});

test('the mail names the project, the deadline and links to the security section', function () {
    config(['app.frontend_url' => 'https://wartung.example']);
    $pause = overdueHardeningPause();

    $mail = (new HardeningPauseOverdueNotification($pause->project, $pause))->toMail(hardeningUser('admin'));

    expect($mail->subject)->toBe('⚠️ Hardening pause overdue: Praxis Dr. Jung');
    expect($mail->actionUrl)->toBe("https://wartung.example/projects/{$pause->project_id}?section=security");
    expect(implode("\n", $mail->introLines))
        ->toContain('https://client.example.com')
        ->toContain($pause->paused_until->format('Y-m-d H:i') . ' UTC');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan test tests/Feature/Hardening/HardeningPauseOverdueNotificationTest.php`
Expected: FAIL, 4 failed — `Class "App\Notifications\HardeningPauseOverdueNotification" not found`.

- [ ] **Step 3: Write the notification**

Create `app/Notifications/HardeningPauseOverdueNotification.php` (same skeleton as `MalwareDetectedNotification.php`: `ShouldQueue` + `Queueable`, promoted protected constructor properties, `via` / `toMail` / `toArray`):

```php
<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\ProjectHardeningPause;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class HardeningPauseOverdueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Project $project,
        protected ProjectHardeningPause $pause,
    ) {}

    /**
     * Determine which channels to use based on project notification preferences.
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        $prefs = $this->project->notification_preferences ?? [];

        if (!empty($prefs['email_alerts_enabled'])) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("⚠️ Hardening pause overdue: {$this->project->name}")
            ->greeting("Archive rule still paused on {$this->project->name}")
            ->line("**URL:** {$this->project->url}")
            ->line("**Pause should have ended:** {$this->pause->paused_until->format('Y-m-d H:i')} UTC")
            ->line('The block_archives rule in wp-content/.htaccess could not be re-enabled automatically. Until it is back on, backup archives (.wpress, .zip, .sql) under wp-content may be publicly downloadable.')
            ->line('Open the Security panel and use "Re-enable now". If the site is unreachable or the plugin is older than 2.10.0, fix that first.')
            ->action('Open Security Panel', config('app.frontend_url') . "/projects/{$this->project->id}?section=security")
            ->salutation('— Landeseiten Maintenance');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'hardening_pause_overdue',
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
            'project_url' => $this->project->url,
            'pause_id' => $this->pause->id,
            'paused_by' => $this->pause->user_id,
            'paused_until' => $this->pause->paused_until->toISOString(),
            'message' => "⚠️ {$this->project->name} — the archive rule pause is overdue, backups may be publicly downloadable",
            'severity' => 'critical',
        ];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan test tests/Feature/Hardening/HardeningPauseOverdueNotificationTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 5: Run the existing all-notifications guard and watch it fail**

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan test tests/Feature/Emails/AllNotificationsRenderTest.php`
Expected: FAIL, 1 failed, 20 passed — "it keeps the notification dataset in sync with app/Notifications" lists `HardeningPauseOverdueNotification` as not covered.

- [ ] **Step 6: Add the new notification to that dataset**

In `tests/Feature/Emails/AllNotificationsRenderTest.php`. Line numbers below refer to the file as it is **before** this step. The four edits are ordered bottom-up, so every number is still valid when you reach it; if anything looks off, locate the spot by the quoted text, not by the number.

1. In `notificationDataset()`, between the `'DomainExpiringNotification'` entry (ends with `},` on line 115) and the `'MalwareDetectedNotification'` entry (line 117), insert — with one blank line above and below, like the neighbouring entries:

```php
        'HardeningPauseOverdueNotification' => function () {
            $project = Project::factory()->make(['id' => 9118, 'name' => 'Sigma']);
            $pause = ProjectHardeningPause::factory()->make([
                'id' => 1,
                'project_id' => 9118,
                'user_id' => null,
                'paused_until' => now()->subMinutes(45),
            ]);

            return new HardeningPauseOverdueNotification($project, $pause);
        },
```

2. In the comment on line 52 change `so all 20 are covered.)` to `so all 21 are covered.)`.

3. Below line 20 `use App\Notifications\DomainExpiringNotification;` add:

```php
use App\Notifications\HardeningPauseOverdueNotification;
```

4. Below line 11 `use App\Models\Project;` add:

```php
use App\Models\ProjectHardeningPause;
```

- [ ] **Step 7: Run the guard again**

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan test tests/Feature/Emails/AllNotificationsRenderTest.php`
Expected: PASS, 22 tests — the new mail renders branded, with no localhost link and no dead `/security"` path.

- [ ] **Step 8: Commit**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api
git add app/Notifications/HardeningPauseOverdueNotification.php \
        tests/Feature/Hardening/HardeningPauseOverdueNotificationTest.php \
        tests/Feature/Emails/AllNotificationsRenderTest.php
git commit -m "feat(hardening): notification for a pause that did not end" \
  -m "Database always, mail when the project has e-mail alerts on. The payload
carries a ready message so the existing notification list renders it without a
web change." \
  -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 11: `hardening:resume-expired` command

**Files:**
- Create: `app/Console/Commands/ResumeExpiredHardeningPauses.php` (auto-discovered, no registration)
- Test: `tests/Feature/Hardening/ResumeExpiredHardeningPausesTest.php` (create)

**Interfaces:**
- Consumes: `LsmService::for($project)->isConfigured(): bool` and `->hardeningRequest('POST', '/hardening/resume', [], 60)` (Task 1); `ProjectHardeningPause::open()`, relations `project` (null when the project is soft-deleted) and `user`, columns `paused_until`, `resumed_at`, `failed_at`, `overdue_notified_at`, `note`, `created_at` (Task 3); `HardeningResponseMapper::map()` → `outcome`, and `body['status']['rules']['block_archives']['state']` when the site answered (Task 4); `new HardeningPauseOverdueNotification(Project $project, ProjectHardeningPause $pause)` (Task 10); test helpers from Tasks 1, 2, 4.
- Produces: artisan command `hardening:resume-expired`, always exit code 0. Notes it writes: `Closed by backstop: archive rule is on`, `Closed without resume: project deleted or LSM not configured`, `Gave up: plugin_outdated`, `Gave up: unauthorized`, `Gave up: not resumed within 24 h`.
- Produces: one `Log::info('hardening', ['user_id' => null, 'project_id' => int, 'action' => 'resume', 'rule' => 'block_archives', 'outcome' => string])` per resume POST — same keys and the same `outcome` rule as the controller's audit line in Task 13 (`ok`, the plugin reason for a plugin failure, or `plugin_outdated` / `unauthorized` / `unreachable`); `user_id` is null because the backstop has no acting user. Rows closed without a POST (trashed / unconfigured project) write no line.
- Produces: at-most-once overdue notification — `overdue_notified_at` is stamped before the notify loop; a recipient whose `notify()` throws is logged with `Log::error` and skipped, the others are still notified.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Hardening/ResumeExpiredHardeningPausesTest.php`:

```php
<?php

use App\Models\ProjectHardeningPause;
use App\Notifications\HardeningPauseOverdueNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * An open pause row whose paused_until passed $minutesOverdue minutes ago,
 * created 60 minutes before that (a 60-minute pause).
 */
function expiredHardeningPause(int $minutesOverdue = 5, array $attrs = [], array $projectAttrs = []): ProjectHardeningPause
{
    return ProjectHardeningPause::factory()->create(array_merge([
        'project_id' => hardeningProject($projectAttrs)->id,
        'user_id' => hardeningUser('developer')->id,
        'paused_until' => now()->subMinutes($minutesOverdue),
        'created_at' => now()->subMinutes($minutesOverdue + 60),
    ], $attrs));
}

function fakeBackstopResume(array $body, int $http = 200): void
{
    Http::fake(['*/wp-json/lsm/v1/hardening/resume' => Http::response($body, $http)]);
}

// ---------------------------------------------------------------------------
// Which rows are picked up
// ---------------------------------------------------------------------------

test('an expired row is resumed with a POST and a 60 s timeout, never decided from a GET, and closed when the rule is on', function () {
    Notification::fake();
    $seenTimeout = null;
    Http::fake(function ($request, array $options) use (&$seenTimeout) {
        $seenTimeout = $options['timeout'] ?? null;

        return Http::response(hardeningPluginBody(true, null, hardeningPluginStatus('on')), 200);
    });
    $pause = expiredHardeningPause(5);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    expect($pause->fresh()->resumed_at)->not->toBeNull();
    expect($pause->fresh()->failed_at)->toBeNull();
    expect($pause->fresh()->note)->toBe('Closed by backstop: archive rule is on');
    expect($seenTimeout)->toBe(60);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://client.example.com/wp-json/lsm/v1/hardening/resume');
    Notification::assertNothingSent();
});

test('a row that has not expired yet is left alone', function () {
    Http::fake();
    $pause = ProjectHardeningPause::factory()->create([
        'project_id' => hardeningProject()->id,
        'paused_until' => now()->addMinutes(10),
    ]);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    expect($pause->fresh()->resumed_at)->toBeNull();
    Http::assertNothingSent();
});

test('rows that are already resumed or given up on are left alone', function () {
    Http::fake();
    expiredHardeningPause(5, ['resumed_at' => now()->subMinute()]);
    expiredHardeningPause(5, ['failed_at' => now()->subMinute()]);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------
// Outcome of the resume POST
// ---------------------------------------------------------------------------

test('an unreachable site leaves the row open for the next run', function (string $failure) {
    Notification::fake();
    Http::fake(['*' => match ($failure) {
        'connection failure' => Http::failedConnection(),
        'http 500' => Http::response('server error', 500),
    }]);
    $pause = expiredHardeningPause(5);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);
    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    expect($pause->fresh()->resumed_at)->toBeNull();
    expect($pause->fresh()->failed_at)->toBeNull();
    Http::assertSentCount(2); // retried on the second run
    Notification::assertNothingSent();
})->with(['connection failure', 'http 500']);

test('a response that does not show the rule on leaves the row open', function (array $body) {
    fakeBackstopResume($body);
    $pause = expiredHardeningPause(5);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    expect($pause->fresh()->resumed_at)->toBeNull();
    expect($pause->fresh()->failed_at)->toBeNull();
})->with([
    'resume failed, still paused' => [hardeningPluginBody(false, 'asset_broken', hardeningPluginStatus('paused', 1790000000, true))],
    'lock busy, still paused' => [hardeningPluginBody(false, 'busy', hardeningPluginStatus('paused', 1790000000, true))],
    'success but the rule is off' => [hardeningPluginBody(true, null, hardeningPluginStatus('off'))],
    'success but drifted' => [hardeningPluginBody(true, null, hardeningPluginStatus('drift'))],
]);

test('every backstop resume call writes one hardening log line without a user', function (?array $body, string $outcome) {
    // The schedule entry runs in the background, so console output is discarded:
    // this line is the only trace of a backstop resume that did not close the row.
    Log::spy();
    Http::fake(['*' => $body === null ? Http::failedConnection() : Http::response($body, 200)]);
    $pause = expiredHardeningPause(5);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    Log::shouldHaveReceived('info')
        ->withArgs(fn ($message, $context = []) => $message === 'hardening' && $context === [
            'user_id' => null,
            'project_id' => $pause->project_id,
            'action' => 'resume',
            'rule' => 'block_archives',
            'outcome' => $outcome,
        ])
        ->once();
})->with([
    'resumed' => [hardeningPluginBody(true, null, hardeningPluginStatus('on')), 'ok'],
    'refused by the site' => [hardeningPluginBody(false, 'asset_broken', hardeningPluginStatus('paused', 1790000000, true)), 'asset_broken'],
    'unreachable' => [null, 'unreachable'],
]);

test('an outdated plugin or a rejected key marks the row failed, notifies once and is never retried', function (int $http, string $note) {
    Notification::fake();
    fakeBackstopResume(['code' => 'rest_error'], $http);
    $admin = hardeningUser('admin');
    $pause = expiredHardeningPause(5);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);
    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    expect($pause->fresh()->failed_at)->not->toBeNull();
    expect($pause->fresh()->resumed_at)->toBeNull();
    expect($pause->fresh()->note)->toBe($note);
    expect($pause->fresh()->overdue_notified_at)->not->toBeNull();
    Http::assertSentCount(1);
    Notification::assertSentToTimes($admin, HardeningPauseOverdueNotification::class, 1);
})->with([
    'old plugin' => [404, 'Gave up: plugin_outdated'],
    'key rejected 401' => [401, 'Gave up: unauthorized'],
    'key rejected 403' => [403, 'Gave up: unauthorized'],
]);

test('a row older than 24 hours that still is not on is marked failed', function () {
    Notification::fake();
    Http::fake(['*' => Http::failedConnection()]);
    $pause = expiredHardeningPause(5, [
        'paused_until' => now()->subHours(24),
        'created_at' => now()->subHours(25),
        'overdue_notified_at' => now()->subHours(23),
    ]);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    expect($pause->fresh()->failed_at)->not->toBeNull();
    expect($pause->fresh()->note)->toBe('Gave up: not resumed within 24 h');
    Notification::assertNothingSent(); // already notified 23 h ago
});

test('a row younger than 24 hours is not given up on', function () {
    Http::fake(['*' => Http::failedConnection()]);
    $pause = expiredHardeningPause(5, [
        'paused_until' => now()->subHours(22),
        'created_at' => now()->subHours(23),
        'overdue_notified_at' => now()->subHours(21),
    ]);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    expect($pause->fresh()->failed_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// The one overdue notification
// ---------------------------------------------------------------------------

test('more than 30 minutes overdue sends exactly one notification to the pausing user and every admin', function () {
    Notification::fake();
    Http::fake(['*' => Http::failedConnection()]);
    $admin = hardeningUser('admin');
    $flagHolder = hardeningUser('manager', ['is_admin' => true]);
    $bystander = hardeningUser('manager');
    $pause = expiredHardeningPause(31);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);
    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    Notification::assertSentToTimes($pause->user, HardeningPauseOverdueNotification::class, 1);
    Notification::assertSentToTimes($admin, HardeningPauseOverdueNotification::class, 1);
    Notification::assertSentToTimes($flagHolder, HardeningPauseOverdueNotification::class, 1);
    Notification::assertNotSentTo($bystander, HardeningPauseOverdueNotification::class);
    expect($pause->fresh()->overdue_notified_at)->not->toBeNull();
    expect($pause->fresh()->resumed_at)->toBeNull();
});

test('30 minutes overdue or less sends nothing yet', function () {
    Notification::fake();
    Http::fake(['*' => Http::failedConnection()]);
    hardeningUser('admin');
    $pause = expiredHardeningPause(29);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    Notification::assertNothingSent();
    expect($pause->fresh()->overdue_notified_at)->toBeNull();
});

test('an admin who paused is notified once, not twice', function () {
    Notification::fake();
    Http::fake(['*' => Http::failedConnection()]);
    $admin = hardeningUser('admin');
    expiredHardeningPause(45, ['user_id' => $admin->id]);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    Notification::assertSentToTimes($admin, HardeningPauseOverdueNotification::class, 1);
});

test('a row whose pausing user is gone still notifies the admins', function () {
    Notification::fake();
    Http::fake(['*' => Http::failedConnection()]);
    $admin = hardeningUser('admin');
    expiredHardeningPause(45, ['user_id' => null]);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    Notification::assertSentToTimes($admin, HardeningPauseOverdueNotification::class, 1);
});

test('a row that gets closed in this run is not reported as overdue', function () {
    Notification::fake();
    fakeBackstopResume(hardeningPluginBody(true, null, hardeningPluginStatus('on')));
    hardeningUser('admin');
    $pause = expiredHardeningPause(45);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    expect($pause->fresh()->resumed_at)->not->toBeNull();
    Notification::assertNothingSent();
});

test('a failing mail transport still means one stored notification per recipient, not one per run', function () {
    // No Notification::fake() here on purpose: the real channels must run.
    // Nothing listens on port 1: the mail channel throws, as a broken SMTP does in production (queue = sync).
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);
    Http::fake(['*' => Http::failedConnection()]);
    $admin = hardeningUser('admin');
    $pause = expiredHardeningPause(45, [], ['notification_preferences' => ['email_alerts_enabled' => true]]);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);
    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    expect($admin->notifications()->count())->toBe(1);
    expect($pause->user->notifications()->count())->toBe(1);
    expect($pause->fresh()->overdue_notified_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Projects that can no longer be called
// ---------------------------------------------------------------------------

test('a trashed project closes the row with a note and nothing is sent', function () {
    Notification::fake();
    Http::fake();
    $pause = expiredHardeningPause(45);
    $pause->project->delete();

    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    expect($pause->fresh()->resumed_at)->not->toBeNull();
    expect($pause->fresh()->note)->toBe('Closed without resume: project deleted or LSM not configured');
    Http::assertNothingSent();
    Notification::assertNothingSent();
});

test('a project that lost its LSM key closes the row with a note and nothing is sent', function () {
    Http::fake();
    $pause = expiredHardeningPause(5);
    $pause->project->update(['health_check_secret' => null]);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    expect($pause->fresh()->resumed_at)->not->toBeNull();
    expect($pause->fresh()->note)->toBe('Closed without resume: project deleted or LSM not configured');
    Http::assertNothingSent();
});

test('a row that blows up does not stop the rows after it', function () {
    Http::fake([
        'https://broken.example.com/*' => fn () => throw new \Error('boom'),
        '*' => Http::response(hardeningPluginBody(true, null, hardeningPluginStatus('on')), 200),
    ]);
    $broken = expiredHardeningPause(5, [], ['url' => 'https://broken.example.com']);
    $healthy = expiredHardeningPause(5);

    $this->artisan('hardening:resume-expired')->assertExitCode(0);

    expect($broken->fresh()->resumed_at)->toBeNull();
    expect($healthy->fresh()->resumed_at)->not->toBeNull();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan test tests/Feature/Hardening/ResumeExpiredHardeningPausesTest.php`
Expected: FAIL, 26 failed — `The command "hardening:resume-expired" does not exist.`

- [ ] **Step 3: Write the command**

Create `app/Console/Commands/ResumeExpiredHardeningPauses.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\ProjectHardeningPause;
use App\Models\User;
use App\Notifications\HardeningPauseOverdueNotification;
use App\Services\HardeningResponseMapper;
use App\Services\LsmService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Platform backstop for "pause for download". The plugin resumes on its own
 * on the first PHP load after pause_until; this command covers sites where
 * that did not happen and pauses whose response never reached the platform.
 */
class ResumeExpiredHardeningPauses extends Command
{
    protected $signature = 'hardening:resume-expired';

    protected $description = 'Re-enable the .htaccess archive rule on sites whose hardening pause has expired';

    private const RESUME_TIMEOUT = 60;

    public function handle(): int
    {
        $pauses = ProjectHardeningPause::open()
            ->where('paused_until', '<', now())
            ->with(['project', 'user'])
            ->orderBy('id')
            ->get();

        foreach ($pauses as $pause) {
            // One broken row must never block the rows behind it.
            try {
                $this->process($pause);
            } catch (\Throwable $e) {
                $this->error("Pause #{$pause->id}: {$e->getMessage()}");
                Log::error("hardening:resume-expired failed for pause #{$pause->id}: {$e->getMessage()}");
            }
        }

        $this->info("Processed {$pauses->count()} expired hardening pause(s).");

        return self::SUCCESS;
    }

    private function process(ProjectHardeningPause $pause): void
    {
        $project = $pause->project; // null when the project is trashed

        if (!$project || !LsmService::for($project)->isConfigured()) {
            $pause->update([
                'resumed_at' => now(),
                'note' => 'Closed without resume: project deleted or LSM not configured',
            ]);
            return;
        }

        // Always POST resume (idempotent on the site) — a status GET may be a
        // cached copy, and only this response proves the rule is back.
        $mapped = HardeningResponseMapper::map(
            LsmService::for($project)->hardeningRequest('POST', '/hardening/resume', [], self::RESUME_TIMEOUT)
        );

        // Same audit line as HardeningController::logAction(). The schedule entry
        // runs in the background, so the console output below is discarded and
        // this is the only trace of a resume that did not close the row.
        Log::info('hardening', [
            'user_id' => null, // platform backstop, no acting user
            'project_id' => $project->id,
            'action' => 'resume',
            'rule' => 'block_archives',
            'outcome' => $mapped['outcome'] === 'failed'
                ? ($mapped['body']['reason'] ?? 'failed')
                : $mapped['outcome'],
        ]);

        if (($mapped['body']['status']['rules']['block_archives']['state'] ?? null) === 'on') {
            $pause->update(['resumed_at' => now(), 'note' => 'Closed by backstop: archive rule is on']);
            $this->line("  {$project->name}: archive rule is on, pause closed");
            return;
        }

        if (in_array($mapped['outcome'], ['plugin_outdated', 'unauthorized'], true)) {
            // Retrying cannot fix an old plugin or a rejected key.
            $pause->update(['failed_at' => now(), 'note' => "Gave up: {$mapped['outcome']}"]);
        } elseif ($pause->created_at->lt(now()->subDay())) {
            $pause->update(['failed_at' => now(), 'note' => 'Gave up: not resumed within 24 h']);
        }

        $this->warn("  {$project->name}: not resumed ({$mapped['outcome']})");

        // One notification per row: when it is more than 30 minutes overdue, or
        // right away when the row was just given up on (nobody looks at it again).
        $due = $pause->failed_at !== null || $pause->paused_until->lt(now()->subMinutes(30));

        if ($due && $pause->overdue_notified_at === null) {
            // Stamp first (write-ahead, like the pause row itself). The queue is
            // `sync`, so a mail transport error surfaces right here; stamping
            // afterwards would re-notify everyone on every ten-minute run.
            $pause->update(['overdue_notified_at' => now()]);

            $recipients = User::where('role', 'admin')->orWhere('is_admin', true)->get();
            if ($pause->user) {
                $recipients->push($pause->user);
            }

            foreach ($recipients->unique('id') as $recipient) {
                try {
                    $recipient->notify(new HardeningPauseOverdueNotification($project, $pause));
                } catch (\Throwable $e) {
                    Log::error("hardening:resume-expired could not notify user #{$recipient->id} about pause #{$pause->id}: {$e->getMessage()}");
                }
            }
        }
    }
}
```

The order inside the notification block is deliberate — do not move the stamp below the loop and do not drop the inner try/catch. `HardeningPauseOverdueNotification` is `ShouldQueue`, the production queue is `sync`, and the notification has a mail channel when the project has e-mail alerts on: a broken SMTP throws straight out of `notify()`. With the stamp after the loop, the row-level try/catch in `handle()` would swallow that exception, the stamp would never be written, the loop would stop at the first recipient, and every ten-minute run would store another database notification for that first admin (up to 144 per row in 24 h). The test "a failing mail transport still means one stored notification per recipient" runs the real channels against a dead SMTP port; against a command that notifies without the inner try/catch and stamps afterwards it fails with `Failed asserting that 2 is identical to 1`. The tests that use `Notification::fake()` cannot see any of this.

Do **not** add the sibling commands' `->where('status', '!=', 'archived')` filter: a project archived during a pause still has its archive rule off and must be resumed. Admins are looked up the way `Project::notifiableTeamMembers()` does it (`app/Models/Project.php:223`), which includes `is_admin` flag holders; the older `User::where('role', 'admin')` in `CheckSslExpiry.php:104` misses them.

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan test tests/Feature/Hardening/ResumeExpiredHardeningPausesTest.php`
Expected: PASS, 26 tests.

- [ ] **Step 5: Commit**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api
git add app/Console/Commands/ResumeExpiredHardeningPauses.php \
        tests/Feature/Hardening/ResumeExpiredHardeningPausesTest.php
git commit -m "feat(hardening): hardening:resume-expired backstop command" \
  -m "For every open pause row past paused_until: POST resume (never decide from a
possibly cached GET), close only when the response shows block_archives on,
give up on an outdated plugin, a rejected key or after 24 h, and send exactly
one overdue notification to the pausing user and the admins. Each row runs in
its own try/catch; trashed or unconfigured projects are closed with a note." \
  -m "overdue_notified_at is stamped before the notify loop and every recipient has
its own try/catch: the queue is sync, so a broken SMTP throws out of notify()
and would otherwise repeat the notification on every run. Each resume POST
writes the 'hardening' audit line with user_id null, because the scheduled run
is in the background and its console output is discarded." \
  -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 12: Scheduler entry

**Files:**
- Modify: `routes/console.php` (append after line 232)
- Test: `tests/Feature/Hardening/HardeningScheduleTest.php` (create)

**Interfaces:**
- Consumes: the command name `hardening:resume-expired` (Task 11).
- Produces: a scheduled event named `hardening-resume-expired`, cron `*/10 * * * *`, mutex expiry 15 minutes, background, one server.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Hardening/HardeningScheduleTest.php` (same technique as `tests/Feature/BackupFeatureFlagTest.php:142-154`):

```php
<?php

use Illuminate\Console\Scheduling\Schedule;

test('hardening:resume-expired runs every ten minutes with a 15-minute mutex, in the background, on one server', function () {
    // routes/console.php registers tasks at boot; re-evaluate it against a fresh Schedule.
    $schedule = new Schedule;
    \Illuminate\Support\Facades\Schedule::swap($schedule);
    require base_path('routes/console.php');

    $event = collect($schedule->events())->first(fn ($e) => $e->description === 'hardening-resume-expired');

    expect($event)->not->toBeNull();
    expect($event->command)->toContain('hardening:resume-expired');
    expect($event->expression)->toBe('*/10 * * * *');
    expect($event->withoutOverlapping)->toBeTrue();
    // Not the 1440-minute default: a stuck default mutex blocked sites:check-uptime for three days in August.
    expect($event->expiresAt)->toBe(15);
    expect($event->runInBackground)->toBeTrue();
    expect($event->onOneServer)->toBeTrue();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan test tests/Feature/Hardening/HardeningScheduleTest.php`
Expected: FAIL, 1 failed — `Expecting null not to be null.`

- [ ] **Step 3: Add the schedule entry**

Append to the end of `routes/console.php`. The file currently ends (lines 228-232) with:

```php
Schedule::command('model:prune', ['--model' => \App\Models\UptimeCheck::class])
    ->dailyAt('02:30')
    ->timezone('Europe/Berlin')
    ->onOneServer()
    ->name('uptime-checks-prune');
```

Add below it (one blank line between):

```php
/*
|--------------------------------------------------------------------------
| Hardening Pause Backstop
|--------------------------------------------------------------------------
|
| Re-enables the .htaccess archive rule on sites whose "pause for download"
| has expired and that did not resume on their own. The mutex expires after
| 15 minutes instead of the 24 h default: a stuck default mutex kept
| sites:check-uptime from running for three days in August 2026.
|
*/

Schedule::command('hardening:resume-expired')
    ->everyTenMinutes()
    ->withoutOverlapping(15)
    ->runInBackground()
    ->name('hardening-resume-expired')
    ->onOneServer();
```

Every other entry in this file uses a bare `->withoutOverlapping()` (24 h mutex). Do not "align" this one with them — the explicit `15` is the point.

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan test tests/Feature/Hardening/HardeningScheduleTest.php tests/Feature/BackupFeatureFlagTest.php`
Expected: PASS, zero failures (the backup schedule tests re-evaluate the same file).

- [ ] **Step 5: Check the schedule list**

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan schedule:list | grep hardening`
Expected: one line containing `php artisan hardening:resume-expired` whose cron column starts with `*/10` (artisan pads the columns, so it prints `*/10 *  * * *`, not `*/10 * * * *`), followed by `Next Due: …`. Do not compare the line literally.

- [ ] **Step 6: Commit**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api
git add routes/console.php tests/Feature/Hardening/HardeningScheduleTest.php
git commit -m "feat(hardening): schedule hardening:resume-expired every ten minutes" \
  -m "withoutOverlapping(15) instead of the 24 h default: a stuck default mutex kept
sites:check-uptime from running from 25 to 28 August." \
  -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 13: `Log::info('hardening', …)` on every mutating call

**Files:**
- Modify: `app/Http/Controllers/Api/V1/HardeningController.php`
- Test: `tests/Feature/Hardening/HardeningAuditLogTest.php` (create)

**Interfaces:**
- Consumes: in `HardeningController` — `setRule()` has `$enabled` (bool), `$validated['rule']` (string) and `$mapped` in scope; `pause()` and `resume()` have `$mapped`; every one of the three ends with `return $this->respond($project, $mapped);`. `$mapped` is `HardeningResponseMapper::map()`'s `['outcome' => ok|busy|failed|plugin_outdated|unauthorized|unreachable, 'code' => int, 'body' => array]`; `body['reason']` holds the plugin reason when `outcome` is `failed` (Tasks 4, 6, 7, 8).
- Produces: private `logAction(Project $project, string $action, string $rule, array $mapped): void` writing exactly `Log::info('hardening', ['user_id' => int, 'project_id' => int, 'action' => 'enable'|'disable'|'pause'|'resume', 'rule' => string, 'outcome' => string])`. `outcome` is `ok`, the plugin reason for a plugin failure (`busy`, `rule_ineffective`, …), or `plugin_outdated` / `unauthorized` / `unreachable`. The backstop command already writes the same line inline with `'user_id' => null` (Task 11) — this task covers the controller only and does not touch the command.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Hardening/HardeningAuditLogTest.php`:

```php
<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Assert exactly one Log::info('hardening', $context) line with this context.
 */
function assertHardeningLogged(array $expected): void
{
    Log::shouldHaveReceived('info')
        ->withArgs(fn ($message, $context = []) => $message === 'hardening' && $context == $expected)
        ->once();
}

test('turning a rule on writes one hardening log line', function () {
    Log::spy();
    Http::fake(['*' => Http::response(hardeningPluginBody(true, null, hardeningPluginStatus('on')), 200)]);
    $admin = hardeningUser('admin');
    $project = hardeningProject();

    $this->actingAs($admin)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_uploads_php', 'enabled' => true])
        ->assertOk();

    assertHardeningLogged([
        'user_id' => $admin->id,
        'project_id' => $project->id,
        'action' => 'enable',
        'rule' => 'block_uploads_php',
        'outcome' => 'ok',
    ]);
});

test('a rolled-back disable is logged with the plugin reason as outcome', function () {
    Log::spy();
    Http::fake(['*' => Http::response(hardeningPluginBody(false, 'asset_broken', hardeningPluginStatus('on')), 200)]);
    $admin = hardeningUser('admin');
    $project = hardeningProject();

    $this->actingAs($admin)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_archives', 'enabled' => false])
        ->assertStatus(422);

    assertHardeningLogged([
        'user_id' => $admin->id,
        'project_id' => $project->id,
        'action' => 'disable',
        'rule' => 'block_archives',
        'outcome' => 'asset_broken',
    ]);
});

test('a pause is logged, also when the outcome is unknown', function () {
    Log::spy();
    Http::fake(['*' => Http::failedConnection()]);
    $admin = hardeningUser('admin');
    $project = hardeningProject();

    $this->actingAs($admin)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 30])
        ->assertStatus(502);

    assertHardeningLogged([
        'user_id' => $admin->id,
        'project_id' => $project->id,
        'action' => 'pause',
        'rule' => 'block_archives',
        'outcome' => 'unreachable',
    ]);
});

test('a resume is logged, also against an outdated plugin', function () {
    Log::spy();
    Http::fake(['*' => Http::response(['code' => 'rest_no_route'], 404)]);
    $admin = hardeningUser('admin');
    $project = hardeningProject();

    $this->actingAs($admin)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/resume")
        ->assertStatus(409);

    assertHardeningLogged([
        'user_id' => $admin->id,
        'project_id' => $project->id,
        'action' => 'resume',
        'rule' => 'block_archives',
        'outcome' => 'plugin_outdated',
    ]);
});

test('refused and invalid calls never reach the plugin and write no hardening line', function () {
    Log::spy();
    Http::fake();
    $project = hardeningProject();

    $this->actingAs(hardeningUser('manager'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 60])
        ->assertForbidden();
    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 45])
        ->assertStatus(422);

    Log::shouldNotHaveReceived('info', ['hardening', \Mockery::any()]);
});

test('reading the status writes no hardening line', function () {
    Log::spy();
    Http::fake(['*' => Http::response(hardeningPluginBody(true, null, hardeningPluginStatus('on')), 200)]);
    $project = hardeningProject();

    $this->actingAs(hardeningUser('admin'))
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk();

    Log::shouldNotHaveReceived('info', ['hardening', \Mockery::any()]);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan test tests/Feature/Hardening/HardeningAuditLogTest.php`
Expected: FAIL, 4 failed, 2 passed — the four "is logged" tests fail with a Mockery `InvalidCountException` for `info`; the two "writes no hardening line" tests pass already.

- [ ] **Step 3: Add the import and `logAction()`**

In `app/Http/Controllers/Api/V1/HardeningController.php` add below `use Illuminate\Support\Facades\Gate;`:

```php
use Illuminate\Support\Facades\Log;
```

Then, directly **above** the docblock that starts with `* The keys the platform adds next to the plugin status.` (the `platformState()` method, i.e. below `respond()`), insert:

```php
    /**
     * Durable server-side record of who changed what. The plugin's own log is a
     * 200-entry ring buffer on the site and carries no platform identity.
     */
    private function logAction(Project $project, string $action, string $rule, array $mapped): void
    {
        Log::info('hardening', [
            'user_id' => auth()->id(),
            'project_id' => $project->id,
            'action' => $action,
            'rule' => $rule,
            'outcome' => $mapped['outcome'] === 'failed'
                ? ($mapped['body']['reason'] ?? 'failed')
                : $mapped['outcome'],
        ]);
    }

```

- [ ] **Step 4: Call it from `setRule()`**

Replace, at the end of `setRule()`:

```php
            $this->closeOpenPauses($project, 'Closed: block_archives turned off');
        }

        return $this->respond($project, $mapped);
```

with:

```php
            $this->closeOpenPauses($project, 'Closed: block_archives turned off');
        }

        $this->logAction($project, $enabled ? 'enable' : 'disable', $validated['rule'], $mapped);

        return $this->respond($project, $mapped);
```

- [ ] **Step 5: Call it from `pause()`**

Replace, at the end of `pause()`:

```php
        // unreachable: outcome unknown — the row stays and the backstop reconciles.

        return $this->respond($project, $mapped);
```

with:

```php
        // unreachable: outcome unknown — the row stays and the backstop reconciles.

        $this->logAction($project, 'pause', 'block_archives', $mapped);

        return $this->respond($project, $mapped);
```

- [ ] **Step 6: Call it from `resume()`**

Replace, at the end of `resume()`:

```php
            $this->closeOpenPauses($project, 'Closed: resumed from the platform');
        }

        return $this->respond($project, $mapped);
```

with:

```php
            $this->closeOpenPauses($project, 'Closed: resumed from the platform');
        }

        $this->logAction($project, 'resume', 'block_archives', $mapped);

        return $this->respond($project, $mapped);
```

The log line sits after authorization and validation on purpose: a refused or invalid request never reached the site and is not a hardening action.

- [ ] **Step 7: Run the feature's tests**

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan test tests/Feature/Hardening tests/Unit/HardeningResponseMapperTest.php`
Expected: PASS, 166 tests (111 after Task 9 + 4 notification + 26 command + 1 schedule + 6 audit log + 18 mapper).

- [ ] **Step 8: Run the whole suite**

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php -d memory_limit=1G vendor/bin/pest`
Expected: `Tests: 2 skipped, 516 passed` (513 planned + 1 review-mandated test in Task 5 + 2 extra audit-outcome tests in Task 13). Zero failures — the baseline was 346 passed, 2 skipped; this feature adds 166 tests plus one dataset row in `AllNotificationsRenderTest`. Any red test is a regression from this branch: stop and fix it.

- [ ] **Step 9: Commit**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api
git add app/Http/Controllers/Api/V1/HardeningController.php \
        tests/Feature/Hardening/HardeningAuditLogTest.php
git commit -m "feat(hardening): log every enable, disable, pause and resume call" \
  -m "One Log::info('hardening', ...) line with user id, project id, action, rule and
outcome — also for rolled-back and outcome-unknown calls. The plugin's own log
is a 200-entry ring buffer on the site and carries no platform identity." \
  -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Appendix A: Deployment runbook — HUMAN ONLY (not a task; never dispatch this section to an agent)

**Agents: stop here. Nothing in this section is to be executed or verified by an agent — every command runs against production.**

This appendix is reference material for the person who deploys. It is not part of the task sequence: the implementation is finished after Task 13, and no step below has a checkbox because none of them is tracked by this plan. The SSH target and the server paths are in the team's deploy workflow document (section "Backend Deployment (API)"). That document also holds credentials — read it locally, never paste from it.

**Precondition:** the branch `feature/htaccess-hardening` with Tasks 1-13 committed and the full suite green.

**Order across repos:** plugin 2.10.0 first (inert: everything off), then this API, then web. Until a site's plugin is updated, its panel shows the quiet `plugin_outdated` state — that is expected, not an error.

**Pre-flight:** Confirm the API vhost and any proxy/CDN in front of it allow requests of about 130 s: the hardening POSTs wait up to 120 s for the plugin. If a pause POST is cut off early the write-ahead row still protects the site, but the audit line and the `paused_until` update are lost and the SPA shows a 504.

1. Confirm plugin `2.10.0` is released (GitHub release of `lsm-wp` with the zip attached) before deploying the API. The API is harmless without it, but nothing can be verified end to end.
2. On your machine, in `lsm-api`: make sure `git status` on `feature/htaccess-hardening` shows a clean working tree (the plan documents are committed, nothing left untracked or modified), run `php -d memory_limit=1G vendor/bin/pest` once more (expect `2 skipped, 516 passed`), then merge into `main` and `git push origin main` (deploys are `git pull` on the server — never edit files there).
3. `ssh wartung-api`, `cd` into the API directory named in the deploy workflow document, `git pull origin main`.
4. `composer install --no-dev --optimize-autoloader`
5. `composer dump-autoload -o` — **before** migrating. New classes (`ProjectHardeningPause`, `HardeningController`, `HardeningResponseMapper`, the command) must be in the optimized classmap first; this ordering is the gotcha recorded from the 5 August deploy, which took production down.
6. `php artisan migrate --force` — expect exactly one migration: `2026_09_21_120000_create_project_hardening_pauses_table`. Confirm with `php artisan migrate:status | grep hardening`.
7. `php artisan route:cache` — as its own command.
8. `php artisan config:cache` — as its own command (do not chain it with `route:cache` using `&&`; if one fails you need to see which).
9. `php artisan route:list --path=hardening` — expect the four routes (`GET …/lsm/hardening`, `POST …/hardening/rule`, `…/pause`, `…/resume`).
10. `php artisan schedule:list | grep hardening` — expect one line containing `php artisan hardening:resume-expired` whose cron column starts with `*/10` (artisan pads the columns, e.g. `*/10 *  * * *`), followed by `Next Due: …`.
11. `php artisan hardening:resume-expired` by hand once — expect `Processed 0 expired hardening pause(s).` and exit code 0.
12. Smoke test with your own session token against a project whose plugin is still < 2.10.0: `GET /api/v1/projects/<id>/lsm/hardening` must answer **200** with `"plugin_outdated":true`. Then against landeseiten.de (plugin 2.10.0): 200 with `"reachable":true` and three rules in `status.rules`.
13. `tail -n 50 storage/logs/laravel.log` — no new errors. After the first real enable or pause from the panel, `grep hardening storage/logs/laravel.log | tail -n 5` must show the audit line with `user_id`, `project_id`, `action`, `rule`, `outcome`. Backstop resumes show up in the same grep with `"user_id":null`.
14. Twenty minutes later: `php artisan schedule:list | grep hardening` shows a fresh "Next Due", and the log has no `hardening:resume-expired failed` or `could not notify user` lines. If the entry ever stops running, the mutex frees itself after 15 minutes; `php artisan schedule:clear-cache` clears it immediately.
15. Only now deploy `lsm-web`.

**Rollback (only if needed).** The order matters: the migration has to be rolled back while its file is still on the server. If you pull the revert first, the file `2026_09_21_120000_create_project_hardening_pauses_table.php` is gone, `migrate:rollback` prints `Migration not found` for it and skips it — the table is not dropped and its row stays in `migrations`.

1. On the server, **before** pulling the revert: `php artisan migrate:status | tail -n 3` — the hardening migration must be the last one that ran. If a later migration exists, stop: `--step=1` would roll back that one instead.
2. `php artisan migrate:rollback --step=1 --force` — drops only `project_hardening_pauses`. For the minute until step 4 is done the hardening endpoints and the ten-minute backstop fail on the missing table; that is expected, finish the sequence.
3. On your machine: revert the merge commit on `main`, `git push origin main`.
4. On the server: `git pull origin main`, then `composer dump-autoload -o`, `php artisan route:cache`, `php artisan config:cache` — each as its own command.

Sites keep their `.htaccess` rules; a site that was paused at that moment still resumes on its own on its next PHP load.

---

## Contract assumptions

The spec is silent on these shared details. Each is the most literal reading; the other two repos cannot be asked.

1. **`open_pause` is the pause row itself**, serialized by Eloquent: `{id, project_id, user_id, paused_until, resumed_at, failed_at, overdue_notified_at, note, created_at, updated_at}`, datetimes as ISO-8601 UTC strings (`2026-09-21T12:00:00.000000Z`), `null` when the project has no open row. The countdown in the web card uses the plugin's `status.pause_until` (unix int) as the spec says, not this string.
2. **The "…" in the 409-busy and 422 rows** means the same extra keys as the success row: those bodies carry `warnings`, `status`, `open_pause`, `pause_overdue`, `can`. The `plugin_outdated`, `unauthorized` and `unreachable` bodies carry exactly the keys listed in the table and nothing else.
3. **The success body has no `reason` key** (the table row lists none).
4. **`GET /hardening` flags:** plugin 404 → `reachable:true, plugin_outdated:true, status:null` (the site answered; this renders correctly whichever flag the web checks first). 401/403, 5xx, timeouts, non-JSON bodies and unconfigured projects → `reachable:false, plugin_outdated:false, status:null`. `min_version` is always `'2.10.0'`. The GET body has no `reason`/`message`.
5. **Resume is authorized with `pauseHardening`** (the spec defines three abilities and no separate resume ability), so `can.pause` also governs "Re-enable now".
6. **Request bodies sent to the plugin:** `/hardening/rule` gets JSON `{"rule": "<key>", "enabled": true|false}` with a real boolean; `/hardening/pause` gets `{"minutes": 15|30|60}` with a real integer; `/hardening/resume` gets an empty JSON body (`[]`, exactly what the existing `post('/updates/core', [])` sends). The GET carries one extra query parameter `_`.
7. **`status.pause_until` is read only when it is a JSON integer.** Anything else leaves the write-ahead `paused_until` (request time + minutes) in place.
8. **A plugin `reason` the platform does not know is passed through unchanged** with 422, so a newer plugin never breaks the API; a `success:false` without `reason` yields `reason: null` and the web falls back to `message`.
9. **Two different 422 bodies exist on the POST routes:** Laravel validation (`{message, errors}`) and plugin failure (`{success:false, reason, message, …}`). Both have `message`; only the second has `reason`. 403 is Laravel's default `{"message":"This action is unauthorized."}`, 429 is `{"message":"Too Many Attempts."}`.
10. **The overdue notification's database payload** has `type: 'hardening_pause_overdue'` and a ready `message`; the existing web notification list renders `data.message` when present, so lsm-web needs no change for it.

## Self-Review

Checked by running every task's code in an isolated scratch copy of the repo at `e1ff102`: each test file was seen failing for the stated reason, then passing; the controller was rebuilt from the Task 5 → 6 → 7 → 8 → 13 snippets and compared byte for byte with the file the tests ran against; intermediate states after Tasks 5, 6, 7, 8 and 9 were run (24 / 49 / 71 / 81 / 111 passing); full suite at the end: 513 passed, 2 skipped. All quoted line numbers were read from the real files on `feature/htaccess-hardening`.

Review round (same scratch copy, test files and the command re-extracted from this document): the three tests added after review were first run against the pre-review code and failed for the stated reasons — the redirected POST arrived as `GET` with an empty body (Task 1), the key-less pause left 2 rows instead of 1 (Task 7), the failing mail transport stored 2 notifications instead of 1 (Task 11) — and pass with the corrected code. The three backstop log-line rows (Task 11) fail when the `Log::info('hardening', …)` block is removed from the command and pass with it. `php artisan schedule:list | grep hardening` really prints `*/10 *  * * *  php artisan hardening:resume-expired  Next Due: …` (padded columns).

**Spec requirement → task**

| Spec Part 2 / Part 4 requirement | Task |
|---|---|
| `hardeningRequest()` signature, returns `['http','json']`, not through `handleResponse()` | 1 |
| GETs append `_=<microtime>` | 1 (two tests) |
| POSTs use 120 s | 6, 7, 8 (`POST_TIMEOUT`, each asserts `$options['timeout'] === 120`); default 30 s asserted in 1 |
| Routes inside `projects/{project}/lsm`, after `security-headers/snippets` | 5-8 (paths), 9 (registration test) |
| POSTs behind `throttle:12,1` | 9 (as `throttle:12,1,hardening` — own bucket, Deviation 1; Global Constraints states the same string) |
| `pauseHardening` / `enableHardening` / `disableHardening` with the exact bodies; admins via `before()` incl. `is_admin` | 2 |
| `POST /hardening/rule`: boolean cast → authorize → validate; fail closed | 6 |
| GET authorized with `view` | 5 (viewer 403, unassigned manager 200) |
| Mapping row 200 `success:true` → 200 | 4 (unit), 6 (HTTP) |
| Mapping row `busy` → 409 | 4, 6; also 7, 8 |
| Mapping row other failure → 422 | 4, 6; also 7, 8 |
| Mapping row 404 → 409 `plugin_outdated`, `min_version:'2.10.0'` | 4, 6; also 5, 7, 8, 11 |
| Mapping row 401/403 → 502 `unauthorized` | 4, 6; also 7, 8, 11 |
| Mapping row no response/timeout/5xx → 502 `unreachable`, exact message | 4, 6; also 7, 8, 11 |
| All bodies carry `message` and `reason` (failure rows) | 4 |
| `GET /hardening` always 200 with `reachable, plugin_outdated, min_version, status, open_pause, pause_overdue, can` | 5 |
| `can` from `Gate::allows` per user and project; unassigned manager all false | 5 |
| `pause_overdue` = open row past `paused_until` or plugin's flag | 5 (three tests), 8 |
| Table columns and FKs; model `ProjectHardeningPause` | 3 |
| Write-ahead: close open rows, insert before the call | 7 ("written before the plugin is called", "pausing while paused") |
| Explicit `success:false` → delete | 7 (also 404, 401/403 and a key-less project, and the superseded row is reopened — Deviations 3, 4) |
| No response → keep | 7 (configured projects only; a key-less project sends nothing and keeps no row) |
| Success → `paused_until` from plugin `pause_until` | 7 |
| Resume success → close open rows | 8 |
| Turning `block_archives` off → close open rows | 6 |
| GET closes opportunistically when not `paused` | 5 (Deviation 2 for fresh rows) |
| Command name, per-row try/catch | 11 ("a row that blows up…") |
| Trashed / missing / not configured → close with a note | 11 (two tests; "missing" cannot occur, the FK cascades) |
| POST resume, never a GET, 60 s | 11 (first test) |
| Close only when state is `on` | 11 (four-row dataset + closed test) |
| `plugin_outdated` / `unauthorized` → `failed_at` | 11 |
| Row older than 24 h → `failed_at` | 11 (two tests, both sides of the boundary) |
| > 30 min overdue and not notified → one notification to pausing user and admins | 11 (31 vs 29 minutes, two runs, flag holder, bystander, deduplicated admin, missing user; "one" also holds with a failing mail transport — real channels, two runs, one stored notification per recipient) |
| `HardeningPauseOverdueNotification` | 10 |
| Scheduler chain exactly as in the spec | 12 |
| `Log::info('hardening', [...])` on enable/disable/pause/resume | 13 (controller: enable, disable, pause, resume), 11 (the backstop's resume calls, `user_id` null) |
| Part 4: permission matrix through Gate/HTTP incl. `is_admin`, unassigned manager GET 200 / POST 403 | 2 (Gate), 5 (GET 200), 6, 7, 8 (POST 403) |
| Part 4: every mapping row with `Http::fake` | 6 (six row tests), 4 |
| Part 4: write-ahead lifecycle, opportunistic close | 7, 5 |
| Part 4: command — expired→closed; unreachable→open; outdated→failed; >30 min→one notification, not two; trashed→closed | 11 |
| Part 4: rollout order plugin → API → web | Appendix A (human-only runbook, not a task) |

No requirement is without a task. Four things the spec does not ask for were added because existing code or the production setup forces them: the `AllNotificationsRenderTest` dataset entry (Task 10, otherwise the existing suite goes red), the four helpers in `tests/Pest.php`, strict redirect handling in `hardeningRequest()` (Task 1 — a redirected POST would otherwise come back 404 and read as `plugin_outdated`), and the stamp-first / per-recipient guard around the overdue notification (Task 11 — the queue is `sync`, so a mail error is thrown out of `notify()`).

The deployment runbook is Appendix A. It is deliberately not a `### Task`, has no checkboxes and opens with a stop line for agents: every command in it runs against production.

**Shared names → defining task**

| Name | Kind | Task |
|---|---|---|
| `LsmService::hardeningRequest(string, string, array = [], int = 30): array` | method | 1 |
| `['http' => int\|null, 'json' => array\|null]` | return shape | 1 |
| query parameter `_` on plugin GETs | wire | 1 |
| plugin paths `/hardening/status`, `/hardening/rule`, `/hardening/pause`, `/hardening/resume` | wire (spec) | used in 5, 6, 7, 8, 11 |
| `pauseHardening`, `enableHardening`, `disableHardening` | Gate abilities | 2 |
| `project_hardening_pauses` + columns `project_id, user_id, paused_until, resumed_at, failed_at, overdue_notified_at, note` | table | 3 |
| `App\Models\ProjectHardeningPause`, `scopeOpen()`, `project()`, `user()` | model | 3 |
| `HardeningResponseMapper::map()`, `MIN_PLUGIN_VERSION` | class | 4 |
| outcomes `ok, busy, failed, plugin_outdated, unauthorized, unreachable` | internal enum | 4 |
| reason codes `plugin_outdated`, `unauthorized`, `unreachable` | JSON value | 4 |
| plugin reason codes (`busy`, `rule_ineffective`, …) | JSON value (spec, passed through) | 4 |
| JSON keys `success, reason, message, warnings, status, min_version` | response | 4 |
| message `Outcome unknown — refresh status` | response | 4 |
| JSON keys `reachable, plugin_outdated, min_version, status, open_pause, pause_overdue, can{pause,enable,disable}` | response | 5 |
| `GET /api/v1/projects/{project}/lsm/hardening` (`api.v1.projects.lsm.hardening`) | route | 5 |
| `POST …/lsm/hardening/rule` (`…hardening.rule`), body `{rule, enabled}` | route | 6 |
| `POST …/lsm/hardening/pause` (`…hardening.pause`), body `{minutes}` | route | 7 |
| `POST …/lsm/hardening/resume` (`…hardening.resume`) | route | 8 |
| `HardeningController::show / setRule / pause / resume` | controller | 5 / 6 / 7 / 8 |
| private `platformState()`, `closeOpenPauses()`, `IN_FLIGHT_SECONDS` | controller | 5 |
| private `respond()`, `RULES`, `POST_TIMEOUT` | controller | 6 |
| private `logAction()` | controller | 13 |
| `throttle:12,1,hardening` | middleware | 9 |
| `HardeningPauseOverdueNotification(Project, ProjectHardeningPause)`, payload `type: hardening_pause_overdue` | notification | 10 |
| `hardening:resume-expired` | artisan command | 11 |
| `hardening-resume-expired` | schedule name | 12 |
| `Log::info('hardening', {user_id, project_id, action, rule, outcome})` | log line | 13 (controller, via `logAction()`); 11 (command, inline, `user_id` null) — same keys, same `outcome` rule |
| `'allow_redirects' => ['strict' => true]` on hardening calls only | HTTP option | 1 |
| test helpers `hardeningProject()` / `hardeningUser()` / `hardeningPluginStatus()`, `hardeningPluginBody()` | tests/Pest.php | 1 / 2 / 4 |
