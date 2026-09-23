# Managed .htaccess Hardening — Plugin (lsm-wp 2.10.0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. **Dispatch Tasks 1-16 only.** Task 17 and the "Release runbook" after it are human-only (live client sites, fleet-wide release) and are never handed to an implementer.

**Goal:** Give the Landeseiten Maintenance plugin a safe-write engine (`LSM_Hardening`) that turns three `.htaccess` deny rules on and off, pauses the archive rule for a download and puts it back by itself, exposed through four `lsm/v1/hardening/*` REST endpoints — written so carefully that a bad rule cannot take a site down and a failed step never leaves a site silently exposed — and ship it as plugin 2.10.0.

**Architecture:** One instance class, `LSM_Hardening` in `includes/class-lsm-hardening.php`, with overridable seams (`http_request`, `content_dir`, `uploads_dir`, `now`, `server_software`) and a thin static facade `LSM_Hardening::instance()`. It owns a strict marker parser/writer (no `insert_with_markers`), computes status from the files, and runs every platform-initiated change through one procedure: static preflight → `INSERT IGNORE` lock → differential loopback self-test (before/after) around a snapshotted write → rollback on any failure. Auto-resume is a light, fail-closed path on `shutdown`; crash recovery rolls a killed resume forward and everything else back. `LSM_API` gets four thin callbacks; the main file gets one `require_once`, one `init` call and one `deactivate()` call.

**Tech Stack:** PHP 7.4-compatible WordPress plugin code (no composer at runtime, no autoloader), WordPress HTTP API (`wp_remote_get`) and Options API, PHPUnit 9.6 (composer dev dependency at the repo root, outside the zipped plugin dir) with a hand-written stub bootstrap: in-memory options, temp dirs, canned HTTP served by a small fake Apache.

**Spec:** /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api/docs/superpowers/specs/2026-09-21-htaccess-hardening-design.md

## Global Constraints

- **Repo and branch:** repo `/Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp`, branch `feature/htaccess-hardening` from `master`, worked on in the worktree `/Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening` (written `W/` below; plugin dir `W/landeseiten-maintenance/` written `P/`). All paths in every agent task (Tasks 1-16) are inside that worktree. Only the two **human-only** sections — Task 17 and the Release runbook after it — leave it (live sites, `master`, GitHub); an agent never executes anything from those two sections.
- **Never disturb the main working tree.** `lsm-wp` itself is on `fix/concurrent-plugin-update-guard` with uncommitted work in `landeseiten-maintenance/includes/class-lsm-actions.php`. Never run `git checkout` / `switch` / `stash` / `reset` / `commit` in `/Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp`, and put nothing of this feature into `class-lsm-actions.php`.
- **Written against `master` = `850eaf3` (tag `v2.9.4`).** Line numbers quoted in the tasks are that commit's; every edit is located by its code snippet, so a `master` that has moved on only shifts the numbers.
- **Plugin release:** `2.10.0`. The version lives in exactly two places: `P/landeseiten-maintenance.php` line 5 (` * Version: 2.9.4`) and line 22 (`define('LSM_VERSION', '2.9.4');`). Tag must be `v2.10.0`.
- **PHP 7.4-compatible:** no enums, no `readonly`, no `match`, no named arguments, no union types, no nullsafe `?->`, no `str_contains` / `str_starts_with` / `str_ends_with`. Cast `substr()` results you concatenate with `(string)` (PHP 7 returns `false` at end of string).
- **House style (not WPCS):** 4 spaces, no tabs; short arrays `[]` with trailing commas and aligned `=>` in multi-line arrays; opening brace on the same line; classes `LSM_Foo` in `class-lsm-foo.php`; snake_case methods; options and log actions prefixed `lsm_` / `hardening_`; docblock on every method (`@param`, `@return`); section banners `// ====…`; every include file starts with the `if (!defined('ABSPATH')) { exit; }` guard; API `message` strings are plain untranslated English.
- **All rules default to off. Installing or updating the plugin changes nothing on any site.** `activate()` never writes (it re-runs after every self-update). No uninstall handler in v1. No existing behaviour changes.
- **Paths always come from `WP_CONTENT_DIR` and `wp_upload_dir()['basedir']`.** Never the root `.htaccess`. `pending` stores an enum (`'content'|'uploads'`), never a path. The snapshot `.htaccess.lsm-bak` exists on disk only while an operation is pending.
- **Rules (key → file → pattern, emitted with plain `|`):**
  - `block_archives` → `WP_CONTENT_DIR/.htaccess` → `<FilesMatch "(?i)\.((wpress|sql|zip|tar|tgz|bak)|(sql|tar|bak|wpress|zip)\.gz)$">` (bare `.gz` is never matched)
  - `block_debug_log` → `WP_CONTENT_DIR/.htaccess` → `<Files "debug.log">`
  - `block_uploads_php` → `wp_upload_dir()['basedir']/.htaccess` → `<FilesMatch "(?i)\.(php[0-9]?|phtml?|pht|phps|phar)(\.|$)">`
- **Rule body, both syntaxes, never a "granted" directive in either branch** (LiteSpeed Enterprise ignores `<IfModule>` tests and executes both): `<IfModule mod_authz_core.c>` / `Require all denied` / `</IfModule>` / `<IfModule !mod_authz_core.c>` / `Order deny,allow` / `Deny from all` / `</IfModule>`.
- **Markers:** `# BEGIN LSM-HARDENING` / `# END LSM-HARDENING`, one block per file, regenerated whole. Strict parse: exactly zero or one BEGIN, each BEGIN followed by an END, no END without BEGIN; anything else → `markers_corrupt`, nothing written. Own writer, never `insert_with_markers()`. Write with `file_put_contents($file, $content, LOCK_EX)`, re-read, compare sha1.
- **State option `lsm_hardening` (autoloaded):** `rules: {block_archives, block_debug_log, block_uploads_php: bool}` (desired), `pause_until: int|null`, `last_attempt_at: int|null`, `pending: {target: 'content'|'uploads', op: 'enable'|'disable'|'pause'|'resume', started_at: int, existed: bool}|null`, `last_result: {at, action, rule, ok, reason, warnings[]}`, `rule_failures: {<rule>: {at, reason}}`. `rules` and `pause_until` are committed only after the self-test passed.
- **States (status is read from the file, in this priority):** `unsupported`, `paused`, `on`, `manual`, `drift`, `off`. `unsupported_reason`: `multisite`, `openlitespeed`, `unknown_server`, `not_writable`.
- **Reason codes (failure):** `busy`, `invalid_rule`, `invalid_minutes`, `not_enabled`, `unsupported`, `loopback_blocked`, `markers_corrupt`, `snapshot_failed`, `write_failed`, `asset_broken`, `rule_ineffective`, `pause_ineffective_foreign_rule`, `rollback_failed`. `last_result.reason` may also be `crash_recovered`. **Warnings:** `already_blocked_elsewhere`, `unverified`. **`last_result.action`:** `enable`, `disable`, `pause`, `resume`, `auto_resume`, `crash_recovery`, `deactivate`.
- **Lock:** one options row `lsm_hardening_lock` taken with `INSERT IGNORE` (as `WP_Upgrader::create_lock()` does), value = unix time, autoload `no`; a lock older than 180 s is stale: delete and retry once. Busy → `success:false, reason:'busy'`; busy never changes any state or `last_result`. Never a transient, never `add_option()` for the insert (check-then-upsert, not atomic).
- **Every loopback:** timeout 5 s, `redirection => 0`, no cookies, `sslverify => apply_filters('https_local_ssl_verify', false, $url)`, header `Cache-Control: no-cache`. `@set_time_limit(120)` at the start of an apply. Do not rely on `ignore_user_abort`.
- **Cache-busters:** the plugin stylesheet (baseline 2a) and **every single probe fetch** get a fresh `lsm_hardening=<8hex>` query parameter, generated at fetch time. The same probe URL is fetched before and after the write; Cloudflare and host CDNs cache `.zip` by extension (a 200 for ~2 h, keyed by URL + query) and ignore the request's `Cache-Control: no-cache`, so without a fresh query string the "after" fetch would be answered from the edge with the cached "before" 200 → a false `rule_ineffective`. The homepage (baseline 2b) never carries a query string.
- **Self-test verdicts:** 401, 5xx, `WP_Error` or a `cf-mitigated: challenge` header on any probe is never "403 effective" → `loopback_blocked`. A rule turned on needs exactly 403 on every probe. The PHP-in-uploads probe file is **never created** — so a `lsm-probe-*.php` on disk is never ours, and neither the cleanup nor the scanner exclusion may treat it as ours.
- **Never write over bytes you did not read.** An existing `.htaccess` that cannot be read is `not_writable` (never "empty"), and the bytes a candidate was built from are re-read right before the snapshot: if something else changed the file while the loopbacks ran, nothing is written (`write_failed`).
- **Pause:** minutes ∈ {15, 30, 60}. Auto-resume throttle 300 s, crash-recovery threshold 180 s (recovery takes the lock first). Auto-resume runs on `shutdown` (late priority) after `fastcgi_finish_request()` / `litespeed_finish_request()`, never inline, never when `PHP_SAPI === 'cli'`, never on a request to `lsm/v1/hardening/*`; `pause_until` is cleared only after the read-back shows the rule in the block.
- **REST:** namespace `lsm/v1`, `permission_callback` = `authenticate`, routes `GET /hardening/status`, `POST /hardening/rule` `{rule, enabled}`, `POST /hardening/pause` `{minutes}`, `POST /hardening/resume`. No `args`; manual validation in the callback (`in_array(..., true)`, `rest_sanitize_boolean`). Every response is HTTP 200, sends `Cache-Control: no-store, private`, and has the top-level shape `{success, reason, message, warnings, status}` — no `data` wrapper.
- **Load-time work is hooked in `Landeseiten_Maintenance::init()` after `LSM_Logger::init()`** — never in the constructor or `init_security_filters()`: `LSM_Logger::log()` calls the pluggable `wp_get_current_user()`, which does not exist while plugins are being included.
- **`LSM_Logger::log($action, $status, $context)`:** snake_case action, status one of `success|info|warning|error`, array context. Never a free-form sentence as the action.
- **Tests live outside `landeseiten-maintenance/`** (`build-release.sh` zips that whole directory). Run with `vendor/bin/phpunit -c phpunit.xml.dist` from `W/`, plus `php -l` on every touched PHP file after each implementation step. Tests must also run under the zero-install fallback (PHPUnit 12): no `@dataProvider` or other annotations, test methods named `test_*`, `setUp(): void`.
- **Commits:** conventional-commit messages (`feat(hardening): …`, `test(harness): …`, `chore(release): …`), each ending with the line `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`. Always `git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening …`.

---

## File Structure

All paths relative to the worktree `W/` = `/Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening`.

**Create (plugin — shipped in the zip):**
- `landeseiten-maintenance/includes/class-lsm-hardening.php` — `LSM_Hardening`: the whole engine (rules, block builder, strict parser/writer, adoption matcher, state/status/preflight, lock, loopback self-test, apply + rollback, pause/resume, auto-resume, crash recovery, deactivation).

**Modify (plugin):**
- `landeseiten-maintenance/landeseiten-maintenance.php` — `includes()` line 85 (one `require_once`), `init()` lines 221-222 (one `on_init()` call), `deactivate()` lines 423-430 (one `deactivate()` call), version lines 5 and 22.
- `landeseiten-maintenance/includes/class-lsm-api.php` — four routes after line 324 (`/security/headers/snippets`), four callbacks + one private helper after line 2190 (end of `get_security_header_snippets()`).
- `landeseiten-maintenance/includes/class-lsm-security-scanner.php` — one `continue` line each in `find_files_by_extension()` (after line 485), `find_double_extensions()` (after line 512) and `find_hidden_files()` (after line 542): skip the engine's own artifacts — exactly `lsm-probe-<16hex>.zip|.wpress` and `.htaccess.lsm-bak`, never a PHP name.

**Create (dev tooling — never shipped):**
- `composer.json`, `composer.lock` — PHPUnit `^9.6` as the only dev dependency.
- `phpunit.xml.dist` — one suite, `tests/`, bootstrap `tests/bootstrap.php`.
- `tests/bootstrap.php` — constants (`ABSPATH`, `WP_CONTENT_DIR`, `LSM_*`), loads the fakes, the classes under test and the test doubles.
- `tests/stubs/wp-functions.php` — `LSM_Test_Env` plus in-memory fakes: options, hooks, URLs, canned HTTP, `$wpdb`, REST classes, `LSM_Logger`.
- `tests/FakeServer.php` — `LSM_Fake_Server`: the canned-HTTP handler; honours the `.htaccess` files the engine really wrote, with scripted overrides.
- `tests/TestableHardening.php` — `LSM_Testable_Hardening`: the engine with its seams pointed at temp dirs and a fixed clock.
- `tests/HardeningTestCase.php` — base test case: fresh temp `wp-content` per test, engine, fake server, helpers.
- `tests/Fixtures.php` — `LSM_Htaccess_Fixtures`: the real manual blocks from the audit artifacts.
- `tests/HarnessTest.php`, `tests/FakeServerTest.php` — prove the harness.
- `tests/HardeningBlockTest.php`, `HardeningWriterTest.php`, `HardeningManualTest.php`, `HardeningStatusTest.php`, `HardeningLockTest.php`, `HardeningLoopbackTest.php`, `HardeningApplyTest.php`, `HardeningPauseTest.php`, `HardeningAutoResumeTest.php`, `HardeningRecoveryTest.php`, `HardeningDeactivateTest.php`, `HardeningRestTest.php`, `HardeningWiringTest.php` — one file per engine task.
- `tests/tools/check-manual-blocks.php` + `tests/ManualBlockToolTest.php` — the one-off pre-release check of fleet `.htaccess` files against the adoption matcher.
- `tests/VersionTest.php` — header and constant agree and are ≥ 2.10.0.

**Modify (dev tooling):**
- `.gitignore` — add `vendor/` and `.phpunit.result.cache` (`build-release.sh` runs `git add -A`).

How the class file grows: Task 2 creates it complete with its closing `}`. Every later engine task says "insert above the final `}` of the class" — paste the given methods, preceded by one blank line, directly above that last line. Only Task 11 edits an existing method (`on_init()`).

---

### Task 1: Worktree, branch and test harness

**Files:**
- Create: `composer.json`, `phpunit.xml.dist`, `tests/bootstrap.php`, `tests/stubs/wp-functions.php`, `tests/FakeServer.php`
- Modify: `.gitignore` (append 3 lines at the end)
- Test: `tests/HarnessTest.php`, `tests/FakeServerTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces (test-only names every later task relies on):
  - `LSM_Test_Env` static bag: `$options`, `$actions` (list of `[hook, callback, priority]`), `$filters`, `$multisite`, `$http` (callable `function($url, $args)`), `$uploads_dir`, `$log` (list of `[action, status, context]`), `$db_var`, `$db_queries`, `$routes` (`"METHOD namespace/route" => args`), and `LSM_Test_Env::reset()`.
  - Fakes: `get_option`, `add_option` (returns `false` when the option exists), `update_option`, `delete_option`, `add_action`, `apply_filters`, `is_multisite`, `wp_upload_dir`, `content_url`, `home_url`, `add_query_arg`, `WP_Error`, `is_wp_error`, `wp_remote_get`, `wp_remote_retrieve_response_code|body|header`, `$GLOBALS['wpdb']` (`LSM_Test_Wpdb`), `WP_REST_Request`, `WP_REST_Response`, `rest_ensure_response`, `register_rest_route`, `rest_is_boolean`, `rest_sanitize_boolean`, stub class `LSM_Logger` with `log()`.
  - A canned HTTP response is `['response' => ['code' => int], 'body' => string, 'headers' => [lowercase-name => value]]`.
  - `LSM_Fake_Server($content_dir, $uploads_dir)`: `handle($url, $args)`, `script($regex, array $responses)` (items: response array, `WP_Error`, `'pass'`, or a `Throwable` to throw), `always($regex, $response)`, `requests_matching($regex)`, `static response($code, $body = '', array $headers = [])`, public `$requests` (list of `['url', 'args', 'file_existed']`), `$foreign_deny` and `$bypass_htaccess` (each a regex of URL **paths** — matched against the path only, so a `$`-anchored pattern like `'~\.zip$~'` still matches a probe URL that carries a `?lsm_hardening=…` cache-buster; `script()` / `always()` / `requests_matching()` match the full URL), `$rejected_directive`, `$home_body`. URLs: `http://example.test/wp-content/...` maps to `$content_dir`, `http://example.test/wp-content/uploads/...` to `$uploads_dir`, anything else is the homepage.
  - Constants from the bootstrap: `ABSPATH`, `WP_CONTENT_DIR` (fixed dir below the system temp dir), `DAY_IN_SECONDS`, `LSM_VERSION` (`'0.0.0-test'`), `LSM_PLUGIN_DIR` (the real plugin dir), `LSM_PLUGIN_URL` (`'http://example.test/wp-content/plugins/landeseiten-maintenance/'`).

- [ ] **Step 1: Create the worktree and the branch without touching the dirty tree**

```bash
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp worktree add /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening -b feature/htaccess-hardening master
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp status --short
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening log --oneline -1
```

Expected: `Preparing worktree (new branch 'feature/htaccess-hardening')`; the status of the main tree still shows exactly ` M landeseiten-maintenance/includes/class-lsm-actions.php` (its uncommitted work is untouched); the worktree is at `850eaf3` (tag `v2.9.4`).

- [ ] **Step 2: Keep `vendor/` out of the repo** — append to `W/.gitignore`, after one blank line (the file currently ends with `*.md`):

```text
# Dev tooling: PHPUnit runs from the repo root, outside the zipped plugin dir
vendor/
.phpunit.result.cache
```

- [ ] **Step 3: Create `W/composer.json`**

```json
{
    "name": "landeseiten/lsm-wp",
    "description": "Development tooling for the Landeseiten Maintenance plugin (not shipped in the release zip).",
    "type": "project",
    "license": "GPL-2.0-or-later",
    "require-dev": {
        "phpunit/phpunit": "^9.6"
    },
    "config": {
        "sort-packages": true
    }
}
```

- [ ] **Step 4: Install PHPUnit**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && composer install --no-interaction --no-progress
```

Expected: `Installing phpunit/phpunit (9.6.x)`, a `vendor/` directory and a `composer.lock`. `git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening status --short` must not list `vendor/`.

Zero-install fallback, only if packagist is unreachable — every `vendor/bin/phpunit -c phpunit.xml.dist` in this plan can be replaced by (run from `W/`; PHPUnit 12 picks up `phpunit.xml.dist` by itself):

```bash
/Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests
```

- [ ] **Step 5: Create `W/phpunit.xml.dist`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
         failOnWarning="true"
         failOnRisky="true">
    <testsuites>
        <testsuite name="lsm-wp">
            <directory suffix="Test.php">tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 6: Write the failing tests** — `W/tests/HarnessTest.php`:

```php
<?php

use PHPUnit\Framework\TestCase;

/**
 * Proves the hand-written WordPress fakes behave the way the plugin relies on.
 */
class HarnessTest extends TestCase {

    protected function setUp(): void {
        LSM_Test_Env::reset();
    }

    public function test_add_option_refuses_an_existing_option() {
        $this->assertTrue(add_option('lsm_x', 1, '', 'no'));
        $this->assertFalse(add_option('lsm_x', 2, '', 'no'));
        $this->assertSame(1, get_option('lsm_x'));
    }

    public function test_update_get_and_delete_option() {
        $this->assertSame('fallback', get_option('lsm_y', 'fallback'));
        update_option('lsm_y', ['a' => 1], true);
        $this->assertSame(['a' => 1], get_option('lsm_y'));
        $this->assertTrue(delete_option('lsm_y'));
        $this->assertFalse(delete_option('lsm_y'));
    }

    public function test_canned_http_goes_through_the_env_handler() {
        $seen = [];
        LSM_Test_Env::$http = function ($url, $args) use (&$seen) {
            $seen[] = [$url, $args];
            return ['response' => ['code' => 403], 'body' => 'denied', 'headers' => ['cf-mitigated' => 'challenge']];
        };

        $response = wp_remote_get('http://example.test/x', ['timeout' => 5]);

        $this->assertSame([['http://example.test/x', ['timeout' => 5]]], $seen);
        $this->assertSame(403, wp_remote_retrieve_response_code($response));
        $this->assertSame('denied', wp_remote_retrieve_body($response));
        $this->assertSame('challenge', wp_remote_retrieve_header($response, 'CF-Mitigated'));
    }

    public function test_http_without_a_handler_is_a_wp_error() {
        $this->assertTrue(is_wp_error(wp_remote_get('http://example.test/')));
    }

    public function test_rest_fakes_record_routes_and_carry_headers() {
        register_rest_route('lsm/v1', '/x', ['methods' => 'POST', 'callback' => 'strtoupper']);
        $this->assertSame(['POST lsm/v1/x'], array_keys(LSM_Test_Env::$routes));

        $response = rest_ensure_response(['success' => true]);
        $response->header('Cache-Control', 'no-store, private');
        $this->assertSame(200, $response->get_status());
        $this->assertSame(['success' => true], $response->get_data());
        $this->assertSame(['Cache-Control' => 'no-store, private'], $response->get_headers());

        $request = new WP_REST_Request(['enabled' => 'false']);
        $this->assertNull($request->get_param('rule'));
        $this->assertTrue(rest_is_boolean($request->get_param('enabled')));
        $this->assertFalse(rest_sanitize_boolean($request->get_param('enabled')), '(bool) "false" would be true');
        $this->assertFalse(rest_is_boolean('yes please'));
    }
}
```

and `W/tests/FakeServerTest.php`:

```php
<?php

use PHPUnit\Framework\TestCase;

/**
 * The fake server is what every self-test in this suite talks to, so it gets its own proof.
 */
class FakeServerTest extends TestCase {

    /** @var string */
    private $root;

    /** @var LSM_Fake_Server */
    private $server;

    protected function setUp(): void {
        $this->root = rtrim(sys_get_temp_dir(), '/\\') . '/lsm-fake-server-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/wp-content/uploads', 0777, true);
        $this->server = new LSM_Fake_Server($this->root . '/wp-content', $this->root . '/wp-content/uploads');
    }

    protected function tearDown(): void {
        foreach (['/wp-content/uploads/.htaccess', '/wp-content/uploads/a.php', '/wp-content/.htaccess', '/wp-content/backup.zip', '/wp-content/style.css'] as $file) {
            @unlink($this->root . $file);
        }
        rmdir($this->root . '/wp-content/uploads');
        rmdir($this->root . '/wp-content');
        rmdir($this->root);
    }

    private function code($url) {
        return $this->server->handle($url, [])['response']['code'];
    }

    public function test_serves_existing_files_and_404s_the_rest() {
        file_put_contents($this->root . '/wp-content/style.css', 'body{}');

        $response = $this->server->handle('http://example.test/wp-content/style.css?ver=1', ['timeout' => 5]);

        $this->assertSame(200, $response['response']['code']);
        $this->assertSame('body{}', $response['body']);
        $this->assertSame(404, $this->code('http://example.test/wp-content/missing.css'));
        $this->assertSame(200, $this->code('http://example.test/'), 'anything outside wp-content is the homepage');
        $this->assertSame(
            ['url' => 'http://example.test/wp-content/style.css?ver=1', 'args' => ['timeout' => 5], 'file_existed' => true],
            $this->server->requests[0]
        );
        $this->assertFalse($this->server->requests[1]['file_existed']);
    }

    public function test_honours_deny_containers_like_apache_even_for_missing_files() {
        file_put_contents($this->root . '/wp-content/backup.zip', 'PK');
        file_put_contents($this->root . '/wp-content/uploads/a.php', '<?php');
        $this->assertSame(200, $this->code('http://example.test/wp-content/backup.zip'));

        file_put_contents($this->root . '/wp-content/.htaccess', "<FilesMatch \"(?i)\\.(zip)$\">\n  Require all denied\n</FilesMatch>\n<Files \"debug.log\">\nDeny from all\n</Files>\n");
        file_put_contents($this->root . '/wp-content/uploads/.htaccess', "<FilesMatch \"\\.php$\">\nDeny from all\n</FilesMatch>\n");

        $this->assertSame(403, $this->code('http://example.test/wp-content/backup.zip'));
        $this->assertSame(403, $this->code('http://example.test/wp-content/uploads/2026/09/OTHER.ZIP'), 'wp-content rules are inherited by uploads');
        $this->assertSame(403, $this->code('http://example.test/wp-content/debug.log'), 'authorization comes before the 404');
        $this->assertSame(403, $this->code('http://example.test/wp-content/uploads/a.php'));
        $this->assertSame(403, $this->code('http://example.test/wp-content/uploads/not-there.php'));
        $this->assertSame(404, $this->code('http://example.test/wp-content/not-there.php'), 'the uploads rule does not apply one level up');
    }

    public function test_scripts_are_consumed_in_order_and_pass_means_simulate() {
        $this->server->script('~style~', [LSM_Fake_Server::response(503), 'pass', new WP_Error('http_request_failed', 'timeout')]);

        $this->assertSame(503, $this->code('http://example.test/wp-content/style.css'));
        $this->assertSame(404, $this->code('http://example.test/wp-content/style.css'));
        $this->assertTrue(is_wp_error($this->server->handle('http://example.test/wp-content/style.css', [])));
        $this->assertSame(404, $this->code('http://example.test/wp-content/style.css'), 'exhausted script falls through');
    }

    public function test_a_throwable_in_a_script_is_thrown() {
        $this->server->script('~.~', [new RuntimeException('killed')]);
        $this->expectException(RuntimeException::class);
        $this->server->handle('http://example.test/', []);
    }

    public function test_always_foreign_deny_bypass_and_rejected_directive() {
        file_put_contents($this->root . '/wp-content/backup.zip', 'PK');
        file_put_contents($this->root . '/wp-content/.htaccess', "<FilesMatch \"\\.zip$\">\nRequire all denied\n</FilesMatch>\n");

        $this->server->bypass_htaccess = '~\.zip$~';
        $this->assertSame(200, $this->code('http://example.test/wp-content/backup.zip'), 'nginx serves static files itself');
        $this->assertSame(200, $this->code('http://example.test/wp-content/backup.zip?lsm_hardening=0a1b2c3d'), 'the knob looks at the path, not at the query string');
        $this->server->bypass_htaccess = null;

        $this->server->foreign_deny = '~\.css$~';
        $this->assertSame(403, $this->code('http://example.test/wp-content/style.css'));
        $this->assertSame(403, $this->code('http://example.test/wp-content/style.css?ver=1'), 'the knob looks at the path, not at the query string');
        $this->server->foreign_deny = null;

        $this->server->rejected_directive = 'Require all denied';
        $this->assertSame(500, $this->code('http://example.test/wp-content/style.css'), 'a rejected directive breaks the whole directory');
        $this->assertSame(200, $this->code('http://example.test/'));
        $this->server->rejected_directive = null;

        $this->server->always('~^http://example\.test/$~', LSM_Fake_Server::response(301));
        $this->assertSame(301, $this->code('http://example.test/'));
        $this->assertCount(2, $this->server->requests_matching('~backup\.zip~'));
        $this->assertCount(1, $this->server->requests_matching('~backup\.zip$~'), 'requests_matching() sees the full URL, query string included');
    }
}
```

- [ ] **Step 7: Run them and watch them fail**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && vendor/bin/phpunit -c phpunit.xml.dist
```

Expected: FAIL — PHPUnit stops with `Cannot open file "./tests/bootstrap.php".`

- [ ] **Step 8: Create `W/tests/stubs/wp-functions.php`**

```php
<?php
/**
 * In-memory fakes for the WordPress functions the plugin classes under test call.
 *
 * Everything mutable lives on LSM_Test_Env so a test can reset it in setUp().
 */

class LSM_Test_Env {

    /** @var array option name => value */
    public static $options = [];

    /** @var array list of [hook, callback, priority] */
    public static $actions = [];

    /** @var array filter name => value returned by apply_filters() */
    public static $filters = [];

    /** @var bool */
    public static $multisite = false;

    /** @var callable|null function($url, $args) returning a response array or WP_Error */
    public static $http = null;

    /** @var string uploads basedir returned by wp_upload_dir() */
    public static $uploads_dir = '';

    /** @var array list of [action, status, context] recorded by the LSM_Logger stub */
    public static $log = [];

    /** @var int value returned by the fake $wpdb->get_var() */
    public static $db_var = 0;

    /** @var array SQL strings passed to the fake $wpdb->get_var() */
    public static $db_queries = [];

    /** @var array "METHOD namespace/route" => args passed to register_rest_route() */
    public static $routes = [];

    public static function reset() {
        self::$options     = [];
        self::$actions     = [];
        self::$filters     = [];
        self::$multisite   = false;
        self::$http        = null;
        self::$uploads_dir = WP_CONTENT_DIR . '/uploads';
        self::$log         = [];
        self::$db_var      = 0;
        self::$db_queries  = [];
        self::$routes      = [];
    }
}

LSM_Test_Env::reset();

// -----------------------------------------------------------------------------
// Options. add_option() refuses an existing option, as WordPress does.
// (Real WordPress checks get_option() first and then upserts, so it is not strictly
// atomic; this single-process fake cannot show the difference.)
// -----------------------------------------------------------------------------

function get_option($option, $default = false) {
    return array_key_exists($option, LSM_Test_Env::$options) ? LSM_Test_Env::$options[$option] : $default;
}

function add_option($option, $value = '', $deprecated = '', $autoload = 'yes') {
    if (array_key_exists($option, LSM_Test_Env::$options)) {
        return false;
    }
    LSM_Test_Env::$options[$option] = $value;
    return true;
}

function update_option($option, $value, $autoload = null) {
    LSM_Test_Env::$options[$option] = $value;
    return true;
}

function delete_option($option) {
    if (!array_key_exists($option, LSM_Test_Env::$options)) {
        return false;
    }
    unset(LSM_Test_Env::$options[$option]);
    return true;
}

// -----------------------------------------------------------------------------
// Hooks and environment.
// -----------------------------------------------------------------------------

function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    LSM_Test_Env::$actions[] = [$hook, $callback, $priority];
    return true;
}

function apply_filters($hook, $value) {
    return array_key_exists($hook, LSM_Test_Env::$filters) ? LSM_Test_Env::$filters[$hook] : $value;
}

function is_multisite() {
    return LSM_Test_Env::$multisite;
}

function wp_upload_dir() {
    return [
        'basedir' => LSM_Test_Env::$uploads_dir,
        'baseurl' => 'http://example.test/wp-content/uploads',
    ];
}

function content_url($path = '') {
    return 'http://example.test/wp-content' . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

function home_url($path = '') {
    return 'http://example.test' . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

function add_query_arg($key, $value, $url) {
    return $url . (strpos($url, '?') === false ? '?' : '&') . rawurlencode($key) . '=' . rawurlencode($value);
}

// -----------------------------------------------------------------------------
// HTTP. Canned: every request goes to LSM_Test_Env::$http.
// A response is ['response' => ['code' => int], 'body' => string, 'headers' => [lowercase-name => value]].
// -----------------------------------------------------------------------------

class WP_Error {

    private $code;
    private $message;

    public function __construct($code = '', $message = '') {
        $this->code    = $code;
        $this->message = $message;
    }

    public function get_error_code() {
        return $this->code;
    }

    public function get_error_message() {
        return $this->message;
    }
}

function is_wp_error($thing) {
    return $thing instanceof WP_Error;
}

function wp_remote_get($url, $args = []) {
    if (LSM_Test_Env::$http === null) {
        return new WP_Error('http_request_failed', 'No canned HTTP handler set');
    }
    return call_user_func(LSM_Test_Env::$http, $url, $args);
}

function wp_remote_retrieve_response_code($response) {
    if (is_wp_error($response) || !isset($response['response']['code'])) {
        return '';
    }
    return $response['response']['code'];
}

function wp_remote_retrieve_body($response) {
    if (is_wp_error($response) || !isset($response['body'])) {
        return '';
    }
    return $response['body'];
}

function wp_remote_retrieve_header($response, $header) {
    if (is_wp_error($response) || !isset($response['headers'][strtolower($header)])) {
        return '';
    }
    return $response['headers'][strtolower($header)];
}

// -----------------------------------------------------------------------------
// Database: just enough of $wpdb for one COUNT query and the INSERT IGNORE lock.
// -----------------------------------------------------------------------------

class LSM_Test_Wpdb {

    public $posts = 'wp_posts';

    public $options = 'wp_options';

    /**
     * Only understands the lock's INSERT IGNORE: 1 when the row was inserted,
     * 0 when the option already exists — the same answer MySQL gives.
     */
    public function query($query) {
        if (!preg_match("/^INSERT IGNORE INTO wp_options .*VALUES \\('([^']*)', '([^']*)'/", $query, $m)) {
            return false;
        }
        if (array_key_exists($m[1], LSM_Test_Env::$options)) {
            return 0;
        }
        LSM_Test_Env::$options[$m[1]] = $m[2];
        return 1;
    }

    public function prepare($query, $args = []) {
        $args = is_array($args) ? $args : array_slice(func_get_args(), 1);
        return vsprintf(str_replace('%s', "'%s'", $query), $args);
    }

    public function get_var($query) {
        LSM_Test_Env::$db_queries[] = $query;
        return (string) LSM_Test_Env::$db_var;
    }
}

$GLOBALS['wpdb'] = new LSM_Test_Wpdb();

// -----------------------------------------------------------------------------
// LSM_Logger stub: records calls instead of writing the activity log.
// -----------------------------------------------------------------------------

class LSM_Logger {

    public static function log($action, $status = 'info', $context = []) {
        LSM_Test_Env::$log[] = [$action, $status, $context];
    }
}

// -----------------------------------------------------------------------------
// REST: just enough to register routes and call the callbacks directly.
// -----------------------------------------------------------------------------

class WP_REST_Request {

    private $params;

    public function __construct(array $params = []) {
        $this->params = $params;
    }

    public function get_param($key) {
        return array_key_exists($key, $this->params) ? $this->params[$key] : null;
    }
}

class WP_REST_Response {

    private $data;
    private $status = 200;
    private $headers = [];

    public function __construct($data = null) {
        $this->data = $data;
    }

    public function get_data() {
        return $this->data;
    }

    public function get_status() {
        return $this->status;
    }

    public function header($key, $value) {
        $this->headers[$key] = $value;
    }

    public function get_headers() {
        return $this->headers;
    }
}

function rest_ensure_response($response) {
    return $response instanceof WP_REST_Response ? $response : new WP_REST_Response($response);
}

function register_rest_route($namespace, $route, $args = []) {
    LSM_Test_Env::$routes[$args['methods'] . ' ' . $namespace . $route] = $args;
    return true;
}

// Copies of the core functions (wp-includes/rest-api.php).
function rest_is_boolean($maybe_bool) {
    if (is_bool($maybe_bool)) {
        return true;
    }
    if (is_string($maybe_bool)) {
        $maybe_bool = strtolower($maybe_bool);
        return in_array($maybe_bool, ['false', 'true', '0', '1'], true);
    }
    if (is_int($maybe_bool)) {
        return in_array($maybe_bool, [0, 1], true);
    }
    return false;
}

function rest_sanitize_boolean($value) {
    if (is_string($value)) {
        $value = strtolower($value);
        if (in_array($value, ['false', '0'], true)) {
            $value = false;
        }
    }
    return (bool) $value;
}
```

- [ ] **Step 9: Create `W/tests/FakeServer.php`**

```php
<?php
/**
 * A tiny stand-in for Apache, used as the canned HTTP handler.
 *
 * It maps http://example.test/wp-content/... onto two real temp directories and
 * honours the <FilesMatch "..."> and <Files "..."> containers it finds in their
 * .htaccess files, so a test observes what the engine really wrote: 403 when a
 * rule covers the file, 200 + file body when it exists, 404 otherwise.
 *
 * Scripted responses (script()/always()) override the simulation per URL.
 */
class LSM_Fake_Server {

    /** @var string */
    public $content_dir;

    /** @var string */
    public $uploads_dir;

    /** @var array list of ['url' => string, 'args' => array, 'file_existed' => bool] */
    public $requests = [];

    /** @var string|null regex of URL paths (no query string) answered 403 no matter what the .htaccess says (a vhost / WAF rule) */
    public $foreign_deny = null;

    /** @var string|null regex of URL paths (no query string) served without looking at .htaccess (nginx serving static files) */
    public $bypass_htaccess = null;

    /** @var string|null a directive this "server" rejects: any .htaccess containing it answers 500 (AllowOverride without AuthConfig) */
    public $rejected_directive = null;

    /** @var string body of the homepage */
    public $home_body = '<html>home</html>';

    /** @var array list of [regex, responses[]] consumed one response per matching request */
    private $scripts = [];

    /** @var array list of [regex, response] applied to every matching request */
    private $always = [];

    public function __construct($content_dir, $uploads_dir) {
        $this->content_dir = $content_dir;
        $this->uploads_dir = $uploads_dir;
    }

    /**
     * Build a response array.
     */
    public static function response($code, $body = '', array $headers = []) {
        return ['response' => ['code' => $code], 'body' => $body, 'headers' => $headers];
    }

    /**
     * Queue responses for URLs matching $regex. Each matching request consumes one;
     * the string 'pass' means "answer this one from the simulation". An exhausted
     * queue falls through to the simulation. A Throwable in the queue is thrown.
     */
    public function script($regex, array $responses) {
        $this->scripts[] = [$regex, $responses];
    }

    /**
     * Answer every request matching $regex with $response.
     */
    public function always($regex, $response) {
        $this->always[] = [$regex, $response];
    }

    /**
     * Requests whose URL matches $regex.
     */
    public function requests_matching($regex) {
        return array_values(array_filter($this->requests, function ($request) use ($regex) {
            return preg_match($regex, $request['url']) === 1;
        }));
    }

    /**
     * The canned HTTP handler: LSM_Test_Env::$http = [$server, 'handle'].
     */
    public function handle($url, $args) {
        $file = $this->file_for($url);
        $this->requests[] = [
            'url'          => $url,
            'args'         => $args,
            'file_existed' => $file !== null && is_file($file),
        ];

        foreach ($this->scripts as $i => $script) {
            if (preg_match($script[0], $url) && !empty($script[1])) {
                $next = array_shift($this->scripts[$i][1]);
                if ($next instanceof Throwable) {
                    throw $next;
                }
                if ($next !== 'pass') {
                    return $next;
                }
                return $this->simulate($url, $file);
            }
        }

        foreach ($this->always as $rule) {
            if (preg_match($rule[0], $url)) {
                return $rule[1];
            }
        }

        return $this->simulate($url, $file);
    }

    /**
     * Map a URL onto a file below the temp wp-content, or null for non-content URLs.
     */
    private function file_for($url) {
        $path   = (string) parse_url($url, PHP_URL_PATH);
        $prefix = '/wp-content/';
        if (strpos($path, $prefix) !== 0) {
            return null;
        }
        $relative = substr($path, strlen($prefix));
        if (strpos($relative, 'uploads/') === 0) {
            return $this->uploads_dir . '/' . substr($relative, strlen('uploads/'));
        }
        return $this->content_dir . '/' . $relative;
    }

    private function simulate($url, $file) {
        if ($file === null) {
            return self::response(200, $this->home_body);
        }

        // The two knobs look at the path only: the engine appends a fresh cache-buster to every
        // probe URL, and their patterns are anchored with "$" ('~\.zip$~').
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($this->foreign_deny !== null && preg_match($this->foreign_deny, $path)) {
            return self::response(403, 'Forbidden');
        }

        $bypass = $this->bypass_htaccess !== null && preg_match($this->bypass_htaccess, $path);
        if (!$bypass) {
            // .htaccess files apply from wp-content downwards.
            $chain = [$this->content_dir . '/.htaccess'];
            if (strpos($file, $this->uploads_dir . '/') === 0) {
                $chain[] = $this->uploads_dir . '/.htaccess';
            }
            foreach ($chain as $htaccess) {
                if (!is_file($htaccess)) {
                    continue;
                }
                $rules = (string) file_get_contents($htaccess);
                if ($this->rejected_directive !== null && strpos($rules, $this->rejected_directive) !== false) {
                    return self::response(500, 'Internal Server Error');
                }
                if ($this->denies($rules, basename($file))) {
                    return self::response(403, 'Forbidden');
                }
            }
        }

        if (is_file($file)) {
            return self::response(200, (string) file_get_contents($file));
        }
        return self::response(404, 'Not Found');
    }

    /**
     * Does any <FilesMatch>/<Files> container in $rules cover $basename?
     * Every container in these tests denies, so matching the container is enough.
     */
    private function denies($rules, $basename) {
        if (preg_match_all('/^\s*<FilesMatch\s+"(.+)">\s*$/m', $rules, $matches)) {
            foreach ($matches[1] as $pattern) {
                if (preg_match('~' . $pattern . '~', $basename)) {
                    return true;
                }
            }
        }
        if (preg_match_all('/^\s*<Files\s+"(.+)">\s*$/m', $rules, $matches)) {
            foreach ($matches[1] as $name) {
                if ($name === $basename) {
                    return true;
                }
            }
        }
        return false;
    }
}
```

- [ ] **Step 10: Create `W/tests/bootstrap.php`**

```php
<?php
/**
 * PHPUnit bootstrap for the Landeseiten Maintenance plugin.
 *
 * Plain PHPUnit, no WordPress: the handful of WordPress functions the tested
 * classes call are replaced by in-memory fakes in stubs/wp-functions.php.
 */

// Fixed fake WordPress root. Tests that need real files create them under here
// or (for LSM_Hardening) under per-test temp dirs handed in through seams.
$lsm_test_root = rtrim(sys_get_temp_dir(), '/\\') . '/lsm-wp-tests/';
if (!is_dir($lsm_test_root . 'wp-content/uploads')) {
    mkdir($lsm_test_root . 'wp-content/uploads', 0777, true);
}

// The class files exit silently without ABSPATH.
define('ABSPATH', $lsm_test_root);
define('WP_CONTENT_DIR', $lsm_test_root . 'wp-content');
define('DAY_IN_SECONDS', 86400);

define('LSM_VERSION', '0.0.0-test');
define('LSM_PLUGIN_DIR', dirname(__DIR__) . '/landeseiten-maintenance/');
define('LSM_PLUGIN_URL', 'http://example.test/wp-content/plugins/landeseiten-maintenance/');

require_once __DIR__ . '/stubs/wp-functions.php';
require_once __DIR__ . '/FakeServer.php';
```

- [ ] **Step 11: Run the tests**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && vendor/bin/phpunit -c phpunit.xml.dist
```

Expected: PASS — `OK (10 tests, 47 assertions)`.

- [ ] **Step 12: Commit**

```bash
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening add .gitignore composer.json composer.lock phpunit.xml.dist tests
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening commit -m "$(cat <<'EOF'
test(harness): add PHPUnit 9.6 harness with WordPress fakes and a fake Apache

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
EOF
)"
```

(With the zero-install fallback there is no `composer.lock`; leave it out of the `git add`.)

---

### Task 2: Class skeleton, rule definitions and block builder

**Files:**
- Create: `landeseiten-maintenance/includes/class-lsm-hardening.php`, `tests/TestableHardening.php`, `tests/HardeningTestCase.php`
- Modify: `tests/bootstrap.php` (append 4 lines at the end)
- Test: `tests/HardeningBlockTest.php`

**Interfaces:**
- Consumes: Task 1 harness.
- Produces:
  - Constants on `LSM_Hardening`: `OPTION = 'lsm_hardening'`, `LOCK_OPTION = 'lsm_hardening_lock'`, `LOCK_TTL = 180`, `RECOVERY_AFTER = 180`, `RESUME_THROTTLE = 300`, `LOOPBACK_TIMEOUT = 5`, `MARKER_BEGIN`, `MARKER_END`, `SNAPSHOT_FILE = '.htaccess.lsm-bak'`, `PROBE_PREFIX = 'lsm-probe-'`, `RULES = ['block_archives', 'block_debug_log', 'block_uploads_php']`, `PAUSE_MINUTES = [15, 30, 60]`.
  - `public static function instance()` → `LSM_Hardening`; `public static function set_instance($instance)` (tests only, `null` resets).
  - Seams (all `protected`): `http_request($url, $args)` → `array|WP_Error`, `content_dir()` → string, `uploads_dir()` → string, `now()` → int, `server_software()` → string.
  - `public function target_of($rule)` → `'content'|'uploads'`; `public function rules_of($target)` → string[]; `public function rule_lines($rule)` → string[9] (index 0 = opening line, 8 = closing tag); `public function build_block($target, array $enabled)` → string without trailing newline, `''` when no rule of that target is enabled; `private static function definitions()` → `[rule => ['target', 'open', 'close']]`.
  - Test doubles: `LSM_Testable_Hardening` with public `$content`, `$uploads`, `$plugin`, `$time` (starts at `1790000000`), `$software` (starts as `'Apache/2.4.57 (Unix)'`), `$cli`, `$finished`, `$put_hook` (`callable($file, $content)`: return `null` to write normally, anything else is returned instead of writing). `HardeningTestCase` with `$this->h`, `$this->server`, `$this->root`, `$this->content`, `$this->uploads` and helpers `htaccess($target)`, `put($target, $content)`, `get($target)` (null when absent), `artifacts()`, `put_an_edge_cache_in_front()` → `ArrayObject` (replaces `LSM_Test_Env::$http` with a CDN edge in front of the fake server that caches every 200 for a `.zip` path, keyed by the full URL incl. query string; Tasks 8 and 9 use it).

- [ ] **Step 1: Write the failing test** — `W/tests/HardeningBlockTest.php`:

```php
<?php

/**
 * Rule definitions and the generated block.
 */
class HardeningBlockTest extends HardeningTestCase {

    /**
     * The regex inside a rule's <FilesMatch "..."> line, as a PHP pattern.
     */
    private function pattern($rule) {
        $open = $this->h->rule_lines($rule)[0];
        $this->assertSame(1, preg_match('/^<FilesMatch "(.+)">$/', $open, $m), $open);
        return '~' . $m[1] . '~';
    }

    public function test_rule_keys_and_targets() {
        $this->assertSame(['block_archives', 'block_debug_log', 'block_uploads_php'], LSM_Hardening::RULES);
        $this->assertSame('content', $this->h->target_of('block_archives'));
        $this->assertSame('content', $this->h->target_of('block_debug_log'));
        $this->assertSame('uploads', $this->h->target_of('block_uploads_php'));
        $this->assertSame(['block_archives', 'block_debug_log'], $this->h->rules_of('content'));
        $this->assertSame(['block_uploads_php'], $this->h->rules_of('uploads'));
    }

    public function test_opening_lines_are_the_spec_patterns() {
        $this->assertSame(
            '<FilesMatch "(?i)\.((wpress|sql|zip|tar|tgz|bak)|(sql|tar|bak|wpress|zip)\.gz)$">',
            $this->h->rule_lines('block_archives')[0]
        );
        $this->assertSame('<Files "debug.log">', $this->h->rule_lines('block_debug_log')[0]);
        $this->assertSame(
            '<FilesMatch "(?i)\.(php[0-9]?|phtml?|pht|phps|phar)(\.|$)">',
            $this->h->rule_lines('block_uploads_php')[0]
        );
    }

    public function test_rule_body_denies_in_both_syntaxes_and_never_grants() {
        foreach (LSM_Hardening::RULES as $rule) {
            $lines = $this->h->rule_lines($rule);
            $this->assertSame([
                '  <IfModule mod_authz_core.c>',
                '    Require all denied',
                '  </IfModule>',
                '  <IfModule !mod_authz_core.c>',
                '    Order deny,allow',
                '    Deny from all',
                '  </IfModule>',
            ], array_slice($lines, 1, 7), $rule);
            $this->assertStringNotContainsStringIgnoringCase('granted', implode("\n", $lines));
            $this->assertStringNotContainsStringIgnoringCase('allow from', implode("\n", $lines));
        }
        $this->assertSame('</FilesMatch>', $this->h->rule_lines('block_archives')[8]);
        $this->assertSame('</Files>', $this->h->rule_lines('block_debug_log')[8]);
        $this->assertSame('</FilesMatch>', $this->h->rule_lines('block_uploads_php')[8]);
    }

    public function test_archive_pattern_blocks_archives_but_not_bare_gz() {
        $pattern = $this->pattern('block_archives');
        foreach (['site.wpress', 'dump.sql', 'backup.ZIP', 'x.tar', 'x.tgz', 'wp-config.php.bak', 'db.sql.gz', 'site.tar.gz', 'a.bak.gz', 'b.wpress.gz', 'c.zip.GZ'] as $name) {
            $this->assertSame(1, preg_match($pattern, $name), $name . ' must be blocked');
        }
        // WP Super Cache and precompressed assets live under wp-content as *.html.gz / *.css.gz.
        foreach (['index-https.html.gz', 'style.css.gz', 'app.js.gz', 'plain.gz', 'photo.jpg', 'zip.txt', 'notes.sqlite'] as $name) {
            $this->assertSame(0, preg_match($pattern, $name), $name . ' must stay reachable');
        }
    }

    public function test_uploads_pattern_blocks_php_variants_and_double_extensions() {
        $pattern = $this->pattern('block_uploads_php');
        foreach (['shell.php', 'SHELL.PHP', 'x.pHp', 'x.php5', 'x.php8', 'x.phtml', 'x.pht', 'x.phps', 'x.phar', 'x.php.jpg', 'x.phtml.png'] as $name) {
            $this->assertSame(1, preg_match($pattern, $name), $name . ' must be blocked');
        }
        foreach (['photo.jpg', 'document.pdf', 'php.txt', 'x.phpx', 'graph.png'] as $name) {
            $this->assertSame(0, preg_match($pattern, $name), $name . ' must stay reachable');
        }
    }

    public function test_block_for_content_holds_archives_then_debug_log() {
        $block = $this->h->build_block('content', ['block_debug_log', 'block_archives', 'block_uploads_php']);
        $lines = explode("\n", $block);

        $this->assertSame('# BEGIN LSM-HARDENING', $lines[0]);
        $this->assertSame('#', $lines[1][0]);
        $this->assertSame('# END LSM-HARDENING', $lines[count($lines) - 1]);
        $this->assertSame(
            array_merge($this->h->rule_lines('block_archives'), $this->h->rule_lines('block_debug_log')),
            array_slice($lines, 2, -1)
        );
        $this->assertStringNotContainsString('php[0-9]', $block);
        $this->assertSame($block, rtrim($block, "\n"), 'no trailing newline');
    }

    public function test_block_for_uploads_holds_only_the_php_rule() {
        $lines = explode("\n", $this->h->build_block('uploads', LSM_Hardening::RULES));
        $this->assertSame($this->h->rule_lines('block_uploads_php'), array_slice($lines, 2, -1));
    }

    public function test_no_enabled_rule_means_no_block_at_all() {
        $this->assertSame('', $this->h->build_block('content', []));
        $this->assertSame('', $this->h->build_block('content', ['block_uploads_php']));
        $this->assertSame('', $this->h->build_block('uploads', ['block_archives', 'block_debug_log']));
    }

    public function test_instance_is_shared_and_replaceable() {
        $this->assertSame($this->h, LSM_Hardening::instance());
        LSM_Hardening::set_instance(null);
        $this->assertInstanceOf(LSM_Hardening::class, LSM_Hardening::instance());
        $this->assertNotSame($this->h, LSM_Hardening::instance());
    }
}
```

- [ ] **Step 2: Create the test doubles** — `W/tests/TestableHardening.php`:

```php
<?php
/**
 * LSM_Hardening with its seams pointed at temp directories and a fixed clock.
 *
 * HTTP is not overridden here: http_request() calls the wp_remote_get() fake,
 * which hands the request to LSM_Test_Env::$http (the fake server).
 *
 * Some overrides below belong to seams that later parts of the class add
 * (put_contents, plugin_dir, is_cli, finish_request). PHP does not mind an
 * override whose parent method does not exist yet.
 */
class LSM_Testable_Hardening extends LSM_Hardening {

    /** @var string */
    public $content = '';

    /** @var string */
    public $uploads = '';

    /** @var string */
    public $plugin = '';

    /** @var int */
    public $time = 1790000000;

    /** @var string */
    public $software = 'Apache/2.4.57 (Unix)';

    /** @var bool */
    public $cli = false;

    /** @var int number of finish_request() calls */
    public $finished = 0;

    /** @var callable|null function($file, $content): return null to write normally, anything else is returned instead of writing */
    public $put_hook = null;

    protected function content_dir() {
        return $this->content;
    }

    protected function uploads_dir() {
        return $this->uploads;
    }

    protected function plugin_dir() {
        return $this->plugin;
    }

    protected function now() {
        return $this->time;
    }

    protected function server_software() {
        return $this->software;
    }

    protected function is_cli() {
        return $this->cli;
    }

    protected function finish_request() {
        $this->finished++;
    }

    protected function put_contents($file, $content) {
        if ($this->put_hook !== null) {
            $result = call_user_func($this->put_hook, $file, $content);
            if ($result !== null) {
                return $result;
            }
        }
        return parent::put_contents($file, $content);
    }
}
```

and `W/tests/HardeningTestCase.php`:

```php
<?php

use PHPUnit\Framework\TestCase;

/**
 * Base class for the LSM_Hardening tests: a fresh temp wp-content per test,
 * a testable engine pointed at it and the fake server as canned HTTP.
 */
abstract class HardeningTestCase extends TestCase {

    /** @var string */
    protected $root;

    /** @var string */
    protected $content;

    /** @var string */
    protected $uploads;

    /** @var LSM_Testable_Hardening */
    protected $h;

    /** @var LSM_Fake_Server */
    protected $server;

    protected function setUp(): void {
        LSM_Test_Env::reset();
        unset($_SERVER['LSWS_EDITION'], $_SERVER['REQUEST_URI'], $_GET['rest_route']);

        $this->root    = rtrim(sys_get_temp_dir(), '/\\') . '/lsm-hardening-' . bin2hex(random_bytes(6));
        $this->content = $this->root . '/wp-content';
        $this->uploads = $this->content . '/uploads';
        $plugin        = $this->content . '/plugins/landeseiten-maintenance/';

        mkdir($this->uploads, 0777, true);
        mkdir($plugin . 'assets/css', 0777, true);
        file_put_contents($plugin . 'assets/css/ticket-ui.css', '.lsm-ticket{display:block}');

        $this->h          = new LSM_Testable_Hardening();
        $this->h->content = $this->content;
        $this->h->uploads = $this->uploads;
        $this->h->plugin  = $plugin;
        LSM_Hardening::set_instance($this->h);

        $this->server       = new LSM_Fake_Server($this->content, $this->uploads);
        LSM_Test_Env::$http = [$this->server, 'handle'];
    }

    protected function tearDown(): void {
        LSM_Hardening::set_instance(null);
        $this->remove($this->root);
    }

    /**
     * Path of a target's .htaccess.
     */
    protected function htaccess($target) {
        return ($target === 'uploads' ? $this->uploads : $this->content) . '/.htaccess';
    }

    /**
     * Write a target's .htaccess directly (test setup).
     */
    protected function put($target, $content) {
        file_put_contents($this->htaccess($target), $content);
    }

    /**
     * Read a target's .htaccess, or null when it does not exist.
     */
    protected function get($target) {
        return is_file($this->htaccess($target)) ? file_get_contents($this->htaccess($target)) : null;
    }

    /**
     * Names of leftover probe and snapshot files in both directories.
     */
    protected function artifacts() {
        $found = [];
        foreach ([$this->content, $this->uploads] as $dir) {
            foreach (scandir($dir) as $name) {
                if (LSM_Hardening::is_own_artifact($name)) {
                    $found[] = $name;
                }
            }
        }
        return $found;
    }

    /**
     * Put a CDN edge in front of the fake server. Like Cloudflare at its default "Standard"
     * cache level it caches every 200 for a .zip by extension, keyed by URL + query string,
     * and ignores the request's "Cache-Control: no-cache". Entries never expire within a
     * test (the real edge keeps a 200 for about two hours).
     *
     * @return ArrayObject The edge cache: full URL => cached response.
     */
    protected function put_an_edge_cache_in_front() {
        $server = $this->server;
        $edge   = new ArrayObject();

        LSM_Test_Env::$http = function ($url, $args) use ($server, $edge) {
            $is_zip = (bool) preg_match('~\.zip$~', (string) parse_url($url, PHP_URL_PATH));
            if ($is_zip && isset($edge[$url])) {
                return $edge[$url]; // HIT: the origin and its .htaccess are never asked
            }
            $response = $server->handle($url, $args);
            if ($is_zip && !is_wp_error($response) && $response['response']['code'] === 200) {
                $edge[$url] = $response;
            }
            return $response;
        };

        return $edge;
    }

    private function remove($path) {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        @chmod($path, 0777);
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $name) {
                if ($name !== '.' && $name !== '..') {
                    $this->remove($path . '/' . $name);
                }
            }
            rmdir($path);
            return;
        }
        unlink($path);
    }
}
```

- [ ] **Step 3: Load them from the bootstrap** — append to the end of `W/tests/bootstrap.php`, after one blank line:

```php
// Classes under test and their test doubles.
require_once LSM_PLUGIN_DIR . 'includes/class-lsm-hardening.php';
require_once __DIR__ . '/TestableHardening.php';
require_once __DIR__ . '/HardeningTestCase.php';
```

- [ ] **Step 4: Run and watch it fail**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && vendor/bin/phpunit -c phpunit.xml.dist
```

Expected: FAIL — `Warning: require_once(…/landeseiten-maintenance/includes/class-lsm-hardening.php): Failed to open stream: No such file or directory`, then `Error in bootstrap script`.

- [ ] **Step 5: Create `W/landeseiten-maintenance/includes/class-lsm-hardening.php`** (the file ends with the class's closing `}` — later tasks insert above that line):

```php
<?php
/**
 * Managed .htaccess hardening for Landeseiten Maintenance.
 *
 * Three deny rules, written between "# BEGIN LSM-HARDENING" / "# END LSM-HARDENING"
 * markers in wp-content/.htaccess and uploads/.htaccess. Every platform-initiated
 * write is snapshotted, self-tested over loopback HTTP and rolled back on failure.
 * Nothing here ever touches the root .htaccess.
 *
 * @package Landeseiten_Maintenance
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * LSM Hardening class.
 */
class LSM_Hardening {

    /**
     * Option holding the desired state, pause, pending operation and last result.
     */
    const OPTION = 'lsm_hardening';

    /**
     * Lock option (one row, taken with INSERT IGNORE) and the age after which it is stale.
     */
    const LOCK_OPTION = 'lsm_hardening_lock';
    const LOCK_TTL    = 180;

    /**
     * A pending operation older than this was killed mid-run.
     */
    const RECOVERY_AFTER = 180;

    /**
     * Minimum seconds between two auto-resume attempts.
     */
    const RESUME_THROTTLE = 300;

    /**
     * Timeout of every loopback request, in seconds.
     */
    const LOOPBACK_TIMEOUT = 5;

    /**
     * Block markers, snapshot file name and probe file prefix.
     */
    const MARKER_BEGIN  = '# BEGIN LSM-HARDENING';
    const MARKER_END    = '# END LSM-HARDENING';
    const SNAPSHOT_FILE = '.htaccess.lsm-bak';
    const PROBE_PREFIX  = 'lsm-probe-';

    /**
     * Rule keys, in the order they are written into a block.
     */
    const RULES = ['block_archives', 'block_debug_log', 'block_uploads_php'];

    /**
     * Allowed pause durations in minutes.
     */
    const PAUSE_MINUTES = [15, 30, 60];

    /**
     * Instance.
     *
     * @var LSM_Hardening|null
     */
    private static $instance = null;

    /**
     * Get the shared instance.
     *
     * @return LSM_Hardening
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Replace the shared instance (tests only).
     *
     * @param LSM_Hardening|null $instance Instance, or null to reset.
     */
    public static function set_instance($instance) {
        self::$instance = $instance;
    }

    // =========================================================================
    // SEAMS (overridden by the unit tests)
    // =========================================================================

    /**
     * Perform one HTTP GET.
     *
     * @param string $url  URL.
     * @param array  $args wp_remote_get() arguments.
     * @return array|WP_Error
     */
    protected function http_request($url, $args) {
        return wp_remote_get($url, $args);
    }

    /**
     * Absolute path of wp-content, no trailing slash.
     *
     * @return string
     */
    protected function content_dir() {
        return rtrim(WP_CONTENT_DIR, '/\\');
    }

    /**
     * Absolute path of the uploads base directory, no trailing slash.
     *
     * @return string
     */
    protected function uploads_dir() {
        $upload_dir = wp_upload_dir();
        return rtrim($upload_dir['basedir'], '/\\');
    }

    /**
     * Current unix time.
     *
     * @return int
     */
    protected function now() {
        return time();
    }

    /**
     * Raw SERVER_SOFTWARE string ('' under WP-CLI).
     *
     * @return string
     */
    protected function server_software() {
        return isset($_SERVER['SERVER_SOFTWARE']) ? (string) $_SERVER['SERVER_SOFTWARE'] : '';
    }

    // =========================================================================
    // RULES AND BLOCK BUILDER
    // =========================================================================

    /**
     * Rule definitions: target file plus the opening and closing container line.
     *
     * @return array
     */
    private static function definitions() {
        return [
            'block_archives' => [
                'target' => 'content',
                'open'   => '<FilesMatch "(?i)\.((wpress|sql|zip|tar|tgz|bak)|(sql|tar|bak|wpress|zip)\.gz)$">',
                'close'  => '</FilesMatch>',
            ],
            'block_debug_log' => [
                'target' => 'content',
                'open'   => '<Files "debug.log">',
                'close'  => '</Files>',
            ],
            'block_uploads_php' => [
                'target' => 'uploads',
                'open'   => '<FilesMatch "(?i)\.(php[0-9]?|phtml?|pht|phps|phar)(\.|$)">',
                'close'  => '</FilesMatch>',
            ],
        ];
    }

    /**
     * Which file a rule lives in.
     *
     * @param string $rule Rule key.
     * @return string 'content' or 'uploads'.
     */
    public function target_of($rule) {
        return self::definitions()[$rule]['target'];
    }

    /**
     * Rule keys that live in a target file, in block order.
     *
     * @param string $target 'content' or 'uploads'.
     * @return array
     */
    public function rules_of($target) {
        $rules = [];
        foreach (self::RULES as $rule) {
            if ($this->target_of($rule) === $target) {
                $rules[] = $rule;
            }
        }
        return $rules;
    }

    /**
     * The lines of one rule. Both branches deny: LiteSpeed Enterprise ignores
     * <IfModule> tests and executes both, so neither may ever grant.
     *
     * @param string $rule Rule key.
     * @return array
     */
    public function rule_lines($rule) {
        $definition = self::definitions()[$rule];

        return [
            $definition['open'],
            '  <IfModule mod_authz_core.c>',
            '    Require all denied',
            '  </IfModule>',
            '  <IfModule !mod_authz_core.c>',
            '    Order deny,allow',
            '    Deny from all',
            '  </IfModule>',
            $definition['close'],
        ];
    }

    /**
     * Build the whole managed block for one target file.
     *
     * @param string $target  'content' or 'uploads'.
     * @param array  $enabled Rule keys that must be in the block; keys of other targets are ignored.
     * @return string Block without a trailing newline, or '' when no rule of this target is enabled.
     */
    public function build_block($target, array $enabled) {
        $lines = [];
        foreach ($this->rules_of($target) as $rule) {
            if (in_array($rule, $enabled, true)) {
                $lines = array_merge($lines, $this->rule_lines($rule));
            }
        }

        if (empty($lines)) {
            return '';
        }

        return implode("\n", array_merge(
            [self::MARKER_BEGIN, '# Managed by the Landeseiten Maintenance plugin. Do not edit between these markers.'],
            $lines,
            [self::MARKER_END]
        ));
    }
}
```

- [ ] **Step 6: Lint and run**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && php -l landeseiten-maintenance/includes/class-lsm-hardening.php && vendor/bin/phpunit -c phpunit.xml.dist
```

Expected: `No syntax errors detected`, then PASS — `OK (19 tests, 117 assertions)`.

- [ ] **Step 7: Commit**

```bash
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening add landeseiten-maintenance/includes/class-lsm-hardening.php tests
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening commit -m "$(cat <<'EOF'
feat(hardening): add LSM_Hardening skeleton with rule definitions and block builder

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Strict marker parser and writer

`insert_with_markers()` cannot remove a block, writes locale-dependent comment lines, returns `true` on a short write and swallows everything after a BEGIN without END. This task is the engine's own small strict writer.

**Files:**
- Modify: `landeseiten-maintenance/includes/class-lsm-hardening.php` (insert above the final `}`)
- Test: `tests/HardeningWriterTest.php`

**Interfaces:**
- Consumes: `build_block($target, array $enabled)`, `content_dir()`, `uploads_dir()`, constants `MARKER_BEGIN` / `MARKER_END` (Task 2); `$this->h->put_hook`, `put()`, `get()`, `htaccess()` (test doubles, Task 2).
- Produces:
  - `public function target_file($target)` → absolute path of that target's `.htaccess`.
  - `public function read_target($target)` → `['existed' => bool, 'content' => string]` (`''` when absent). A file that exists but cannot be read comes back as `['existed' => true, 'content' => '', 'unreadable' => true]` — the extra key is only present in that case, and whoever sees it must not write (Tasks 5 and 12 check it): treating such a file as empty would make a write destroy it.
  - `public function parse_markers($content)` → `['corrupt' => bool, 'found' => bool, 'start' => int, 'end' => int, 'lines' => string[]]` — `start`/`end` = byte range from the BEGIN line to the end of the END line (without its line break), `lines` = body lines between the markers.
  - `public function replace_block($content, $block)` → new content; `null` when the markers are corrupt; `$block === ''` removes block and markers.
  - `protected function put_contents($file, $content)` → `int|false` (seam; `file_put_contents(..., LOCK_EX)`).
  - `public function commit_target($target, $content, $original, $existed)` → bool: writes, reads back, compares sha1; unchanged content writes nothing; a file that did not exist before and would be left empty is deleted. Does **not** restore on failure.
  - `public function restore_target($target, $original, $existed)` → bool: original bytes back (or file deleted when it did not exist), verified by read-back.
- Literal reading of the spec you must keep: a file is only deleted when it did **not exist before this operation** (`$existed === false`). Turning the last rule off on a file an earlier operation created leaves an empty `.htaccess` — harmless, and the state has no field that could remember "we created it".

- [ ] **Step 1: Write the failing test** — `W/tests/HardeningWriterTest.php`:

```php
<?php

/**
 * Strict marker parser and writer.
 */
class HardeningWriterTest extends HardeningTestCase {

    /** Foreign content as found on a real site: no LSM markers, WebP Express block, CRLF-free. */
    const FOREIGN = "# BEGIN WebP Express\n<IfModule mod_mime.c>\n  AddType image/webp .webp\n</IfModule>\n# END WebP Express\n";

    private function block() {
        return $this->h->build_block('content', ['block_archives']);
    }

    public function test_parse_absent_markers() {
        $parsed = $this->h->parse_markers(self::FOREIGN);
        $this->assertFalse($parsed['corrupt']);
        $this->assertFalse($parsed['found']);
        $this->assertSame([], $parsed['lines']);
    }

    public function test_parse_present_markers_returns_the_body_lines() {
        $parsed = $this->h->parse_markers(self::FOREIGN . "\n" . $this->block() . "\nOptions -Indexes\n");
        $this->assertFalse($parsed['corrupt']);
        $this->assertTrue($parsed['found']);
        $this->assertSame('<FilesMatch "(?i)\.((wpress|sql|zip|tar|tgz|bak)|(sql|tar|bak|wpress|zip)\.gz)$">', $parsed['lines'][1]);
        $this->assertSame('</FilesMatch>', $parsed['lines'][count($parsed['lines']) - 1]);
    }

    public function test_parse_tolerates_crlf_and_indented_markers() {
        $parsed = $this->h->parse_markers("Options -Indexes\r\n  # BEGIN LSM-HARDENING\r\n<Files \"debug.log\">\r\n</Files>\r\n# END LSM-HARDENING  \r\n");
        $this->assertTrue($parsed['found']);
        $this->assertSame(['<Files "debug.log">', '</Files>'], $parsed['lines']);
    }

    public function test_corrupt_markers_are_detected_and_never_rewritten() {
        $corrupt = [
            'begin without end'  => "# BEGIN LSM-HARDENING\n<Files \"debug.log\">\n</Files>\n",
            'end without begin'  => "<Files \"debug.log\">\n</Files>\n# END LSM-HARDENING\n",
            'end before begin'   => "# END LSM-HARDENING\n# BEGIN LSM-HARDENING\n",
            'two blocks'         => "# BEGIN LSM-HARDENING\n# END LSM-HARDENING\n# BEGIN LSM-HARDENING\n# END LSM-HARDENING\n",
            'two begins one end' => "# BEGIN LSM-HARDENING\n# BEGIN LSM-HARDENING\n# END LSM-HARDENING\n",
            'one begin two ends' => "# BEGIN LSM-HARDENING\n# END LSM-HARDENING\n# END LSM-HARDENING\n",
        ];

        foreach ($corrupt as $case => $content) {
            $this->assertTrue($this->h->parse_markers($content)['corrupt'], $case);
            $this->assertNull($this->h->replace_block($content, $this->block()), $case);
            $this->assertNull($this->h->replace_block($content, ''), $case);
        }
    }

    public function test_a_marker_that_is_only_part_of_a_line_is_not_a_marker() {
        $content = "# BEGIN LSM-HARDENING-OLD\n# see # END LSM-HARDENING in the docs\n";
        $parsed  = $this->h->parse_markers($content);
        $this->assertFalse($parsed['corrupt']);
        $this->assertFalse($parsed['found']);
    }

    public function test_append_to_empty_content() {
        $this->assertSame($this->block() . "\n", $this->h->replace_block('', $this->block()));
    }

    public function test_append_puts_a_blank_line_before_the_block() {
        $this->assertSame(self::FOREIGN . "\n" . $this->block() . "\n", $this->h->replace_block(self::FOREIGN, $this->block()));
    }

    public function test_append_never_fuses_onto_a_last_line_without_newline() {
        // wirbewegen.schule: "# END WebP Express<FilesMatch ...>" took every upload down with a 500.
        $content = rtrim(self::FOREIGN, "\n");
        $this->assertSame($content . "\n\n" . $this->block() . "\n", $this->h->replace_block($content, $this->block()));
    }

    public function test_replace_in_place_preserves_outside_bytes() {
        $before  = "# top\r\nOptions -Indexes\r\n\n";
        $after   = "\n\n<IfModule litespeed>\nphp_value \n</IfModule>\n\t# tail without newline";
        $content = $before . $this->block() . $after;

        $new_block = $this->h->build_block('content', ['block_archives', 'block_debug_log']);
        $result    = $this->h->replace_block($content, $new_block);

        $this->assertSame($before . $new_block . $after, $result);
    }

    public function test_enable_then_remove_gives_back_the_original_bytes() {
        $with = $this->h->replace_block(self::FOREIGN, $this->block());
        $this->assertSame(self::FOREIGN, $this->h->replace_block($with, ''));
    }

    public function test_removal_in_the_middle_keeps_both_sides() {
        $content = "Options -Indexes\n" . $this->block() . "\nErrorDocument 404 /404.html\n";
        $this->assertSame("Options -Indexes\nErrorDocument 404 /404.html\n", $this->h->replace_block($content, ''));
    }

    public function test_removal_without_a_block_changes_nothing() {
        $this->assertSame(self::FOREIGN, $this->h->replace_block(self::FOREIGN, ''));
    }

    public function test_read_target_reports_absent_and_present_files() {
        $this->assertSame(['existed' => false, 'content' => ''], $this->h->read_target('uploads'));
        $this->put('uploads', self::FOREIGN);
        $this->assertSame(['existed' => true, 'content' => self::FOREIGN], $this->h->read_target('uploads'));
        $this->assertSame($this->uploads . '/.htaccess', $this->h->target_file('uploads'));
        $this->assertSame($this->content . '/.htaccess', $this->h->target_file('content'));
    }

    public function test_read_target_never_reports_an_unreadable_file_as_empty() {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Running as root: every file is readable.');
        }
        $this->put('content', self::FOREIGN);
        // Writable but not readable: is_writable() is true, file_get_contents() fails.
        chmod($this->htaccess('content'), 0200);

        $this->assertSame(['existed' => true, 'content' => '', 'unreadable' => true], $this->h->read_target('content'));
    }

    public function test_commit_creates_the_file_and_reads_it_back() {
        $content = $this->block() . "\n";
        $this->assertTrue($this->h->commit_target('content', $content, '', false));
        $this->assertSame($content, $this->get('content'));
    }

    public function test_commit_deletes_a_file_it_created_once_it_is_empty() {
        $this->put('content', $this->block() . "\n");
        $this->assertTrue($this->h->commit_target('content', '', $this->block() . "\n", false));
        $this->assertFileDoesNotExist($this->htaccess('content'));
    }

    public function test_commit_keeps_an_emptied_file_that_existed_before() {
        $this->put('content', $this->block() . "\n");
        $this->assertTrue($this->h->commit_target('content', '', $this->block() . "\n", true));
        $this->assertSame('', $this->get('content'));
    }

    public function test_commit_with_unchanged_content_writes_nothing() {
        $writes = 0;
        $this->h->put_hook = function () use (&$writes) {
            $writes++;
            return null;
        };
        $this->assertTrue($this->h->commit_target('content', '', '', false));
        $this->put('content', self::FOREIGN);
        $this->assertTrue($this->h->commit_target('content', self::FOREIGN, self::FOREIGN, true));
        $this->assertSame(0, $writes);
        $this->assertSame(self::FOREIGN, $this->get('content'));
    }

    public function test_commit_detects_a_short_write_by_sha1_and_restore_puts_the_bytes_back() {
        $this->put('content', self::FOREIGN);
        $new = $this->h->replace_block(self::FOREIGN, $this->block());

        // Quota exhausted mid-write: only the first 40 bytes reach the disk, once.
        $failed = false;
        $this->h->put_hook = function ($file, $content) use (&$failed) {
            if ($failed) {
                return null;
            }
            $failed = true;
            return file_put_contents($file, substr($content, 0, 40));
        };

        $this->assertFalse($this->h->commit_target('content', $new, self::FOREIGN, true));
        $this->assertNotSame(self::FOREIGN, $this->get('content'));

        $this->assertTrue($this->h->restore_target('content', self::FOREIGN, true));
        $this->assertSame(self::FOREIGN, $this->get('content'));
    }

    public function test_restore_deletes_a_file_that_did_not_exist_before() {
        $this->put('uploads', $this->h->build_block('uploads', ['block_uploads_php']) . "\n");
        $this->assertTrue($this->h->restore_target('uploads', '', false));
        $this->assertFileDoesNotExist($this->htaccess('uploads'));
    }

    public function test_restore_reports_failure_when_the_bytes_do_not_come_back() {
        $this->put('content', 'garbage');
        $this->h->put_hook = function () {
            return false;
        };
        $this->assertFalse($this->h->restore_target('content', self::FOREIGN, true));
    }
}
```

- [ ] **Step 2: Run and watch it fail**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && vendor/bin/phpunit -c phpunit.xml.dist --filter HardeningWriterTest
```

Expected: FAIL — `Tests: 21, Assertions: 0, Errors: 21.`, each one `Error: Call to undefined method LSM_Testable_Hardening::parse_markers()` (or `replace_block`, `read_target`, `commit_target`, `restore_target`).

- [ ] **Step 3: Implement** — insert above the final `}` of `class LSM_Hardening`, preceded by one blank line:

```php
    // =========================================================================
    // STRICT FILE HANDLING
    // =========================================================================

    /**
     * Absolute path of a target's .htaccess.
     *
     * @param string $target 'content' or 'uploads'.
     * @return string
     */
    public function target_file($target) {
        return ($target === 'uploads' ? $this->uploads_dir() : $this->content_dir()) . '/.htaccess';
    }

    /**
     * Read a target file.
     *
     * @param string $target 'content' or 'uploads'.
     * @return array ['existed' => bool, 'content' => string], plus 'unreadable' => true when the
     *               file exists but cannot be read (content is '' then — and nobody may write).
     */
    public function read_target($target) {
        $file = $this->target_file($target);
        if (!is_file($file)) {
            return ['existed' => false, 'content' => ''];
        }
        $content = @file_get_contents($file);
        if ($content === false) {
            // Exists but cannot be read: never treat it as empty — a write would destroy it.
            return ['existed' => true, 'content' => '', 'unreadable' => true];
        }
        return ['existed' => true, 'content' => $content];
    }

    /**
     * Byte offsets of every line that consists of exactly one marker.
     *
     * @param string $content File content.
     * @param string $marker  Marker text.
     * @return array List of [offset, length].
     */
    private function marker_offsets($content, $marker) {
        $found = [];
        if (preg_match_all('/^[ \t]*' . preg_quote($marker, '/') . '[ \t]*\r?$/m', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $found[] = [$match[1], strlen($match[0])];
            }
        }
        return $found;
    }

    /**
     * Strict marker parser: exactly zero or one BEGIN, followed by its END,
     * no END without a BEGIN. Anything else is corrupt and must not be written to.
     *
     * @param string $content File content.
     * @return array ['corrupt' => bool, 'found' => bool, 'start' => int, 'end' => int, 'lines' => array]
     *               start/end are the byte range of the block (BEGIN line up to the end of the END line,
     *               without its line break); lines are the block's body lines.
     */
    public function parse_markers($content) {
        $result = ['corrupt' => false, 'found' => false, 'start' => 0, 'end' => 0, 'lines' => []];

        $begin = $this->marker_offsets($content, self::MARKER_BEGIN);
        $end   = $this->marker_offsets($content, self::MARKER_END);

        if (empty($begin) && empty($end)) {
            return $result;
        }

        if (count($begin) !== 1 || count($end) !== 1 || $end[0][0] < $begin[0][0]) {
            $result['corrupt'] = true;
            return $result;
        }

        $body_start = $begin[0][0] + $begin[0][1];
        $body       = substr($content, $body_start, $end[0][0] - $body_start);

        $result['found'] = true;
        $result['start'] = $begin[0][0];
        $result['end']   = $end[0][0] + $end[0][1];
        foreach (explode("\n", trim($body, "\r\n")) as $line) {
            $result['lines'][] = rtrim($line, "\r");
        }

        return $result;
    }

    /**
     * Put $block where the managed block is (or append it), or remove the managed
     * block when $block is ''. Everything outside the markers is kept byte for byte.
     *
     * @param string $content File content.
     * @param string $block   Block from build_block(), '' to remove.
     * @return string|null New content, or null when the markers are corrupt.
     */
    public function replace_block($content, $block) {
        $parsed = $this->parse_markers($content);
        if ($parsed['corrupt']) {
            return null;
        }

        if (!$parsed['found']) {
            if ($block === '') {
                return $content;
            }
            if ($content === '') {
                return $block . "\n";
            }
            // A file without a trailing newline would fuse its last line onto our marker.
            $separator = substr($content, -1) === "\n" ? "\n" : "\n\n";
            return $content . $separator . $block . "\n";
        }

        $before = substr($content, 0, $parsed['start']);
        $after  = (string) substr($content, $parsed['end']);

        if ($block !== '') {
            return $before . $block . $after;
        }

        // Removal: take the END line's line break and the blank line we put in front with it.
        if (substr($after, 0, 2) === "\r\n") {
            $after = (string) substr($after, 2);
        } elseif (substr($after, 0, 1) === "\n") {
            $after = (string) substr($after, 1);
        }
        if (substr($before, -2) === "\n\n") {
            $before = substr($before, 0, -1);
        }

        return $before . $after;
    }

    /**
     * Write a file. A seam so tests can simulate short or failing writes.
     *
     * @param string $file    Absolute path.
     * @param string $content Content.
     * @return int|false Bytes written.
     */
    protected function put_contents($file, $content) {
        return @file_put_contents($file, $content, LOCK_EX);
    }

    /**
     * Write new content into a target file and verify it by reading it back.
     * A file this operation would leave empty, and that did not exist before, is deleted.
     * Does not restore anything on failure — the caller still holds the original bytes.
     *
     * @param string $target   'content' or 'uploads'.
     * @param string $content  New full content.
     * @param string $original Content before the operation ('' when the file did not exist).
     * @param bool   $existed  Whether the file existed before the operation.
     * @return bool False when the read-back does not match.
     */
    public function commit_target($target, $content, $original, $existed) {
        $file = $this->target_file($target);

        if ($content === $original) {
            return true;
        }

        if (!$existed && trim($content) === '') {
            if (file_exists($file)) {
                @unlink($file);
            }
            return !file_exists($file);
        }

        $this->put_contents($file, $content);

        $written = @file_get_contents($file);
        return $written !== false && sha1($written) === sha1($content);
    }

    /**
     * Put a target file back to its original bytes (or delete it if it did not exist).
     *
     * @param string $target   'content' or 'uploads'.
     * @param string $original Original bytes held in memory.
     * @param bool   $existed  Whether the file existed before the operation.
     * @return bool True when the file is back to its original state.
     */
    public function restore_target($target, $original, $existed) {
        $file = $this->target_file($target);

        if (!$existed) {
            if (file_exists($file)) {
                @unlink($file);
            }
            return !file_exists($file);
        }

        $this->put_contents($file, $original);

        $written = @file_get_contents($file);
        return $written !== false && sha1($written) === sha1($original);
    }
```

- [ ] **Step 4: Lint and run the whole suite**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && php -l landeseiten-maintenance/includes/class-lsm-hardening.php && vendor/bin/phpunit -c phpunit.xml.dist
```

Expected: `No syntax errors detected`, then PASS — `OK (40 tests, 175 assertions)`. (As root — some containers — `test_read_target_never_reports_an_unreadable_file_as_empty` reports as skipped, because root can read everything; all quoted counts in this plan are for a non-root run.)

- [ ] **Step 5: Commit**

```bash
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening add landeseiten-maintenance/includes/class-lsm-hardening.php tests/HardeningWriterTest.php
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening commit -m "$(cat <<'EOF'
feat(hardening): add strict marker parser and sha1-verified writer

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Manual-block grammar (adoption matcher)

The security-audit procedure appended unmarked deny blocks by hand. They are recognised by a small grammar, not by exact text: a known opening line (after whitespace normalisation), body lines only from a fixed set, then the matching close tag. Two deliberate tightenings, both covered by near-miss tests: the body must contain at least one deny directive (`Require all denied` or `Deny from all`) — an empty or `Order`-only container is not a deny rule — and `<IfModule>` lines must balance. Blank lines inside the body are tolerated. Matching is case-sensitive, as the spec's "equals" says. Anything else is never touched.

The fixtures are the real blocks: `wp-audits/midnightblue-duck-937654.hostingersite.com_2026-09-07/scan_verify.txt` lines 319-327 and 333-335, `~/.claude/skills/wordpress-security-audit/audits/drjung.ch_2026-09-10/scan_verify.txt` lines 288-290, and the three blocks of the audit skill (`SKILL.md` lines 344-350 and 377-381).

**Files:**
- Create: `tests/Fixtures.php`
- Modify: `landeseiten-maintenance/includes/class-lsm-hardening.php` (insert above the final `}`), `tests/bootstrap.php` (append 1 line)
- Test: `tests/HardeningManualTest.php`

**Interfaces:**
- Consumes: `parse_markers($content)` (Task 3), `definitions()` (Task 2).
- Produces:
  - `public function find_manual_blocks($content, $rule)` → list of `['start' => int, 'length' => int]` byte ranges covering whole lines, outside our markers only.
  - `public function strip_manual_blocks($content, $rule)` → content without those ranges.
  - `private function normalize_line($line)` → trimmed, inner whitespace collapsed to one space (Task 5 reuses it).
  - `LSM_Htaccess_Fixtures::MIDNIGHTBLUE_CONTENT`, `::AUDITED_UPLOADS`, `::SKILL_CONTENT`, `::SKILL_UPLOADS` (string constants).

- [ ] **Step 1: Create the fixtures** — `W/tests/Fixtures.php` (copy exactly; line 2 of the first fixture ends in a space, which is why it is written with escapes):

```php
<?php
/**
 * Real .htaccess content from audited sites and from the audit procedure, used by
 * the adoption tests. Never "tidy" these strings: the matcher has to cope with
 * exactly what is on the servers.
 */
class LSM_Htaccess_Fixtures {

    /**
     * wp-content/.htaccess of midnightblue-duck-937654.hostingersite.com, byte for byte
     * (wp-audits/midnightblue-duck-..._2026-09-07/scan_verify.txt:319-327). Written with explicit
     * escapes because line 2 really ends in a space ("php_value ") that editors like to strip.
     */
    const MIDNIGHTBLUE_CONTENT = "<IfModule litespeed>\nphp_value \n</IfModule>\n"
        . "<Files \"debug.log\">\nRequire all denied\n</Files>\n"
        . "<FilesMatch \"\\.(wpress|sql|zip|tar|gz|bak)$\">\nRequire all denied\n</FilesMatch>\n";

    /**
     * uploads/.htaccess of midnightblue-duck and of drjung.ch — identical
     * (scan_verify.txt:333-335 and audits/drjung.ch_2026-09-10/scan_verify.txt:288-290).
     */
    const AUDITED_UPLOADS = <<<'HT'
<FilesMatch "\.php$">
Require all denied
</FilesMatch>

HT;

    /**
     * The three blocks exactly as the audit skill appends them (SKILL.md:344-350 and :377-381),
     * each after the blank line harden_htaccess() puts in front.
     */
    const SKILL_CONTENT = <<<'HT'
# BEGIN WebP Express
AddType image/webp .webp
# END WebP Express

<Files "debug.log">
Deny from all
</Files>

<FilesMatch "\.(wpress|sql|zip|tar|gz|bak)$">
  <IfModule mod_authz_core.c>
    Require all denied
  </IfModule>
</FilesMatch>

HT;

    const SKILL_UPLOADS = <<<'HT'

<FilesMatch "\.php$">
Deny from all
</FilesMatch>

HT;
}
```

and append to the end of `W/tests/bootstrap.php`:

```php
require_once __DIR__ . '/Fixtures.php';
```

- [ ] **Step 2: Write the failing test** — `W/tests/HardeningManualTest.php`:

```php
<?php

/**
 * Recognition and removal of the hand-written deny blocks the audit procedure left on sites.
 */
class HardeningManualTest extends HardeningTestCase {

    public function test_real_fixtures_are_recognised() {
        $this->assertCount(1, $this->h->find_manual_blocks(LSM_Htaccess_Fixtures::MIDNIGHTBLUE_CONTENT, 'block_archives'));
        $this->assertCount(1, $this->h->find_manual_blocks(LSM_Htaccess_Fixtures::MIDNIGHTBLUE_CONTENT, 'block_debug_log'));
        $this->assertCount(1, $this->h->find_manual_blocks(LSM_Htaccess_Fixtures::AUDITED_UPLOADS, 'block_uploads_php'));
        $this->assertCount(1, $this->h->find_manual_blocks(LSM_Htaccess_Fixtures::SKILL_CONTENT, 'block_archives'));
        $this->assertCount(1, $this->h->find_manual_blocks(LSM_Htaccess_Fixtures::SKILL_CONTENT, 'block_debug_log'));
        $this->assertCount(1, $this->h->find_manual_blocks(LSM_Htaccess_Fixtures::SKILL_UPLOADS, 'block_uploads_php'));
    }

    public function test_a_rule_is_only_recognised_by_its_own_opening_line() {
        $this->assertSame([], $this->h->find_manual_blocks(LSM_Htaccess_Fixtures::MIDNIGHTBLUE_CONTENT, 'block_uploads_php'));
        $this->assertSame([], $this->h->find_manual_blocks(LSM_Htaccess_Fixtures::AUDITED_UPLOADS, 'block_archives'));
        $this->assertSame([], $this->h->find_manual_blocks(LSM_Htaccess_Fixtures::AUDITED_UPLOADS, 'block_debug_log'));
    }

    public function test_block_range_covers_whole_lines() {
        $blocks = $this->h->find_manual_blocks(LSM_Htaccess_Fixtures::MIDNIGHTBLUE_CONTENT, 'block_debug_log');
        $this->assertSame(
            "<Files \"debug.log\">\nRequire all denied\n</Files>\n",
            substr(LSM_Htaccess_Fixtures::MIDNIGHTBLUE_CONTENT, $blocks[0]['start'], $blocks[0]['length'])
        );
    }

    public function test_whitespace_variants_and_the_dual_syntax_body_are_recognised() {
        $content = "\t<FilesMatch   \"\\.php$\">  \r\n"
            . "  <IfModule mod_authz_core.c>\r\n    Require all denied\r\n  </IfModule>\r\n"
            . "  <IfModule !mod_authz_core.c>\r\n    Order allow,deny\r\n    Deny   from  all\r\n  </IfModule>\r\n"
            . "\r\n"
            . "</FilesMatch>";
        $blocks = $this->h->find_manual_blocks($content, 'block_uploads_php');
        $this->assertCount(1, $blocks);
        $this->assertSame(strlen($content), $blocks[0]['length']);
    }

    public function test_stripping_removes_only_the_recognised_block() {
        $this->assertSame(
            "<IfModule litespeed>\nphp_value \n</IfModule>\n<Files \"debug.log\">\nRequire all denied\n</Files>\n",
            $this->h->strip_manual_blocks(LSM_Htaccess_Fixtures::MIDNIGHTBLUE_CONTENT, 'block_archives')
        );
        $this->assertSame(
            "<IfModule litespeed>\nphp_value \n</IfModule>\n<FilesMatch \"\\.(wpress|sql|zip|tar|gz|bak)$\">\nRequire all denied\n</FilesMatch>\n",
            $this->h->strip_manual_blocks(LSM_Htaccess_Fixtures::MIDNIGHTBLUE_CONTENT, 'block_debug_log')
        );
        $this->assertSame('', $this->h->strip_manual_blocks(LSM_Htaccess_Fixtures::AUDITED_UPLOADS, 'block_uploads_php'));
    }

    public function test_a_block_appended_twice_is_stripped_twice() {
        $content = LSM_Htaccess_Fixtures::AUDITED_UPLOADS . "Options -Indexes\n" . LSM_Htaccess_Fixtures::AUDITED_UPLOADS;
        $this->assertCount(2, $this->h->find_manual_blocks($content, 'block_uploads_php'));
        $this->assertSame("Options -Indexes\n", $this->h->strip_manual_blocks($content, 'block_uploads_php'));
    }

    public function test_near_misses_are_never_recognised() {
        $near_misses = [
            'other archive list'        => ['block_archives', "<FilesMatch \"\\.(wpress|sql|zip)$\">\nRequire all denied\n</FilesMatch>\n"],
            'our own managed pattern'   => ['block_archives', "<FilesMatch \"(?i)\\.((wpress|sql|zip|tar|tgz|bak)|(sql|tar|bak|wpress|zip)\\.gz)$\">\nRequire all denied\n</FilesMatch>\n"],
            'grants instead of denies'  => ['block_uploads_php', "<FilesMatch \"\\.php$\">\nRequire all granted\n</FilesMatch>\n"],
            'ip allow-list in the body' => ['block_uploads_php', "<FilesMatch \"\\.php$\">\nOrder deny,allow\nDeny from all\nAllow from 203.0.113.7\n</FilesMatch>\n"],
            'require ip in the body'    => ['block_debug_log', "<Files \"debug.log\">\nRequire ip 203.0.113.7\n</Files>\n"],
            'handler in the body'       => ['block_uploads_php', "<FilesMatch \"\\.php$\">\nSetHandler none\nDeny from all\n</FilesMatch>\n"],
            'comment in the body'       => ['block_debug_log', "<Files \"debug.log\">\n# keep\nDeny from all\n</Files>\n"],
            'no deny at all'            => ['block_debug_log', "<Files \"debug.log\">\nOrder allow,deny\n</Files>\n"],
            'empty body'                => ['block_debug_log', "<Files \"debug.log\">\n</Files>\n"],
            'never closed'              => ['block_debug_log', "<Files \"debug.log\">\nDeny from all\n"],
            'wrong close tag'           => ['block_uploads_php', "<FilesMatch \"\\.php$\">\nDeny from all\n</Files>\n"],
            'unbalanced IfModule'       => ['block_archives', "<FilesMatch \"\\.(wpress|sql|zip|tar|gz|bak)$\">\n<IfModule mod_authz_core.c>\nRequire all denied\n</FilesMatch>\n"],
            'stray IfModule close'      => ['block_archives', "<FilesMatch \"\\.(wpress|sql|zip|tar|gz|bak)$\">\n</IfModule>\nRequire all denied\n</FilesMatch>\n"],
            'commented out'             => ['block_debug_log', "# <Files \"debug.log\">\n# Deny from all\n# </Files>\n"],
            'unquoted file name'        => ['block_debug_log', "<Files debug.log>\nDeny from all\n</Files>\n"],
            'other file'                => ['block_debug_log', "<Files \"error.log\">\nDeny from all\n</Files>\n"],
            'uppercase directive'       => ['block_debug_log', "<FILES \"debug.log\">\nDeny from all\n</FILES>\n"],
        ];

        foreach ($near_misses as $case => $near_miss) {
            list($rule, $content) = $near_miss;
            $this->assertSame([], $this->h->find_manual_blocks($content, $rule), $case);
            $this->assertSame($content, $this->h->strip_manual_blocks($content, $rule), $case);
        }
    }

    public function test_a_foreign_line_resets_the_match_but_a_later_clean_block_still_counts() {
        $content = "<Files \"debug.log\">\nSatisfy any\n<Files \"debug.log\">\nDeny from all\n</Files>\n";
        $blocks  = $this->h->find_manual_blocks($content, 'block_debug_log');
        $this->assertCount(1, $blocks);
        $this->assertSame("<Files \"debug.log\">\nDeny from all\n</Files>\n", substr($content, $blocks[0]['start'], $blocks[0]['length']));
    }

    public function test_a_manual_looking_block_inside_our_markers_is_not_manual() {
        $content = "# BEGIN LSM-HARDENING\n<Files \"debug.log\">\nDeny from all\n</Files>\n# END LSM-HARDENING\n";
        $this->assertSame([], $this->h->find_manual_blocks($content, 'block_debug_log'));
        $this->assertSame($content, $this->h->strip_manual_blocks($content, 'block_debug_log'));
    }

    public function test_the_managed_block_itself_is_never_mistaken_for_a_manual_one() {
        $content = $this->h->build_block('content', ['block_archives', 'block_debug_log']) . "\n";
        $this->assertSame([], $this->h->find_manual_blocks($content, 'block_debug_log'));
        $this->assertSame([], $this->h->find_manual_blocks($content, 'block_archives'));
    }
}
```

- [ ] **Step 3: Run and watch it fail**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && vendor/bin/phpunit -c phpunit.xml.dist --filter HardeningManualTest
```

Expected: FAIL — `Tests: 10, Assertions: 0, Errors: 10.`, each one `Error: Call to undefined method LSM_Testable_Hardening::find_manual_blocks()` (or `strip_manual_blocks()`).

- [ ] **Step 4: Implement** — insert above the final `}` of `class LSM_Hardening`, preceded by one blank line:

```php
    // =========================================================================
    // MANUAL RULES ALREADY ON A SITE (ADOPTION)
    // =========================================================================

    /**
     * Opening lines of the hand-written blocks the security-audit procedure appends.
     *
     * @return array Rule key => opening line (whitespace-normalised).
     */
    private static function manual_openings() {
        return [
            'block_archives'    => '<FilesMatch "\.(wpress|sql|zip|tar|gz|bak)$">',
            'block_debug_log'   => '<Files "debug.log">',
            'block_uploads_php' => '<FilesMatch "\.php$">',
        ];
    }

    /**
     * Trim a line and collapse inner whitespace runs to one space.
     *
     * @param string $line Raw line.
     * @return string
     */
    private function normalize_line($line) {
        return trim(preg_replace('/\s+/', ' ', $line));
    }

    /**
     * Find hand-written deny blocks for a rule, outside our markers.
     *
     * A small grammar, not exact text: a known opening line, then only deny /
     * IfModule lines (at least one deny, IfModule balanced), then the matching
     * close tag. Anything else is somebody else's rule and is never touched.
     *
     * @param string $content File content.
     * @param string $rule    Rule key.
     * @return array List of ['start' => int, 'length' => int] byte ranges (whole lines).
     */
    public function find_manual_blocks($content, $rule) {
        $opening = self::manual_openings()[$rule];
        $close   = self::definitions()[$rule]['close'];
        $deny    = ['Require all denied', 'Deny from all'];
        $neutral = ['Order deny,allow', 'Order allow,deny'];
        $if_open = ['<IfModule mod_authz_core.c>', '<IfModule !mod_authz_core.c>'];

        $managed = $this->parse_markers($content);
        $blocks  = [];
        $offset  = 0;
        $start   = null;
        $denies  = 0;
        $depth   = 0;

        foreach (preg_split('/(?<=\n)/', $content) as $line) {
            $length     = strlen($line);
            $normalized = $this->normalize_line($line);
            $in_managed = $managed['found'] && $offset >= $managed['start'] && $offset < $managed['end'];

            if ($in_managed) {
                $start = null;
            } elseif ($start !== null && $normalized === $close) {
                if ($denies > 0 && $depth === 0) {
                    $blocks[] = ['start' => $start, 'length' => $offset + $length - $start];
                }
                $start = null;
            } elseif ($start !== null && in_array($normalized, $deny, true)) {
                $denies++;
            } elseif ($start !== null && in_array($normalized, $if_open, true)) {
                $depth++;
            } elseif ($start !== null && $normalized === '</IfModule>' && $depth > 0) {
                $depth--;
            } elseif ($start !== null && ($normalized === '' || in_array($normalized, $neutral, true))) {
                // Allowed filler.
            } elseif ($normalized === $opening) {
                $start  = $offset;
                $denies = 0;
                $depth  = 0;
            } else {
                $start = null;
            }

            $offset += $length;
        }

        return $blocks;
    }

    /**
     * Remove every recognised manual block of a rule.
     *
     * @param string $content File content.
     * @param string $rule    Rule key.
     * @return string
     */
    public function strip_manual_blocks($content, $rule) {
        foreach (array_reverse($this->find_manual_blocks($content, $rule)) as $block) {
            $content = substr($content, 0, $block['start']) . (string) substr($content, $block['start'] + $block['length']);
        }
        return $content;
    }
```

- [ ] **Step 5: Lint and run the whole suite**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && php -l landeseiten-maintenance/includes/class-lsm-hardening.php && vendor/bin/phpunit -c phpunit.xml.dist
```

Expected: `No syntax errors detected`, then PASS — `OK (50 tests, 232 assertions)`.

- [ ] **Step 6: Commit**

```bash
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening add landeseiten-maintenance/includes/class-lsm-hardening.php tests
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening commit -m "$(cat <<'EOF'
feat(hardening): recognise hand-written deny blocks by grammar for adoption

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: State storage, static preflight and status-from-file

Status is computed from the files on every call; the option only says what is *desired*. Per rule, first match wins: `unsupported` (static preflight fails) → `paused` (`block_archives` only: `pause_until` is set, also when overdue) → `on` (the managed block contains this rule's lines) → `manual` (a recognised manual block is in the file) → `drift` (desired, but not in the managed block) → `off`. A rule that is in the block while desired is false still reports `on`. Corrupt markers simply mean "not in the block".

**Files:**
- Modify: `landeseiten-maintenance/includes/class-lsm-hardening.php` (insert above the final `}`)
- Test: `tests/HardeningStatusTest.php`

**Interfaces:**
- Consumes: `read_target`, `parse_markers`, `target_file` (Task 3); `find_manual_blocks`, `normalize_line` (Task 4); `rule_lines`, `rules_of`, `target_of`, `server_software()`, `now()` (Task 2); fakes `is_multisite()`, `$GLOBALS['wpdb']`, `LSM_Test_Env::$db_var` / `$db_queries` (Task 1).
- Produces:
  - `public function get_state()` → the option merged over defaults: `['rules' => [rule => bool ×3], 'pause_until' => int|null, 'last_attempt_at' => int|null, 'pending' => array|null, 'last_result' => array|null, 'rule_failures' => array]`.
  - `public function preflight($rule)` → `null` or one of `'multisite'`, `'openlitespeed'`, `'unknown_server'`, `'not_writable'` (checked in that order; reads `$_SERVER['LSWS_EDITION']` directly). `not_writable` = the file is not writable **or not readable** (an engine that cannot read the bytes must not replace them), or — when the file is absent — its directory is not writable.
  - `public function file_facts($target)` → `['existed' => bool, 'content' => string, 'corrupt' => bool, 'in_block' => [rule => bool], 'manual' => [rule => bool]]` (rules of that target only; plus `read_target()`'s `'unreadable' => true` when it applies). `corrupt` is also `true` for an unreadable file, so the writers that skip the preflight (auto-resume, crash recovery) refuse to write as well.
  - `public function rule_statuses()` → `[rule => ['state' => string, 'desired' => bool, 'unsupported_reason' => string|null, 'last_failure' => ['at', 'reason']|null]]`.
  - `public function get_status()` → `['plugin_version', 'server', 'rules', 'pause_until', 'pause_overdue', 'archive_attachments', 'last_result']` — exactly the `status` object of the spec's JSON.
  - `protected function count_archive_attachments()` → int (one COUNT query on `$wpdb->posts`).
- `server` is a short label: `OpenLiteSpeed`, `LiteSpeed`, `Apache`, otherwise the raw `SERVER_SOFTWARE`, or `unknown` when that is empty. `last_result` is `null` until the first operation ran.

- [ ] **Step 1: Write the failing test** — `W/tests/HardeningStatusTest.php`:

```php
<?php

/**
 * State storage, static preflight and status-from-file.
 */
class HardeningStatusTest extends HardeningTestCase {

    private function state_of($rule) {
        return $this->h->get_status()['rules'][$rule]['state'];
    }

    public function test_default_state_has_everything_off_and_nothing_pending() {
        $this->assertSame([
            'rules'           => ['block_archives' => false, 'block_debug_log' => false, 'block_uploads_php' => false],
            'pause_until'     => null,
            'last_attempt_at' => null,
            'pending'         => null,
            'last_result'     => null,
            'rule_failures'   => [],
        ], $this->h->get_state());
    }

    public function test_a_damaged_option_is_read_as_defaults() {
        update_option('lsm_hardening', 'not-an-array');
        $this->assertFalse($this->h->get_state()['rules']['block_archives']);

        update_option('lsm_hardening', ['rules' => ['block_archives' => 1, 'bogus' => true], 'pause_until' => '1790000600']);
        $state = $this->h->get_state();
        $this->assertSame(['block_archives' => true, 'block_debug_log' => false, 'block_uploads_php' => false], $state['rules']);
        $this->assertSame(1790000600, $state['pause_until']);
    }

    public function test_status_shape_on_a_fresh_site() {
        LSM_Test_Env::$db_var = 7;

        $off = ['state' => 'off', 'desired' => false, 'unsupported_reason' => null, 'last_failure' => null];
        $this->assertSame([
            'plugin_version'      => LSM_VERSION,
            'server'              => 'Apache',
            'rules'               => ['block_archives' => $off, 'block_debug_log' => $off, 'block_uploads_php' => $off],
            'pause_until'         => null,
            'pause_overdue'       => false,
            'archive_attachments' => 7,
            'last_result'         => null,
        ], $this->h->get_status());

        $this->assertCount(1, LSM_Test_Env::$db_queries);
        $this->assertStringContainsString("FROM wp_posts WHERE post_type = 'attachment'", LSM_Test_Env::$db_queries[0]);
        $this->assertStringContainsString("'application/zip'", LSM_Test_Env::$db_queries[0]);
        $this->assertStringContainsString("'application/x-gzip'", LSM_Test_Env::$db_queries[0]);
    }

    public function test_on_is_read_from_the_file_even_when_desired_is_false() {
        $this->put('content', "Options -Indexes\n\n" . $this->h->build_block('content', ['block_archives']) . "\n");

        $rules = $this->h->get_status()['rules'];
        $this->assertSame('on', $rules['block_archives']['state']);
        $this->assertFalse($rules['block_archives']['desired']);
        $this->assertSame('off', $rules['block_debug_log']['state']);
    }

    public function test_on_survives_reindenting_inside_the_markers() {
        $block = str_replace("\n  ", "\n\t\t", $this->h->build_block('uploads', ['block_uploads_php']));
        $this->put('uploads', $block . "\n");
        $this->assertSame('on', $this->state_of('block_uploads_php'));
    }

    public function test_drift_when_desired_but_not_in_the_file() {
        update_option('lsm_hardening', ['rules' => ['block_uploads_php' => true, 'block_debug_log' => true]]);
        // The content file has a block, but only with the archive rule.
        $this->put('content', $this->h->build_block('content', ['block_archives']) . "\n");

        $rules = $this->h->get_status()['rules'];
        $this->assertSame('drift', $rules['block_uploads_php']['state']);
        $this->assertTrue($rules['block_uploads_php']['desired']);
        $this->assertSame('drift', $rules['block_debug_log']['state']);
        $this->assertSame('on', $rules['block_archives']['state']);
    }

    public function test_manual_when_a_recognised_hand_written_block_is_in_the_file() {
        $this->put('uploads', "<FilesMatch \"\\.php$\">\nRequire all denied\n</FilesMatch>\n");
        $this->put('content', "<Files \"debug.log\">\nDeny from all\n</Files>\n");

        $this->assertSame('manual', $this->state_of('block_uploads_php'));
        $this->assertSame('manual', $this->state_of('block_debug_log'));
        $this->assertSame('off', $this->state_of('block_archives'));
    }

    public function test_manual_wins_over_drift_and_on_wins_over_manual() {
        update_option('lsm_hardening', ['rules' => ['block_debug_log' => true]]);
        $manual = "<Files \"debug.log\">\nDeny from all\n</Files>\n";

        $this->put('content', $manual);
        $this->assertSame('manual', $this->state_of('block_debug_log'));

        $this->put('content', $manual . "\n" . $this->h->build_block('content', ['block_debug_log']) . "\n");
        $this->assertSame('on', $this->state_of('block_debug_log'));
    }

    public function test_paused_and_overdue() {
        update_option('lsm_hardening', ['rules' => ['block_archives' => true], 'pause_until' => $this->h->time + 600]);

        $status = $this->h->get_status();
        $this->assertSame('paused', $status['rules']['block_archives']['state']);
        $this->assertSame($this->h->time + 600, $status['pause_until']);
        $this->assertFalse($status['pause_overdue']);

        $this->h->time += 601;
        $status = $this->h->get_status();
        $this->assertSame('paused', $status['rules']['block_archives']['state']);
        $this->assertTrue($status['pause_overdue']);
    }

    public function test_pause_only_ever_shows_on_the_archive_rule() {
        update_option('lsm_hardening', ['pause_until' => $this->h->time + 600]);
        $this->assertSame('off', $this->state_of('block_debug_log'));
        $this->assertSame('off', $this->state_of('block_uploads_php'));
    }

    public function test_corrupt_markers_are_not_on() {
        update_option('lsm_hardening', ['rules' => ['block_archives' => true]]);
        $this->put('content', "# BEGIN LSM-HARDENING\n" . implode("\n", $this->h->rule_lines('block_archives')) . "\n");
        $this->assertSame('drift', $this->state_of('block_archives'));
    }

    public function test_last_failure_and_last_result_are_passed_through() {
        $failure = ['at' => 1790000000, 'reason' => 'rule_ineffective'];
        $result  = ['at' => 1790000000, 'action' => 'enable', 'rule' => 'block_uploads_php', 'ok' => false, 'reason' => 'rule_ineffective', 'warnings' => []];
        update_option('lsm_hardening', ['rule_failures' => ['block_uploads_php' => $failure], 'last_result' => $result]);

        $status = $this->h->get_status();
        $this->assertSame($failure, $status['rules']['block_uploads_php']['last_failure']);
        $this->assertNull($status['rules']['block_archives']['last_failure']);
        $this->assertSame($result, $status['last_result']);
    }

    public function test_unsupported_multisite() {
        LSM_Test_Env::$multisite = true;
        foreach (LSM_Hardening::RULES as $rule) {
            $this->assertSame('multisite', $this->h->preflight($rule));
        }
        $rules = $this->h->get_status()['rules'];
        $this->assertSame('unsupported', $rules['block_archives']['state']);
        $this->assertSame('multisite', $rules['block_archives']['unsupported_reason']);
    }

    public function test_unsupported_openlitespeed() {
        $this->h->software        = 'LiteSpeed';
        $_SERVER['LSWS_EDITION'] = 'Openlitespeed 1.7.19';

        $this->assertSame('openlitespeed', $this->h->preflight('block_archives'));
        $this->assertSame('OpenLiteSpeed', $this->h->get_status()['server']);
    }

    public function test_litespeed_enterprise_is_supported() {
        $this->h->software        = 'LiteSpeed';
        $_SERVER['LSWS_EDITION'] = 'LiteSpeed Web Server/Enterprise';

        $this->assertNull($this->h->preflight('block_archives'));
        $this->assertSame('LiteSpeed', $this->h->get_status()['server']);
    }

    public function test_unsupported_unknown_server() {
        $this->h->software = 'nginx/1.24.0';
        $this->assertSame('unknown_server', $this->h->preflight('block_debug_log'));
        $this->assertSame('nginx/1.24.0', $this->h->get_status()['server']);

        // WP-CLI: wp_fix_server_vars() leaves SERVER_SOFTWARE empty.
        $this->h->software = '';
        $this->assertSame('unknown_server', $this->h->preflight('block_debug_log'));
        $this->assertSame('unknown', $this->h->get_status()['server']);
    }

    /**
     * chmod proves nothing for root (CI containers): it may write everywhere.
     */
    private function skip_as_root() {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Running as root: every file is writable.');
        }
    }

    public function test_unsupported_not_writable_file() {
        $this->skip_as_root();
        $this->put('uploads', "Options -Indexes\n");
        chmod($this->htaccess('uploads'), 0444);

        $this->assertSame('not_writable', $this->h->preflight('block_uploads_php'));
        $this->assertNull($this->h->preflight('block_archives'), 'the content file is a different file');

        $rules = $this->h->get_status()['rules'];
        $this->assertSame('unsupported', $rules['block_uploads_php']['state']);
        $this->assertSame('not_writable', $rules['block_uploads_php']['unsupported_reason']);
        $this->assertSame('off', $rules['block_archives']['state']);
    }

    public function test_unsupported_not_writable_directory_when_the_file_is_absent() {
        $this->skip_as_root();
        chmod($this->uploads, 0555);
        $this->assertSame('not_writable', $this->h->preflight('block_uploads_php'));
        chmod($this->uploads, 0777);
        $this->assertNull($this->h->preflight('block_uploads_php'));
    }

    public function test_a_file_that_cannot_be_read_is_unsupported_and_never_taken_for_empty() {
        $this->skip_as_root();
        $this->put('content', "<Files \"debug.log\">\nDeny from all\n</Files>\n");
        // Writable but not readable: a write built from "empty" would destroy what is in it.
        chmod($this->htaccess('content'), 0200);

        $this->assertSame('not_writable', $this->h->preflight('block_archives'));
        $this->assertTrue($this->h->file_facts('content')['corrupt'], 'the writers that skip the preflight must refuse too');

        $rules = $this->h->get_status()['rules'];
        $this->assertSame('unsupported', $rules['block_debug_log']['state']);
        $this->assertSame('not_writable', $rules['block_debug_log']['unsupported_reason']);
        $this->assertSame('off', $rules['block_uploads_php']['state'], 'the uploads file is a different file');
    }

    public function test_unsupported_wins_over_paused() {
        LSM_Test_Env::$multisite = true;
        update_option('lsm_hardening', ['pause_until' => $this->h->time + 600]);
        $this->assertSame('unsupported', $this->state_of('block_archives'));
    }
}
```

- [ ] **Step 2: Run and watch it fail**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && vendor/bin/phpunit -c phpunit.xml.dist --filter HardeningStatusTest
```

Expected: FAIL — `Tests: 20, Assertions: 0, Errors: 20.`, each one `Error: Call to undefined method LSM_Testable_Hardening::get_state()` (or `get_status()`, `preflight()`).

- [ ] **Step 3: Implement** — insert above the final `}` of `class LSM_Hardening`, preceded by one blank line:

```php
    // =========================================================================
    // STATE, PREFLIGHT AND STATUS
    // =========================================================================

    /**
     * Read the lsm_hardening option, filled up with defaults.
     *
     * @return array
     */
    public function get_state() {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $state = array_merge([
            'rules'           => [],
            'pause_until'     => null,
            'last_attempt_at' => null,
            'pending'         => null,
            'last_result'     => null,
            'rule_failures'   => [],
        ], $stored);

        $desired        = is_array($state['rules']) ? $state['rules'] : [];
        $state['rules'] = [];
        foreach (self::RULES as $rule) {
            $state['rules'][$rule] = !empty($desired[$rule]);
        }

        $state['pause_until']   = $state['pause_until'] === null ? null : (int) $state['pause_until'];
        $state['rule_failures'] = is_array($state['rule_failures']) ? $state['rule_failures'] : [];

        return $state;
    }

    /**
     * Static preflight: no write, no HTTP.
     *
     * @param string $rule Rule key.
     * @return string|null Reason the rule is unsupported here, or null.
     */
    public function preflight($rule) {
        if (is_multisite()) {
            return 'multisite';
        }

        $edition = isset($_SERVER['LSWS_EDITION']) ? (string) $_SERVER['LSWS_EDITION'] : '';
        if (stripos($edition, 'Openlitespeed') === 0) {
            return 'openlitespeed';
        }

        // SERVER_SOFTWARE cannot see an nginx in front of Apache — only the probes can.
        $software = $this->server_software();
        if (stripos($software, 'Apache') === false && stripos($software, 'LiteSpeed') === false) {
            return 'unknown_server';
        }

        // A file we can write but not read is as good as not writable: the candidate would be
        // built from "empty" and the write would destroy whatever is in it.
        $file     = $this->target_file($this->target_of($rule));
        $writable = file_exists($file) ? (is_writable($file) && is_readable($file)) : is_writable(dirname($file));
        if (!$writable) {
            return 'not_writable';
        }

        return null;
    }

    /**
     * Does the managed block contain a rule's lines (in order, whitespace-insensitive)?
     *
     * @param array  $block_lines Body lines from parse_markers().
     * @param string $rule        Rule key.
     * @return bool
     */
    private function block_has_rule(array $block_lines, $rule) {
        $haystack = array_map([$this, 'normalize_line'], $block_lines);
        $needle   = array_map([$this, 'normalize_line'], $this->rule_lines($rule));
        $last     = count($haystack) - count($needle);

        for ($i = 0; $i <= $last; $i++) {
            if (array_slice($haystack, $i, count($needle)) === $needle) {
                return true;
            }
        }
        return false;
    }

    /**
     * What a target file says right now.
     *
     * @param string $target 'content' or 'uploads'.
     * @return array ['existed' => bool, 'content' => string, 'corrupt' => bool,
     *                'in_block' => [rule => bool], 'manual' => [rule => bool]]
     *               (plus read_target()'s 'unreadable' => true when it applies)
     */
    public function file_facts($target) {
        $facts  = $this->read_target($target);
        $parsed = $this->parse_markers($facts['content']);

        // An unreadable file counts as corrupt: every writer refuses to touch a corrupt file,
        // also the ones that skip the preflight (auto-resume, crash recovery).
        $facts['corrupt']  = $parsed['corrupt'] || !empty($facts['unreadable']);
        $facts['in_block'] = [];
        $facts['manual']   = [];
        foreach ($this->rules_of($target) as $rule) {
            $facts['in_block'][$rule] = $parsed['found'] && $this->block_has_rule($parsed['lines'], $rule);
            $facts['manual'][$rule]   = !empty($this->find_manual_blocks($facts['content'], $rule));
        }

        return $facts;
    }

    /**
     * Per-rule status, computed from the files — the option only says what is desired.
     *
     * @return array Rule key => ['state', 'desired', 'unsupported_reason', 'last_failure'].
     */
    public function rule_statuses() {
        $state = $this->get_state();
        $facts = [
            'content' => $this->file_facts('content'),
            'uploads' => $this->file_facts('uploads'),
        ];

        $rules = [];
        foreach (self::RULES as $rule) {
            $file        = $facts[$this->target_of($rule)];
            $unsupported = $this->preflight($rule);

            if ($unsupported !== null) {
                $name = 'unsupported';
            } elseif ($rule === 'block_archives' && $state['pause_until'] !== null) {
                $name = 'paused';
            } elseif ($file['in_block'][$rule]) {
                $name = 'on';
            } elseif ($file['manual'][$rule]) {
                $name = 'manual';
            } elseif ($state['rules'][$rule]) {
                $name = 'drift';
            } else {
                $name = 'off';
            }

            $rules[$rule] = [
                'state'              => $name,
                'desired'            => $state['rules'][$rule],
                'unsupported_reason' => $unsupported,
                'last_failure'       => isset($state['rule_failures'][$rule]) ? $state['rule_failures'][$rule] : null,
            ];
        }

        return $rules;
    }

    /**
     * Short server name for the panel.
     *
     * @return string
     */
    private function server_label() {
        $software = $this->server_software();
        $edition  = isset($_SERVER['LSWS_EDITION']) ? (string) $_SERVER['LSWS_EDITION'] : '';

        if (stripos($edition, 'Openlitespeed') === 0) {
            return 'OpenLiteSpeed';
        }
        if (stripos($software, 'LiteSpeed') !== false) {
            return 'LiteSpeed';
        }
        if (stripos($software, 'Apache') !== false) {
            return 'Apache';
        }
        return $software !== '' ? $software : 'unknown';
    }

    /**
     * Media Library attachments block_archives would stop serving (one COUNT query).
     *
     * @return int
     */
    protected function count_archive_attachments() {
        global $wpdb;

        $mime_types = [
            'application/zip',
            'application/x-zip-compressed',
            'application/gzip',
            'application/x-gzip',
            'application/x-tar',
            'application/rar',
            'application/x-rar-compressed',
            'application/x-7z-compressed',
        ];
        $placeholders = implode(', ', array_fill(0, count($mime_types), '%s'));

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ($placeholders)",
            $mime_types
        ));
    }

    /**
     * Full status object of the REST responses.
     *
     * @return array
     */
    public function get_status() {
        $state = $this->get_state();

        return [
            'plugin_version'      => LSM_VERSION,
            'server'              => $this->server_label(),
            'rules'               => $this->rule_statuses(),
            'pause_until'         => $state['pause_until'],
            'pause_overdue'       => $state['pause_until'] !== null && $state['pause_until'] < $this->now(),
            'archive_attachments' => $this->count_archive_attachments(),
            'last_result'         => $state['last_result'],
        ];
    }
```

- [ ] **Step 4: Lint and run the whole suite**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && php -l landeseiten-maintenance/includes/class-lsm-hardening.php && vendor/bin/phpunit -c phpunit.xml.dist
```

Expected: `No syntax errors detected`, then PASS — `OK (70 tests, 291 assertions)`. (When the suite runs as root — some containers — the chmod-based tests report as skipped, because root can read and write everything: one in `HardeningWriterTest` from Task 3, three here, later one more in `HardeningApplyTest` — five in the finished suite. That is intended; the quoted counts are for a non-root run.)

- [ ] **Step 5: Commit**

```bash
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening add landeseiten-maintenance/includes/class-lsm-hardening.php tests/HardeningStatusTest.php
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening commit -m "$(cat <<'EOF'
feat(hardening): compute status from the files, add state defaults and static preflight

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Lock

The lock is one row in the options table, taken with `INSERT IGNORE` — the same statement WordPress core uses in `WP_Upgrader::create_lock()`. The database decides who wins, so two processes can never both acquire it. `add_option()` is **not** used for the insert: it checks `get_option()` first and then upserts with `INSERT … ON DUPLICATE KEY UPDATE`, which leaves a window of a few milliseconds in which two processes both "succeed". As core does, a successful insert is followed by `update_option()` so the options cache learns about the row. An option — unlike a transient — survives `wp_cache_flush()` and the plugin's own "clear all transients" action. `WP_Upgrader::create_lock()` itself is not called because it lives in `wp-admin/includes/class-wp-upgrader.php`, which is not loaded on the front-end `shutdown` path.

**Files:**
- Modify: `landeseiten-maintenance/includes/class-lsm-hardening.php` (insert above the final `}`)
- Test: `tests/HardeningLockTest.php`

**Interfaces:**
- Consumes: `now()`, constants `LOCK_OPTION`, `LOCK_TTL` (Task 2); fake `$wpdb->query()` that answers the lock's `INSERT IGNORE` with 1 / 0, fake `update_option()` / `get_option()` / `delete_option()` (Task 1).
- Produces: `public function acquire_lock()` → bool (`false` = busy; a lock older than 180 s is deleted and re-taken once); `public function release_lock()` → void.

- [ ] **Step 1: Write the failing test** — `W/tests/HardeningLockTest.php`:

```php
<?php

/**
 * The INSERT IGNORE lock.
 */
class HardeningLockTest extends HardeningTestCase {

    public function test_lock_is_taken_once_and_stores_the_time() {
        $this->assertTrue($this->h->acquire_lock());
        $this->assertSame($this->h->time, get_option('lsm_hardening_lock'));
        $this->assertFalse($this->h->acquire_lock());
    }

    public function test_release_frees_the_lock() {
        $this->h->acquire_lock();
        $this->h->release_lock();
        $this->assertFalse(get_option('lsm_hardening_lock'));
        $this->assertTrue($this->h->acquire_lock());
    }

    public function test_a_lock_of_exactly_180_seconds_is_still_held() {
        $this->h->acquire_lock();
        $this->h->time += 180;
        $this->assertFalse($this->h->acquire_lock());
    }

    public function test_a_lock_older_than_180_seconds_is_stale_and_taken_over() {
        $this->h->acquire_lock();
        $this->h->time += 181;
        $this->assertTrue($this->h->acquire_lock());
        $this->assertSame($this->h->time, get_option('lsm_hardening_lock'));
    }

    public function test_the_lock_is_not_the_state_option() {
        $this->h->acquire_lock();
        $this->assertFalse(get_option('lsm_hardening'));
    }
}
```

- [ ] **Step 2: Run and watch it fail**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && vendor/bin/phpunit -c phpunit.xml.dist --filter HardeningLockTest
```

Expected: FAIL — `Tests: 5, Assertions: 0, Errors: 5.`, each one `Error: Call to undefined method LSM_Testable_Hardening::acquire_lock()`.

- [ ] **Step 3: Implement** — insert above the final `}` of `class LSM_Hardening`, preceded by one blank line:

```php
    // =========================================================================
    // LOCK
    // =========================================================================

    /**
     * Take the operation lock.
     *
     * One options row, inserted with INSERT IGNORE so the database decides who wins
     * (the statement WP_Upgrader::create_lock() uses). An option (unlike a transient)
     * survives cache flushes and transient purges. A lock older than LOCK_TTL belongs
     * to a dead process: delete it and retry once.
     *
     * @return bool False when another operation holds the lock.
     */
    public function acquire_lock() {
        if ($this->insert_lock_row()) {
            return true;
        }

        $taken_at = (int) get_option(self::LOCK_OPTION, 0);
        if ($this->now() - $taken_at <= self::LOCK_TTL) {
            return false;
        }

        delete_option(self::LOCK_OPTION);
        return $this->insert_lock_row();
    }

    /**
     * Insert the lock row. add_option() is not atomic (it checks, then upserts), so the
     * insert goes straight to the database; update_option() afterwards only teaches the
     * options cache about the row, as core does after its own lock insert.
     *
     * @return bool True when this process inserted the row.
     */
    protected function insert_lock_row() {
        global $wpdb;

        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no') /* LOCK */",
            self::LOCK_OPTION,
            (string) $this->now()
        ));
        if (!$inserted) {
            return false;
        }

        update_option(self::LOCK_OPTION, $this->now(), false);
        return true;
    }

    /**
     * Release the operation lock.
     */
    public function release_lock() {
        delete_option(self::LOCK_OPTION);
    }
```

- [ ] **Step 4: Lint and run the whole suite**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && php -l landeseiten-maintenance/includes/class-lsm-hardening.php && vendor/bin/phpunit -c phpunit.xml.dist
```

Expected: `No syntax errors detected`, then PASS — `OK (75 tests, 300 assertions)`.

- [ ] **Step 5: Commit**

```bash
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening add landeseiten-maintenance/includes/class-lsm-hardening.php tests/HardeningLockTest.php
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening commit -m "$(cat <<'EOF'
feat(hardening): add the INSERT IGNORE lock with 180 s stale takeover

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: Loopback helper, baselines and probes

The self-test is differential. Server facts the code must respect: a bare 403 can come from a WAF or a Cloudflare challenge, so 401 / 5xx / `WP_Error` / `cf-mitigated: challenge` never count as "403 effective"; `.zip` is what a front-end nginx serves itself and `.wpress` is what the team downloads, so both are probed with **real** files whose body is a random token; Apache answers 403 for a covered *name* before it looks for the file, so `debug.log` and the uploads PHP probe are URL-only — the PHP file is never created; rewrite-mode page caches serve the anonymous homepage from `wp-content/cache/`, so the homepage is fetched with no query string and no cookies, while the plugin stylesheet gets a cache-buster so a CDN-cached 200 cannot mask a 500. **Every probe fetch gets its own fresh cache-buster too, generated at fetch time, not at prepare time:** the same probe URL is fetched before and after the write, Cloudflare (and host CDNs) cache `.zip` by extension — a 200 for about two hours, keyed by URL + query string — and the request header `Cache-Control: no-cache` is ignored by the edge. With one URL for both fetches the "after" fetch is an edge HIT with the cached "before" 200 → a false `rule_ineffective` and a rollback, i.e. `block_archives` could never be enabled (or manually resumed) on a Cloudflare-proxied site. The random file name only guarantees that the *first* fetch is a miss.

**Files:**
- Modify: `landeseiten-maintenance/includes/class-lsm-hardening.php` (insert above the final `}`)
- Test: `tests/HardeningLoopbackTest.php`

**Interfaces:**
- Consumes: `http_request($url, $args)`, `content_dir()`, `uploads_dir()`, constants `LOOPBACK_TIMEOUT`, `PROBE_PREFIX`, `SNAPSHOT_FILE` (Task 2); `put_contents($file, $content)` (Task 3); fakes `content_url()`, `home_url()`, `wp_upload_dir()`, `add_query_arg()`, `apply_filters()`; `$this->server` (`LSM_Fake_Server`), `$this->h->plugin`, `artifacts()` (Tasks 1-2).
- Produces:
  - `protected function plugin_dir()` → `LSM_PLUGIN_DIR` (seam).
  - `public function loopback($url)` → `['error' => bool, 'code' => int, 'body' => string, 'challenge' => bool]`.
  - `public function baseline_asset()` → `loopback()` result of `LSM_PLUGIN_URL . 'assets/css/ticket-ui.css'` + cache-buster, or `null` when the plugin dir is not below `content_dir()` (check skipped).
  - `public function baseline_home()` → `loopback(home_url('/'))`.
  - `public function prepare_probes($rule)` → list of `['label' => '.zip'|'.wpress'|'debug.log'|'uploads .php', 'url' => string, 'token' => string|null]`; creates the two archive probe files `lsm-probe-<16hex>.zip|.wpress` in `content_dir()`. The URLs are returned **without** a cache-buster.
  - `public function fetch_probes(array $probes)` → same list (URLs unchanged) plus `'code'` (int), `'inconclusive'` (bool), `'served'` (bool: 200, and the token in the body when there is one). Each call requests every probe once, at `url` + a fresh `lsm_hardening=<8hex>` query parameter — two calls never request the same URL.
  - `public function judge_before($rule, array $probes, $explained)` → `['reason' => null|'loopback_blocked', 'message' => string, 'warnings' => []|['already_blocked_elsewhere']]`. `$explained` = the file itself already explains a 403 (rule in our block, or a manual block) → no warning.
  - `public function judge_after($rule, $in_block, array $probes)` → `['reason' => null|'loopback_blocked'|'rule_ineffective'|'pause_ineffective_foreign_rule', 'message' => string]`.
  - `public static function is_own_artifact($name)` → bool: exactly the names the engine can create — `lsm-probe-<16 lowercase hex>.zip`, `lsm-probe-<16 lowercase hex>.wpress`, `.htaccess.lsm-bak`. **Never a PHP name and never a bare prefix match:** the uploads PHP probe is never created, so a `lsm-probe-*.php` (or `lsm-probe-x.php.jpg`, `lsm-probe-x.ico`) on disk is somebody else's file; Task 14 wires this function into the scanner, and a prefix match would hand a self-restoring malware kit a documented evasion name. `public function cleanup_artifacts()` → deletes the files with those names in both directories.
  - `private function asset_ok($asset)` → bool (`null` = skipped = ok; else 200 with a non-empty body) — Tasks 8 and 10 use it.
- Three readings of the spec's letter, in its spirit: (1) an unexplained 403 before the write raises `already_blocked_elsewhere` for all three rules, not only for the archive probes — the verification was just as vacuous; (2) the spec names a cache-buster only for baseline 2a, but it also states that Cloudflare caches `.zip` for ~2 h — so every probe fetch is busted as well (see above); (3) the spec's "`lsm-probe-*` … excluded" is honoured for every file the plugin can create (`lsm-probe-<16hex>.zip|.wpress`), not for arbitrary names that merely start with the prefix.

- [ ] **Step 1: Write the failing test** — `W/tests/HardeningLoopbackTest.php`:

```php
<?php

/**
 * Loopback helper, baselines, probes and their verdicts.
 */
class HardeningLoopbackTest extends HardeningTestCase {

    public function test_loopback_sends_the_spec_arguments() {
        $this->h->loopback('http://example.test/wp-content/x.txt');

        $this->assertSame([
            'timeout'     => 5,
            'redirection' => 0,
            'cookies'     => [],
            'sslverify'   => false,
            'headers'     => ['Cache-Control' => 'no-cache'],
        ], $this->server->requests[0]['args']);
    }

    public function test_sslverify_follows_the_https_local_ssl_verify_filter() {
        LSM_Test_Env::$filters['https_local_ssl_verify'] = true;
        $this->h->loopback('http://example.test/');
        $this->assertTrue($this->server->requests[0]['args']['sslverify']);
    }

    public function test_loopback_reduces_a_response() {
        file_put_contents($this->content . '/hello.txt', 'hi');
        $this->assertSame(
            ['error' => false, 'code' => 200, 'body' => 'hi', 'challenge' => false],
            $this->h->loopback('http://example.test/wp-content/hello.txt')
        );
    }

    public function test_loopback_reduces_a_wp_error_and_a_cloudflare_challenge() {
        $this->server->script('~x~', [
            new WP_Error('http_request_failed', 'cURL error 28'),
            LSM_Fake_Server::response(403, 'Just a moment...', ['cf-mitigated' => 'challenge']),
        ]);

        $this->assertSame(['error' => true, 'code' => 0, 'body' => '', 'challenge' => false], $this->h->loopback('http://example.test/x'));
        $this->assertTrue($this->h->loopback('http://example.test/x')['challenge']);
    }

    public function test_baseline_asset_is_the_plugin_css_with_a_cache_buster() {
        $asset = $this->h->baseline_asset();

        $this->assertSame(200, $asset['code']);
        $this->assertSame('.lsm-ticket{display:block}', $asset['body']);
        $this->assertMatchesRegularExpression(
            '~^http://example\.test/wp-content/plugins/landeseiten-maintenance/assets/css/ticket-ui\.css\?lsm_hardening=[a-f0-9]{8}$~',
            $this->server->requests[0]['url']
        );
    }

    public function test_baseline_asset_is_skipped_when_the_plugin_is_not_below_wp_content() {
        $this->h->plugin = $this->root . '/elsewhere/plugins/landeseiten-maintenance/';
        $this->assertNull($this->h->baseline_asset());
        $this->assertSame([], $this->server->requests);
    }

    public function test_baseline_home_is_a_plain_anonymous_request() {
        $home = $this->h->baseline_home();

        $this->assertSame(200, $home['code']);
        $this->assertSame('http://example.test/', $this->server->requests[0]['url']);
        $this->assertSame([], $this->server->requests[0]['args']['cookies']);
    }

    public function test_archive_probes_are_real_files_with_a_token() {
        $probes = $this->h->prepare_probes('block_archives');

        $this->assertSame(['.zip', '.wpress'], array_column($probes, 'label'));
        foreach ($probes as $probe) {
            $this->assertMatchesRegularExpression('~^http://example\.test/wp-content/lsm-probe-[a-f0-9]{16}\.(zip|wpress)$~', $probe['url']);
            $this->assertMatchesRegularExpression('~^[a-f0-9]{32}$~', $probe['token']);
            $this->assertSame($probe['token'], file_get_contents($this->content . '/' . basename($probe['url'])));
        }

        $fetched = $this->h->fetch_probes($probes);
        $this->assertSame([200, 200], array_column($fetched, 'code'));
        $this->assertSame([true, true], array_column($fetched, 'served'));
        $this->assertSame([false, false], array_column($fetched, 'inconclusive'));
    }

    public function test_the_uploads_php_probe_is_never_created() {
        $probes = $this->h->prepare_probes('block_uploads_php');

        $this->assertCount(1, $probes);
        $this->assertMatchesRegularExpression('~^http://example\.test/wp-content/uploads/lsm-probe-[a-f0-9]{16}\.php$~', $probes[0]['url']);
        $this->assertNull($probes[0]['token']);
        $this->assertSame([], glob($this->uploads . '/*'));

        $fetched = $this->h->fetch_probes($probes);
        $this->assertSame(404, $fetched[0]['code']);
        $this->assertFalse($this->server->requests[0]['file_existed']);
        $this->assertSame([], glob($this->uploads . '/*'));
    }

    public function test_the_debug_log_probe_needs_no_file() {
        $probes = $this->h->prepare_probes('block_debug_log');

        $this->assertSame('http://example.test/wp-content/debug.log', $probes[0]['url'], 'the cache-buster is added per fetch, not here');
        $this->assertSame(404, $this->h->fetch_probes($probes)[0]['code']);
        $this->assertMatchesRegularExpression('~/wp-content/debug\.log\?lsm_hardening=[a-f0-9]{8}$~', $this->server->requests[0]['url']);
        $this->assertFileDoesNotExist($this->content . '/debug.log');
    }

    public function test_every_probe_fetch_carries_a_fresh_cache_buster() {
        // The same probes are fetched before and after the write. An edge cache keyed by
        // URL + query (Cloudflare caches .zip by extension) must never see the same URL twice.
        $probes = $this->h->prepare_probes('block_archives');

        $first  = $this->h->fetch_probes($probes);
        $second = $this->h->fetch_probes($probes);

        $this->assertSame(array_column($probes, 'url'), array_column($first, 'url'), 'the probe list keeps the plain URLs');
        $this->assertSame(array_column($probes, 'url'), array_column($second, 'url'));

        $requested = array_column($this->server->requests, 'url');
        $this->assertCount(4, $requested);
        $this->assertCount(4, array_unique($requested));
        foreach ($requested as $url) {
            $this->assertMatchesRegularExpression('~^http://example\.test/wp-content/lsm-probe-[a-f0-9]{16}\.(zip|wpress)\?lsm_hardening=[a-f0-9]{8}$~', $url);
        }
    }

    public function test_probes_turn_403_once_the_rule_is_in_the_file() {
        $archives = $this->h->prepare_probes('block_archives');
        $php      = $this->h->prepare_probes('block_uploads_php');
        $log      = $this->h->prepare_probes('block_debug_log');

        $this->put('content', $this->h->build_block('content', ['block_archives', 'block_debug_log']) . "\n");
        $this->put('uploads', $this->h->build_block('uploads', ['block_uploads_php']) . "\n");

        $this->assertSame([403, 403], array_column($this->h->fetch_probes($archives), 'code'));
        $this->assertSame([403], array_column($this->h->fetch_probes($php), 'code'));
        $this->assertSame([403], array_column($this->h->fetch_probes($log), 'code'));
        $this->assertSame(200, $this->h->baseline_asset()['code'], 'the plugin CSS is not an archive');
    }

    private function probe($label, $code, $served = false, $inconclusive = false) {
        return ['label' => $label, 'url' => 'http://example.test/p', 'token' => null, 'code' => $code, 'served' => $served, 'inconclusive' => $inconclusive];
    }

    public function test_before_served_archives_are_fine() {
        $verdict = $this->h->judge_before('block_archives', [$this->probe('.zip', 200, true), $this->probe('.wpress', 200, true)], false);
        $this->assertSame(['reason' => null, 'message' => '', 'warnings' => []], $verdict);
    }

    public function test_before_an_unexplained_403_is_a_warning_not_a_failure() {
        foreach (LSM_Hardening::RULES as $rule) {
            $verdict = $this->h->judge_before($rule, [$this->probe('x', 403)], false);
            $this->assertNull($verdict['reason'], $rule);
            $this->assertSame(['already_blocked_elsewhere'], $verdict['warnings'], $rule);
        }
    }

    public function test_before_a_403_the_file_explains_is_no_warning() {
        $verdict = $this->h->judge_before('block_archives', [$this->probe('.zip', 403), $this->probe('.wpress', 403)], true);
        $this->assertSame([], $verdict['warnings']);
        $this->assertNull($verdict['reason']);
    }

    public function test_before_an_archive_probe_that_is_neither_served_nor_403_blocks_the_operation() {
        $cases = [
            'file not reachable (other docroot)' => $this->probe('.zip', 404),
            'soft 404 page without the token'    => $this->probe('.zip', 200, false),
            'redirect'                           => $this->probe('.wpress', 301),
        ];
        foreach ($cases as $case => $probe) {
            $verdict = $this->h->judge_before('block_archives', [$probe], false);
            $this->assertSame('loopback_blocked', $verdict['reason'], $case);
            $this->assertStringContainsString($probe['label'], $verdict['message'], $case);
        }
    }

    public function test_before_any_code_is_fine_for_the_url_only_probes() {
        foreach ([404, 200, 301] as $code) {
            $this->assertNull($this->h->judge_before('block_debug_log', [$this->probe('debug.log', $code, $code === 200)], false)['reason']);
            $this->assertNull($this->h->judge_before('block_uploads_php', [$this->probe('uploads .php', $code)], false)['reason']);
        }
    }

    public function test_inconclusive_responses_are_loopback_blocked_before_and_after() {
        $this->server->script('~inconclusive~', [
            new WP_Error('http_request_failed', 'timeout'),
            LSM_Fake_Server::response(401, 'Authorization Required'),
            LSM_Fake_Server::response(503, 'Service Unavailable'),
            LSM_Fake_Server::response(403, 'Just a moment...', ['cf-mitigated' => 'challenge']),
        ]);

        for ($i = 0; $i < 4; $i++) {
            $probes = $this->h->fetch_probes([['label' => 'uploads .php', 'url' => 'http://example.test/wp-content/uploads/inconclusive.php', 'token' => null]]);
            $this->assertTrue($probes[0]['inconclusive'], 'response ' . $i);
            $this->assertSame('loopback_blocked', $this->h->judge_before('block_uploads_php', $probes, false)['reason'], 'before ' . $i);
            $this->assertSame('loopback_blocked', $this->h->judge_after('block_uploads_php', true, $probes)['reason'], 'after on ' . $i);
            $this->assertSame('loopback_blocked', $this->h->judge_after('block_uploads_php', false, $probes)['reason'], 'after off ' . $i);
        }
    }

    public function test_after_a_rule_that_is_on_needs_exactly_403_on_every_probe() {
        $this->assertNull($this->h->judge_after('block_archives', true, [$this->probe('.zip', 403), $this->probe('.wpress', 403)])['reason']);

        $verdict = $this->h->judge_after('block_archives', true, [$this->probe('.zip', 200, true), $this->probe('.wpress', 403)]);
        $this->assertSame('rule_ineffective', $verdict['reason']);
        $this->assertStringContainsString('.zip', $verdict['message']);
        $this->assertStringContainsString('200', $verdict['message']);

        $this->assertSame('rule_ineffective', $this->h->judge_after('block_uploads_php', true, [$this->probe('uploads .php', 404)])['reason']);
        $this->assertSame('rule_ineffective', $this->h->judge_after('block_debug_log', true, [$this->probe('debug.log', 200, true)])['reason']);
    }

    public function test_after_archives_out_of_the_block_need_the_wpress_probe_served() {
        $served  = $this->probe('.wpress', 200, true);
        $blocked = $this->probe('.wpress', 403);

        $this->assertNull($this->h->judge_after('block_archives', false, [$this->probe('.zip', 403), $served])['reason'], 'a .zip blocked elsewhere does not matter');
        $this->assertSame('pause_ineffective_foreign_rule', $this->h->judge_after('block_archives', false, [$this->probe('.zip', 200, true), $blocked])['reason']);
        $this->assertSame('pause_ineffective_foreign_rule', $this->h->judge_after('block_archives', false, [$this->probe('.wpress', 200, false)])['reason'], '200 without the token is not our file');
    }

    public function test_after_turning_the_other_rules_off_needs_nothing_more() {
        $this->assertNull($this->h->judge_after('block_debug_log', false, [$this->probe('debug.log', 403)])['reason']);
        $this->assertNull($this->h->judge_after('block_uploads_php', false, [$this->probe('uploads .php', 404)])['reason']);
    }

    public function test_own_artifacts_are_recognised_by_their_exact_names() {
        $this->assertTrue(LSM_Hardening::is_own_artifact('lsm-probe-0123456789abcdef.zip'));
        $this->assertTrue(LSM_Hardening::is_own_artifact('lsm-probe-0123456789abcdef.wpress'));
        $this->assertTrue(LSM_Hardening::is_own_artifact('.htaccess.lsm-bak'));

        // The uploads PHP probe is never created: a PHP name with our prefix is somebody else's file.
        $this->assertFalse(LSM_Hardening::is_own_artifact('lsm-probe-0123456789abcdef.php'));
        $this->assertFalse(LSM_Hardening::is_own_artifact('lsm-probe-0123456789abcdef.php.zip'));
        $this->assertFalse(LSM_Hardening::is_own_artifact('lsm-probe-0123456789abcdef.php.jpg'));
        $this->assertFalse(LSM_Hardening::is_own_artifact('lsm-probe-0123456789abcdef.ico'));
        $this->assertFalse(LSM_Hardening::is_own_artifact('lsm-probe-shell.zip'));
        $this->assertFalse(LSM_Hardening::is_own_artifact('lsm-probe-0123456789ABCDEF.zip'));
        $this->assertFalse(LSM_Hardening::is_own_artifact("lsm-probe-0123456789abcdef.zip\n"));
        $this->assertFalse(LSM_Hardening::is_own_artifact('my-lsm-probe-0123456789abcdef.zip'));
        $this->assertFalse(LSM_Hardening::is_own_artifact('.htaccess'));
        $this->assertFalse(LSM_Hardening::is_own_artifact('backup.zip'));
    }

    public function test_cleanup_deletes_probes_and_snapshots_in_both_directories_and_nothing_else() {
        $this->h->prepare_probes('block_archives');
        file_put_contents($this->content . '/.htaccess.lsm-bak', 'x');
        file_put_contents($this->uploads . '/.htaccess.lsm-bak', 'x');
        file_put_contents($this->uploads . '/lsm-probe-0123456789abcdef.php', '<?php // not ours: the PHP probe is never created');
        file_put_contents($this->content . '/backup.zip', 'keep');
        $this->put('content', "Options -Indexes\n");
        $this->assertCount(4, $this->artifacts());

        $this->h->cleanup_artifacts();

        $this->assertSame([], $this->artifacts());
        $this->assertFileExists($this->uploads . '/lsm-probe-0123456789abcdef.php', 'never delete what we cannot have created');
        $this->assertFileExists($this->content . '/backup.zip');
        $this->assertSame("Options -Indexes\n", $this->get('content'));
    }
}
```

- [ ] **Step 2: Run and watch it fail**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && vendor/bin/phpunit -c phpunit.xml.dist --filter HardeningLoopbackTest
```

Expected: FAIL — `Tests: 23, Assertions: 0, Errors: 23.`, each one `Error: Call to undefined method LSM_Testable_Hardening::loopback()` (or `baseline_asset()`, `prepare_probes()`, `judge_before()`, `LSM_Hardening::is_own_artifact()`, …).

- [ ] **Step 3: Implement** — insert above the final `}` of `class LSM_Hardening`, preceded by one blank line:

```php
    // =========================================================================
    // LOOPBACK, BASELINE AND PROBES
    // =========================================================================

    /**
     * Absolute path of this plugin's directory.
     *
     * @return string
     */
    protected function plugin_dir() {
        return LSM_PLUGIN_DIR;
    }

    /**
     * One loopback GET, reduced to what the self-test looks at.
     *
     * @param string $url URL on this site.
     * @return array ['error' => bool, 'code' => int, 'body' => string, 'challenge' => bool]
     */
    public function loopback($url) {
        $response = $this->http_request($url, [
            'timeout'     => self::LOOPBACK_TIMEOUT,
            // An "ErrorDocument 403 https://..." would turn a 403 into 302 -> 200.
            'redirection' => 0,
            'cookies'     => [],
            'sslverify'   => apply_filters('https_local_ssl_verify', false, $url),
            'headers'     => ['Cache-Control' => 'no-cache'],
        ]);

        if (is_wp_error($response)) {
            return ['error' => true, 'code' => 0, 'body' => '', 'challenge' => false];
        }

        return [
            'error'     => false,
            'code'      => (int) wp_remote_retrieve_response_code($response),
            'body'      => (string) wp_remote_retrieve_body($response),
            'challenge' => strtolower((string) wp_remote_retrieve_header($response, 'cf-mitigated')) === 'challenge',
        ];
    }

    /**
     * A response that proves nothing about our rule: no answer, basic auth,
     * a server error or a Cloudflare challenge. Never counts as "403 effective".
     *
     * @param array $response Result of loopback().
     * @return bool
     */
    private function is_inconclusive(array $response) {
        return $response['error'] || $response['challenge'] || $response['code'] === 401 || $response['code'] >= 500;
    }

    /**
     * Append a fresh cache-buster, so a CDN or nginx cached 200 can neither mask a 500
     * (baseline) nor answer the "after" fetch of a probe with its cached "before" response.
     *
     * @param string $url URL.
     * @return string
     */
    private function bust($url) {
        return add_query_arg('lsm_hardening', bin2hex(random_bytes(4)), $url);
    }

    /**
     * Baseline 2a: a static file of this plugin, served from below wp-content.
     *
     * @return array|null loopback() result, or null when the plugin does not live below wp-content.
     */
    public function baseline_asset() {
        $plugin_dir  = rtrim(str_replace('\\', '/', $this->plugin_dir()), '/') . '/';
        $content_dir = rtrim(str_replace('\\', '/', $this->content_dir()), '/') . '/';
        if (strpos($plugin_dir, $content_dir) !== 0) {
            return null;
        }

        return $this->loopback($this->bust(LSM_PLUGIN_URL . 'assets/css/ticket-ui.css'));
    }

    /**
     * Was baseline 2a fine (or not applicable)?
     *
     * @param array|null $asset Result of baseline_asset().
     * @return bool
     */
    private function asset_ok($asset) {
        return $asset === null || (!$asset['error'] && $asset['code'] === 200 && $asset['body'] !== '');
    }

    /**
     * Baseline 2b: the homepage as an anonymous visitor — no query string, no cookies —
     * because rewrite-mode page caches serve exactly that request from wp-content/cache/.
     *
     * @return array loopback() result.
     */
    public function baseline_home() {
        return $this->loopback(home_url('/'));
    }

    /**
     * Prepare the probes of a rule.
     *
     * Archives: two real files (.zip is what a front-end nginx serves itself, .wpress is
     * what the team downloads) with a random token as body. debug.log and the uploads PHP
     * probe are plain URLs: Apache answers 403 for a covered name before it looks for the
     * file, so the PHP probe is never created. No cache-buster here: fetch_probes() adds a
     * fresh one to every single request.
     *
     * @param string $rule Rule key.
     * @return array List of ['label' => string, 'url' => string, 'token' => string|null].
     */
    public function prepare_probes($rule) {
        if ($rule === 'block_debug_log') {
            return [['label' => 'debug.log', 'url' => content_url('debug.log'), 'token' => null]];
        }

        if ($rule === 'block_uploads_php') {
            $upload_dir = wp_upload_dir();
            $name       = self::PROBE_PREFIX . bin2hex(random_bytes(8)) . '.php';
            return [['label' => 'uploads .php', 'url' => rtrim($upload_dir['baseurl'], '/') . '/' . $name, 'token' => null]];
        }

        $probes = [];
        foreach (['zip', 'wpress'] as $extension) {
            $name  = self::PROBE_PREFIX . bin2hex(random_bytes(8)) . '.' . $extension;
            $token = bin2hex(random_bytes(16));
            $this->put_contents($this->content_dir() . '/' . $name, $token);
            $probes[] = ['label' => '.' . $extension, 'url' => content_url($name), 'token' => $token];
        }
        return $probes;
    }

    /**
     * Fetch every probe once.
     *
     * @param array $probes Result of prepare_probes().
     * @return array The probes with 'code' (int), 'inconclusive' (bool) and 'served' (bool: 200, and the token when there is one).
     */
    public function fetch_probes(array $probes) {
        foreach ($probes as $i => $probe) {
            // Fresh cache-buster per fetch: the same URL is fetched before and after the write, and an
            // edge cache (Cloudflare caches .zip by extension, a 200 for ~2 h, keyed by URL + query, and
            // ignores our "Cache-Control: no-cache") would answer the "after" fetch with the cached
            // "before" 200 -> a false rule_ineffective.
            $response = $this->loopback($this->bust($probe['url']));

            $probes[$i]['code']         = $response['code'];
            $probes[$i]['inconclusive'] = $this->is_inconclusive($response);
            $probes[$i]['served']       = $response['code'] === 200
                && ($probe['token'] === null || strpos($response['body'], $probe['token']) !== false);
        }
        return $probes;
    }

    /**
     * Judge the probes fetched before the write.
     *
     * @param string $rule      Rule key.
     * @param array  $probes    Result of fetch_probes().
     * @param bool   $explained Whether the file itself already explains a 403 (rule in our block, or a manual block).
     * @return array ['reason' => string|null, 'message' => string, 'warnings' => array]
     */
    public function judge_before($rule, array $probes, $explained) {
        $warnings = [];

        foreach ($probes as $probe) {
            // Archive probes are real files: only "served with the token" or "already 403" make sense.
            $plausible = $rule !== 'block_archives' || $probe['served'] || $probe['code'] === 403;
            if ($probe['inconclusive'] || !$plausible) {
                return [
                    'reason'   => 'loopback_blocked',
                    'message'  => sprintf('The site could not fetch its own %s probe (HTTP %d), so the rule cannot be verified. Nothing was changed.', $probe['label'], $probe['code']),
                    'warnings' => [],
                ];
            }
            if ($probe['code'] === 403 && !$explained) {
                $warnings = ['already_blocked_elsewhere'];
            }
        }

        return ['reason' => null, 'message' => '', 'warnings' => $warnings];
    }

    /**
     * Judge the probes fetched after the write.
     *
     * @param string $rule     Rule key.
     * @param bool   $in_block Whether the rule is in the block that was just written.
     * @param array  $probes   Result of fetch_probes().
     * @return array ['reason' => string|null, 'message' => string]
     */
    public function judge_after($rule, $in_block, array $probes) {
        foreach ($probes as $probe) {
            if ($probe['inconclusive']) {
                return [
                    'reason'  => 'loopback_blocked',
                    'message' => sprintf('The %s probe could not be evaluated after the write (HTTP %d).', $probe['label'], $probe['code']),
                ];
            }
            if ($in_block && $probe['code'] !== 403) {
                return [
                    'reason'  => 'rule_ineffective',
                    'message' => sprintf('The rule has no effect on this server: the %s probe was still answered with HTTP %d instead of 403 (a front-end nginx or CDN serving static files?).', $probe['label'], $probe['code']),
                ];
            }
            if (!$in_block && $rule === 'block_archives' && $probe['label'] === '.wpress' && !$probe['served']) {
                return [
                    'reason'  => 'pause_ineffective_foreign_rule',
                    'message' => sprintf('Our rule is out of the file, but the .wpress probe is still answered with HTTP %d: another rule on this server blocks it.', $probe['code']),
                ];
            }
        }

        return ['reason' => null, 'message' => ''];
    }

    /**
     * Is this file name one of our own short-lived artifacts (probe file or snapshot)?
     * cleanup_artifacts() deletes these and the plugin's suspicious-file collectors skip them,
     * so the match is exact — a bare prefix match would be an evasion name for malware.
     *
     * @param string $name File name without directory.
     * @return bool
     */
    public static function is_own_artifact($name) {
        if ($name === self::SNAPSHOT_FILE) {
            return true;
        }
        // Exactly what prepare_probes() creates. Never a PHP name: the uploads PHP probe is never
        // created, so a "lsm-probe-*.php" on disk is somebody else's file and must be reported.
        return preg_match('/^' . preg_quote(self::PROBE_PREFIX, '/') . '[a-f0-9]{16}\.(zip|wpress)\z/', $name) === 1;
    }

    /**
     * Delete every probe file and snapshot in both directories. Paths are derived, never stored.
     */
    public function cleanup_artifacts() {
        foreach ([$this->content_dir(), $this->uploads_dir()] as $dir) {
            foreach ((array) @scandir($dir) as $name) {
                if (is_string($name) && self::is_own_artifact($name) && is_file($dir . '/' . $name)) {
                    @unlink($dir . '/' . $name);
                }
            }
        }
    }
```

- [ ] **Step 4: Lint and run the whole suite**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && php -l landeseiten-maintenance/includes/class-lsm-hardening.php && vendor/bin/phpunit -c phpunit.xml.dist
```

Expected: `No syntax errors detected`, then PASS — `OK (98 tests, 412 assertions)`.

- [ ] **Step 5: Commit**

```bash
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening add landeseiten-maintenance/includes/class-lsm-hardening.php tests/HardeningLoopbackTest.php
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening commit -m "$(cat <<'EOF'
feat(hardening): add loopback helper, baselines and differential probes

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: Full apply procedure with rollback

One procedure for every platform-initiated change: 1 static preflight + lock → 2 baseline before (2a plugin stylesheet, 2b anonymous homepage) → 3 probes before → 4 snapshot + `pending` → 5 write → 6 self-test after → 7 rollback on any failure → 8 finish (both paths). Things an implementer would otherwise get wrong:

- **The candidate block is built from the file, not from the option:** "what the managed block holds right now, plus or minus this rule". Otherwise enabling `block_debug_log` would silently re-add a drifted `block_archives` without probing it, and a pause on a site whose DB was restored would drop rules the option does not know about.
- **Nothing before the lock writes state.** `invalid_rule`, `unsupported` and `busy` return without touching the option, `last_result` or the log. `busy` must not release the *other* operation's lock.
- **Never write a candidate built from stale bytes.** The file is read at the very top (the corrupt-marker check must come before any HTTP, and `judge_before()` needs to know whether the file itself explains a 403), then up to four 5-second loopbacks run. WebP Express and cache plugins do rewrite `wp-content/.htaccess` from admin requests in the meantime. So directly before step 4 the file is read again; if it differs from what the candidate was built from, the operation ends with `write_failed` ("changed by something else … try again") and nothing is written — the spec holds the original bytes at step 4, after the loopbacks, not before them.
- **`rules` / `pause_until` are committed only in `finish()` on success**, so a crash leaves the option describing the last good state.
- **`finish()` saves the state first and deletes the snapshot and probe files after that.** A kill between the two (LiteSpeed kills on client abort, and `finish()` starts right after the long loopback phase) then leaves at worst a stray `.htaccess.lsm-bak` / probe pair that the next operation or the deactivation removes. The opposite order would leave `pending` set with no snapshot: after a killed, successful pause, recovery could restore nothing, `pause_until` was never committed, and the site would sit without the archive rule, with no timer — exactly the "silently exposed" outcome the spec forbids.
- **A throwable inside the procedure is a crash:** no `try/finally`. Lock, `pending`, snapshot and probe files stay exactly as a killed PHP process would leave them, and crash recovery (Task 11) cleans up.
- **Rollback:** restore the original bytes from memory (or delete a file that did not exist), re-check baseline 2a. Restore not verifiable → `rollback_failed` plus the last resort (strip only the managed block). Restore fine but 2a still broken → `rollback_failed` *without* stripping: those exact bytes passed 2a a moment ago, and stripping would throw away rules that were working.
- **Homepage comparison:** same status code as before; the body must be non-empty only if it was non-empty before (a `redirection => 0` fetch of a redirecting homepage legitimately has an empty body).
- Every failed operation past the lock records `rule_failures[rule]` (sticky until the next success of that rule) and `last_result`.

**Files:**
- Modify: `landeseiten-maintenance/includes/class-lsm-hardening.php` (insert above the final `}`)
- Test: `tests/HardeningApplyTest.php`

**Interfaces:**
- Consumes: `preflight` , `file_facts`, `get_state`, `get_status` (Task 5); `acquire_lock`, `release_lock` (Task 6); `baseline_asset`, `asset_ok`, `baseline_home`, `prepare_probes`, `fetch_probes`, `judge_before`, `judge_after`, `cleanup_artifacts` (Task 7); `strip_manual_blocks` (Task 4); `replace_block`, `commit_target`, `restore_target`, `read_target`, `target_file`, `put_contents` (Task 3); `build_block`, `rules_of`, `target_of`, `now` (Task 2); `LSM_Logger::log()`; `LSM_Htaccess_Fixtures` (Task 4); test helpers `put_an_edge_cache_in_front()`, `artifacts()`, `$this->h->put_hook` (Task 2).
- Produces:
  - `public function respond($success, $reason, $message, array $warnings = [])` → `['success' => bool, 'reason' => string|null, 'message' => string, 'warnings' => string[], 'status' => get_status()]` — the top-level REST shape.
  - `public function set_rule($rule, $enabled)` → `respond()` shape. Enable also means adopt (manual) and re-apply (drift). On `block_archives` it also ends a pause on success.
  - `private function apply($action, $rule, $in_block, array $commit)` → `respond()` shape; `$action` ∈ `enable|disable|pause|resume` (also `pending.op` and `last_result.action`); `$commit` keys, both optional, applied on success only: `'rules' => [rule => bool]`, `'pause_minutes' => int|null` (`null` clears `pause_until`, an int sets it to `now() + minutes * 60`).
  - `private function save_state(array $state)`; `private function write_snapshot($target, array $current)` → bool; `private function rollback($target, array $current, array $failure)` → `['reason', 'message']`; `private function finish($action, $rule, array $outcome, array $commit)` → `respond()` shape, where `$outcome = ['reason' => string|null, 'message' => string, 'warnings' => array]`. Tasks 9-11 call `apply`, `save_state`, `write_snapshot`, `rollback` and `finish`.
  - Log actions: `hardening_applied` (`success`), `hardening_failed` (`error`).

- [ ] **Step 1: Write the failing test** — `W/tests/HardeningApplyTest.php`:

```php
<?php

/**
 * The full apply procedure: enable, disable, adopt — and every way it can fail.
 */
class HardeningApplyTest extends HardeningTestCase {

    const FOREIGN = "# BEGIN WebP Express\nAddType image/webp .webp\n# END WebP Express\n";

    /**
     * What every failed operation must leave behind: desired state, pause and file bytes
     * untouched, nothing pending, lock free, no artifacts, the failure recorded.
     */
    private function assertFailedCleanly(array $result, $reason, $rule, $target, $expected_file) {
        $this->assertFalse($result['success']);
        $this->assertSame($reason, $result['reason']);
        $this->assertNotSame('', $result['message']);

        $this->assertSame($expected_file, $this->get($target), 'file bytes');

        $state = $this->h->get_state();
        $this->assertSame(['block_archives' => false, 'block_debug_log' => false, 'block_uploads_php' => false], $state['rules']);
        $this->assertNull($state['pause_until']);
        $this->assertNull($state['pending']);
        $this->assertFalse(get_option('lsm_hardening_lock'), 'lock released');
        $this->assertSame([], $this->artifacts());

        $this->assertSame($reason, $state['rule_failures'][$rule]['reason']);
        $this->assertSame($this->h->time, $state['rule_failures'][$rule]['at']);
        $this->assertFalse($state['last_result']['ok']);
        $this->assertSame($reason, $state['last_result']['reason']);
        $this->assertSame($reason, $result['status']['rules'][$rule]['last_failure']['reason']);
        $this->assertSame(['hardening_failed', 'error'], array_slice(LSM_Test_Env::$log[0], 0, 2));
    }

    /**
     * Count writes to .htaccess files (not to probes or snapshots).
     */
    private function count_htaccess_writes() {
        $counter = new stdClass();
        $counter->n = 0;
        $this->h->put_hook = function ($file) use ($counter) {
            if (basename($file) === '.htaccess') {
                $counter->n++;
            }
            return null;
        };
        return $counter;
    }

    // -------------------------------------------------------------------------
    // Success
    // -------------------------------------------------------------------------

    public function test_enable_archives_on_a_fresh_site() {
        $result = $this->h->set_rule('block_archives', true);

        $this->assertSame(true, $result['success']);
        $this->assertNull($result['reason']);
        $this->assertSame('Applied and verified', $result['message']);
        $this->assertSame([], $result['warnings']);
        $this->assertSame('on', $result['status']['rules']['block_archives']['state']);
        $this->assertTrue($result['status']['rules']['block_archives']['desired']);
        $this->assertSame(
            ['at' => $this->h->time, 'action' => 'enable', 'rule' => 'block_archives', 'ok' => true, 'reason' => null, 'warnings' => []],
            $result['status']['last_result']
        );

        $this->assertSame($this->h->build_block('content', ['block_archives']) . "\n", $this->get('content'));
        $this->assertNull($this->get('uploads'));

        $state = $this->h->get_state();
        $this->assertTrue($state['rules']['block_archives']);
        $this->assertNull($state['pending']);
        $this->assertFalse(get_option('lsm_hardening_lock'));
        $this->assertSame([], $this->artifacts());
        $this->assertSame(['hardening_applied', 'success'], array_slice(LSM_Test_Env::$log[0], 0, 2));
    }

    public function test_enable_runs_baselines_and_probes_before_and_after() {
        $this->h->set_rule('block_archives', true);

        $kinds = array_map(function ($request) {
            if (strpos($request['url'], 'ticket-ui.css') !== false) {
                return 'asset';
            }
            if ($request['url'] === 'http://example.test/') {
                return 'home';
            }
            return pathinfo(parse_url($request['url'], PHP_URL_PATH), PATHINFO_EXTENSION);
        }, $this->server->requests);

        $this->assertSame(['asset', 'home', 'zip', 'wpress', 'asset', 'home', 'zip', 'wpress'], $kinds);
        foreach ($this->server->requests_matching('~lsm-probe-~') as $request) {
            $this->assertTrue($request['file_existed'], 'archive probes are real files');
        }
    }

    public function test_enable_uploads_php_never_creates_a_php_file() {
        $result = $this->h->set_rule('block_uploads_php', true);

        $this->assertTrue($result['success']);
        $this->assertSame($this->h->build_block('uploads', ['block_uploads_php']) . "\n", $this->get('uploads'));
        $this->assertNull($this->get('content'));

        $probes = $this->server->requests_matching('~/uploads/lsm-probe-[a-f0-9]{16}\.php\?lsm_hardening=[a-f0-9]{8}$~');
        $this->assertCount(2, $probes);
        $this->assertSame([false, false], array_column($probes, 'file_existed'));
        $this->assertNotSame($probes[0]['url'], $probes[1]['url'], 'before and after never share a URL');
    }

    public function test_an_edge_cache_cannot_fake_rule_ineffective() {
        // Cloudflare in front of the site: the "before" 200 of the .zip probe is cached at the edge.
        // If the "after" fetch used the same URL it would be a HIT (200) and the enable would be
        // rolled back as rule_ineffective on every Cloudflare-proxied site.
        $edge = $this->put_an_edge_cache_in_front();

        $result = $this->h->set_rule('block_archives', true);

        $this->assertTrue($result['success'], (string) $result['reason']);
        $this->assertCount(1, $edge, 'the edge did cache the "before" 200 of the .zip probe');
        $this->assertSame('on', $result['status']['rules']['block_archives']['state']);
    }

    public function test_enable_debug_log_keeps_the_other_rule_and_foreign_bytes() {
        $this->put('content', self::FOREIGN);
        $this->h->set_rule('block_archives', true);

        $result = $this->h->set_rule('block_debug_log', true);

        $this->assertTrue($result['success']);
        $this->assertSame(
            self::FOREIGN . "\n" . $this->h->build_block('content', ['block_archives', 'block_debug_log']) . "\n",
            $this->get('content')
        );
        $this->assertSame('on', $result['status']['rules']['block_archives']['state']);
        $this->assertSame('on', $result['status']['rules']['block_debug_log']['state']);
    }

    public function test_enabling_one_rule_does_not_resurrect_a_drifted_one() {
        update_option('lsm_hardening', ['rules' => ['block_archives' => true]]);

        $result = $this->h->set_rule('block_debug_log', true);

        $this->assertTrue($result['success']);
        $this->assertSame($this->h->build_block('content', ['block_debug_log']) . "\n", $this->get('content'));
        $this->assertSame('drift', $result['status']['rules']['block_archives']['state']);
    }

    public function test_reapplying_a_drifted_rule_fixes_it() {
        update_option('lsm_hardening', ['rules' => ['block_archives' => true]]);
        $result = $this->h->set_rule('block_archives', true);
        $this->assertTrue($result['success']);
        $this->assertSame('on', $result['status']['rules']['block_archives']['state']);
    }

    public function test_disable_removes_the_rule_and_with_the_last_rule_the_markers() {
        $this->h->set_rule('block_archives', true);
        $this->h->set_rule('block_debug_log', true);

        $result = $this->h->set_rule('block_archives', false);
        $this->assertTrue($result['success']);
        $this->assertSame('disable', $result['status']['last_result']['action']);
        $this->assertSame($this->h->build_block('content', ['block_debug_log']) . "\n", $this->get('content'));
        $this->assertFalse($this->h->get_state()['rules']['block_archives']);

        $this->assertTrue($this->h->set_rule('block_debug_log', false)['success']);
        // The file existed when this operation started, so it is emptied, not deleted.
        $this->assertSame('', $this->get('content'));
    }

    public function test_disabling_a_rule_on_a_site_without_the_file_creates_nothing() {
        $result = $this->h->set_rule('block_uploads_php', false);

        $this->assertTrue($result['success']);
        $this->assertNull($this->get('uploads'));
        $this->assertSame('off', $result['status']['rules']['block_uploads_php']['state']);
    }

    public function test_disable_gives_a_foreign_file_back_byte_for_byte() {
        $this->put('uploads', self::FOREIGN);
        $this->h->set_rule('block_uploads_php', true);

        $this->assertTrue($this->h->set_rule('block_uploads_php', false)['success']);
        $this->assertSame(self::FOREIGN, $this->get('uploads'));
    }

    public function test_a_rule_in_the_file_can_be_turned_off_even_when_desired_was_false() {
        // DB restored to before the enable: option says off, file still has the block.
        $this->put('content', $this->h->build_block('content', ['block_archives']) . "\n");

        $result = $this->h->set_rule('block_archives', false);

        $this->assertTrue($result['success']);
        $this->assertSame('off', $result['status']['rules']['block_archives']['state']);
    }

    public function test_enabling_a_rule_that_is_already_on_rewrites_nothing() {
        $this->h->set_rule('block_archives', true);
        $writes = $this->count_htaccess_writes();

        $result = $this->h->set_rule('block_archives', true);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['warnings'], 'a 403 our own block explains is no warning');
        $this->assertSame(0, $writes->n);
    }

    public function test_adoption_replaces_the_manual_block_in_the_same_write() {
        $this->put('content', LSM_Htaccess_Fixtures::MIDNIGHTBLUE_CONTENT);
        $this->assertSame('manual', $this->h->get_status()['rules']['block_archives']['state']);
        $writes = $this->count_htaccess_writes();

        $result = $this->h->set_rule('block_archives', true);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['warnings'], 'the manual block explains the 403 before the write');
        $this->assertSame(1, $writes->n);
        $this->assertSame(
            "<IfModule litespeed>\nphp_value \n</IfModule>\n<Files \"debug.log\">\nRequire all denied\n</Files>\n\n"
                . $this->h->build_block('content', ['block_archives']) . "\n",
            $this->get('content')
        );
        $this->assertSame('on', $result['status']['rules']['block_archives']['state']);
        $this->assertSame('manual', $result['status']['rules']['block_debug_log']['state'], 'only the toggled rule is adopted');
    }

    public function test_adoption_of_the_uploads_block() {
        $this->put('uploads', LSM_Htaccess_Fixtures::AUDITED_UPLOADS);

        $this->assertTrue($this->h->set_rule('block_uploads_php', true)['success']);
        $this->assertSame($this->h->build_block('uploads', ['block_uploads_php']) . "\n", $this->get('uploads'));
    }

    public function test_warning_when_something_else_already_blocks_the_probes() {
        $this->server->foreign_deny = '~\.(zip|wpress)$~';

        $result = $this->h->set_rule('block_archives', true);

        $this->assertTrue($result['success']);
        $this->assertSame(['already_blocked_elsewhere'], $result['warnings']);
        $this->assertSame(['already_blocked_elsewhere'], $result['status']['last_result']['warnings']);
    }

    public function test_baseline_asset_is_skipped_when_the_plugin_lives_outside_wp_content() {
        $this->h->plugin = $this->root . '/elsewhere/landeseiten-maintenance/';

        $this->assertTrue($this->h->set_rule('block_archives', true)['success']);
        $this->assertSame([], $this->server->requests_matching('~ticket-ui\.css~'));
    }

    public function test_a_success_clears_the_sticky_failure_of_that_rule_only() {
        update_option('lsm_hardening', ['rule_failures' => [
            'block_archives'  => ['at' => 1, 'reason' => 'rule_ineffective'],
            'block_debug_log' => ['at' => 1, 'reason' => 'asset_broken'],
        ]]);

        $this->h->set_rule('block_archives', true);

        $this->assertSame(['block_debug_log'], array_keys($this->h->get_state()['rule_failures']));
    }

    // -------------------------------------------------------------------------
    // Rejections: nothing starts, nothing is recorded
    // -------------------------------------------------------------------------

    public function test_invalid_rule() {
        foreach (['block_everything', '', null, ['block_archives']] as $rule) {
            $result = $this->h->set_rule($rule, true);
            $this->assertFalse($result['success']);
            $this->assertSame('invalid_rule', $result['reason']);
        }
        $this->assertFalse(get_option('lsm_hardening'), 'no state written');
        $this->assertSame([], $this->server->requests);
    }

    public function test_unsupported() {
        $this->h->software = 'nginx/1.24.0';

        $result = $this->h->set_rule('block_archives', true);

        $this->assertFalse($result['success']);
        $this->assertSame('unsupported', $result['reason']);
        $this->assertStringContainsString('unknown_server', $result['message']);
        $this->assertSame('unsupported', $result['status']['rules']['block_archives']['state']);
        $this->assertFalse(get_option('lsm_hardening'));
        $this->assertSame([], $this->server->requests);
        $this->assertNull($this->get('content'));
    }

    public function test_an_unreadable_htaccess_is_never_overwritten() {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Running as root: every file is readable.');
        }
        // Writable but not readable. Read as "empty", the candidate would be nothing but our block,
        // the snapshot '' and the rollback would "restore" '' — the foreign rules would be gone.
        $this->put('content', self::FOREIGN);
        chmod($this->htaccess('content'), 0200);

        $result = $this->h->set_rule('block_debug_log', true);

        chmod($this->htaccess('content'), 0644);
        $this->assertFalse($result['success']);
        $this->assertSame('unsupported', $result['reason']);
        $this->assertStringContainsString('not_writable', $result['message']);
        $this->assertSame(self::FOREIGN, $this->get('content'));
        $this->assertSame([], $this->server->requests);
    }

    public function test_busy_changes_nothing_and_leaves_the_other_lock_alone() {
        $previous = ['at' => 5, 'action' => 'enable', 'rule' => 'block_debug_log', 'ok' => true, 'reason' => null, 'warnings' => []];
        update_option('lsm_hardening', ['last_result' => $previous]);
        add_option('lsm_hardening_lock', $this->h->time - 10, '', 'no');

        $result = $this->h->set_rule('block_archives', true);

        $this->assertFalse($result['success']);
        $this->assertSame('busy', $result['reason']);
        $this->assertSame(['last_result' => $previous], get_option('lsm_hardening'), 'state untouched');
        $this->assertSame($this->h->time - 10, get_option('lsm_hardening_lock'), 'lock still held by the other operation');
        $this->assertSame([], $this->server->requests);
        $this->assertSame([], LSM_Test_Env::$log);
    }

    // -------------------------------------------------------------------------
    // Failures before the write: nothing is written
    // -------------------------------------------------------------------------

    public function test_markers_corrupt() {
        $corrupt = "# BEGIN LSM-HARDENING\n<Files \"debug.log\">\n";
        $this->put('content', $corrupt);
        $writes = $this->count_htaccess_writes();

        $result = $this->h->set_rule('block_archives', true);

        $this->assertFailedCleanly($result, 'markers_corrupt', 'block_archives', 'content', $corrupt);
        $this->assertSame(0, $writes->n);
        $this->assertSame([], $this->server->requests);
    }

    public function test_loopback_blocked_when_every_loopback_is_403() {
        // Bot Fight Mode / host WAF: a uniform 403 must never read as "verified".
        $this->server->always('~.~', LSM_Fake_Server::response(403, 'Forbidden'));
        $writes = $this->count_htaccess_writes();

        $result = $this->h->set_rule('block_archives', true);

        $this->assertFailedCleanly($result, 'loopback_blocked', 'block_archives', 'content', null);
        $this->assertSame(0, $writes->n);
    }

    public function test_loopback_blocked_when_the_asset_body_is_empty() {
        $this->server->always('~ticket-ui\.css~', LSM_Fake_Server::response(200, ''));
        $this->assertFailedCleanly($this->h->set_rule('block_debug_log', true), 'loopback_blocked', 'block_debug_log', 'content', null);
    }

    public function test_loopback_blocked_when_the_homepage_cannot_be_fetched() {
        $this->server->always('~^http://example\.test/$~', new WP_Error('http_request_failed', 'cURL error 28'));
        $writes = $this->count_htaccess_writes();

        $result = $this->h->set_rule('block_uploads_php', true);

        $this->assertFailedCleanly($result, 'loopback_blocked', 'block_uploads_php', 'uploads', null);
        $this->assertSame(0, $writes->n);
    }

    public function test_loopback_blocked_when_the_archive_probe_file_is_not_reachable() {
        // home_url resolves to another server (pre-switch DNS): the probe file is not there.
        $this->server->always('~lsm-probe-~', LSM_Fake_Server::response(404, 'Not Found'));
        $writes = $this->count_htaccess_writes();

        $result = $this->h->set_rule('block_archives', true);

        $this->assertFailedCleanly($result, 'loopback_blocked', 'block_archives', 'content', null);
        $this->assertSame(0, $writes->n);
    }

    public function test_snapshot_failed() {
        $this->put('content', self::FOREIGN);
        $htaccess_writes = 0;
        $this->h->put_hook = function ($file) use (&$htaccess_writes) {
            if (basename($file) === '.htaccess.lsm-bak') {
                return false;
            }
            if (basename($file) === '.htaccess') {
                $htaccess_writes++;
            }
            return null;
        };

        $result = $this->h->set_rule('block_archives', true);

        $this->assertFailedCleanly($result, 'snapshot_failed', 'block_archives', 'content', self::FOREIGN);
        $this->assertSame(0, $htaccess_writes);
    }

    public function test_a_file_changed_by_something_else_during_the_self_test_is_never_overwritten() {
        $this->put('content', self::FOREIGN);
        $writes   = $this->count_htaccess_writes();
        $server   = $this->server;
        $htaccess = $this->htaccess('content');
        $appended = false;
        // WebP Express / a cache plugin rewrites wp-content/.htaccess while our "before" probe runs.
        LSM_Test_Env::$http = function ($url, $args) use ($server, $htaccess, &$appended) {
            if (!$appended && strpos($url, '/debug.log') !== false) {
                $appended = true;
                file_put_contents($htaccess, "ErrorDocument 404 /404.html\n", FILE_APPEND);
            }
            return $server->handle($url, $args);
        };

        $result = $this->h->set_rule('block_debug_log', true);

        $this->assertTrue($appended);
        $this->assertFailedCleanly($result, 'write_failed', 'block_debug_log', 'content', self::FOREIGN . "ErrorDocument 404 /404.html\n");
        $this->assertStringContainsString('changed by something else', $result['message']);
        $this->assertSame(0, $writes->n, 'the stale candidate never reached the disk');
    }

    // -------------------------------------------------------------------------
    // Failures after the write: rolled back
    // -------------------------------------------------------------------------

    public function test_snapshot_and_pending_exist_only_while_the_operation_runs() {
        $this->put('content', self::FOREIGN);
        $seen = [];
        $this->h->put_hook = function ($file) use (&$seen) {
            if (basename($file) === '.htaccess') {
                $seen = [
                    'snapshot' => file_get_contents(dirname($file) . '/.htaccess.lsm-bak'),
                    'pending'  => get_option('lsm_hardening')['pending'],
                    'lock'     => get_option('lsm_hardening_lock'),
                ];
            }
            return null;
        };

        $this->h->set_rule('block_archives', true);

        $this->assertSame(self::FOREIGN, $seen['snapshot']);
        $this->assertSame(['target' => 'content', 'op' => 'enable', 'started_at' => $this->h->time, 'existed' => true], $seen['pending']);
        $this->assertSame($this->h->time, $seen['lock']);
        $this->assertFileDoesNotExist($this->content . '/.htaccess.lsm-bak');
    }

    public function test_the_state_is_committed_before_the_snapshot_is_deleted() {
        // A kill between the two may leave a stray snapshot (the next operation removes it), but never
        // a `pending` without a snapshot: recovery could not undo a killed pause then.
        $engine = new class extends LSM_Testable_Hardening {
            /** @var array|null the stored option at the moment the artifacts are deleted */
            public $state_at_cleanup = null;

            public function cleanup_artifacts() {
                $this->state_at_cleanup = get_option('lsm_hardening');
                parent::cleanup_artifacts();
            }
        };
        $engine->content = $this->content;
        $engine->uploads = $this->uploads;
        $engine->plugin  = $this->h->plugin;
        $this->put('content', self::FOREIGN);

        $this->assertTrue($engine->set_rule('block_archives', true)['success']);

        $this->assertIsArray($engine->state_at_cleanup);
        $this->assertNull($engine->state_at_cleanup['pending'], 'pending is cleared before the snapshot goes');
        $this->assertTrue($engine->state_at_cleanup['rules']['block_archives'], 'rules are committed before the snapshot goes');
        $this->assertSame([], $this->artifacts());
    }

    public function test_write_failed_restores_the_original_bytes() {
        $this->put('content', self::FOREIGN);
        $failed = false;
        $this->h->put_hook = function ($file, $content) use (&$failed) {
            if (basename($file) === '.htaccess' && !$failed) {
                $failed = true;
                return file_put_contents($file, substr($content, 0, 60));
            }
            return null;
        };

        $result = $this->h->set_rule('block_archives', true);

        $this->assertFailedCleanly($result, 'write_failed', 'block_archives', 'content', self::FOREIGN);
    }

    public function test_asset_broken_when_the_server_rejects_the_directive() {
        // AllowOverride without AuthConfig: "Require" in .htaccess is a 500 for everything below wp-content.
        $this->server->rejected_directive = 'Require all denied';
        $this->put('content', self::FOREIGN);

        $result = $this->h->set_rule('block_debug_log', true);

        $this->assertFailedCleanly($result, 'asset_broken', 'block_debug_log', 'content', self::FOREIGN);
        $this->assertStringContainsString('500', $result['message']);
        $this->assertStringContainsString('rolled back', $result['message']);
    }

    public function test_asset_broken_when_the_anonymous_homepage_changes() {
        // A rewrite-mode page cache serves "/" from wp-content/cache/: the visitor sees the damage, the asset does not.
        $this->server->script('~^http://example\.test/$~', ['pass', LSM_Fake_Server::response(403, 'Forbidden')]);

        $result = $this->h->set_rule('block_archives', true);

        $this->assertFailedCleanly($result, 'asset_broken', 'block_archives', 'content', null);
        $this->assertStringContainsString('homepage', $result['message']);
    }

    public function test_asset_broken_when_the_homepage_body_goes_empty() {
        $this->server->script('~^http://example\.test/$~', ['pass', LSM_Fake_Server::response(200, '')]);
        $this->assertFailedCleanly($this->h->set_rule('block_archives', true), 'asset_broken', 'block_archives', 'content', null);
    }

    public function test_rule_ineffective_names_the_probe_a_front_end_nginx_still_serves() {
        $this->server->bypass_htaccess = '~\.zip$~';

        $result = $this->h->set_rule('block_archives', true);

        $this->assertFailedCleanly($result, 'rule_ineffective', 'block_archives', 'content', null);
        $this->assertStringContainsString('.zip', $result['message']);
    }

    public function test_rule_ineffective_when_the_server_ignores_htaccess_for_the_php_probe() {
        $this->server->bypass_htaccess = '~\.php$~';
        $this->put('uploads', self::FOREIGN);

        $result = $this->h->set_rule('block_uploads_php', true);

        $this->assertFailedCleanly($result, 'rule_ineffective', 'block_uploads_php', 'uploads', self::FOREIGN);
    }

    public function test_loopback_blocked_after_the_write_is_rolled_back() {
        // Before: 404. After: the uploads directory answers 500 — never "403 effective".
        $this->server->script('~/uploads/lsm-probe-~', ['pass', LSM_Fake_Server::response(500, 'Internal Server Error')]);

        $result = $this->h->set_rule('block_uploads_php', true);

        $this->assertFailedCleanly($result, 'loopback_blocked', 'block_uploads_php', 'uploads', null);
    }

    public function test_a_cloudflare_challenge_403_is_not_an_effective_rule() {
        $challenge = LSM_Fake_Server::response(403, 'Just a moment...', ['cf-mitigated' => 'challenge']);
        $this->server->script('~/uploads/lsm-probe-~', ['pass', $challenge]);

        $result = $this->h->set_rule('block_uploads_php', true);

        $this->assertFailedCleanly($result, 'loopback_blocked', 'block_uploads_php', 'uploads', null);
    }

    public function test_pause_ineffective_foreign_rule_when_turning_archives_off_changes_nothing() {
        $this->h->set_rule('block_archives', true);
        $with_rule = $this->get('content');
        LSM_Test_Env::$log = [];
        $this->server->foreign_deny = '~\.wpress$~';

        $result = $this->h->set_rule('block_archives', false);

        $this->assertFalse($result['success']);
        $this->assertSame('pause_ineffective_foreign_rule', $result['reason']);
        $this->assertSame($with_rule, $this->get('content'), 'rolled back: our rule is still in the file');
        $this->assertTrue($this->h->get_state()['rules']['block_archives'], 'desired state unchanged');
        $this->assertSame('on', $result['status']['rules']['block_archives']['state']);
        $this->assertSame('pause_ineffective_foreign_rule', $result['status']['rules']['block_archives']['last_failure']['reason']);
        $this->assertSame([], $this->artifacts());
    }

    public function test_rollback_failed_when_the_original_cannot_be_restored() {
        $this->put('content', self::FOREIGN);
        $this->server->bypass_htaccess = '~\.zip$~';
        $writes = 0;
        $this->h->put_hook = function ($file) use (&$writes) {
            if (basename($file) !== '.htaccess') {
                return null;
            }
            $writes++;
            // 1 = our block, 2 = the restore (fails), 3 = the last-resort strip.
            return $writes === 2 ? false : null;
        };

        $result = $this->h->set_rule('block_archives', true);

        $this->assertFalse($result['success']);
        $this->assertSame('rollback_failed', $result['reason']);
        $this->assertSame(3, $writes);
        $this->assertStringNotContainsString('LSM-HARDENING', $this->get('content'), 'last resort: the managed block is stripped');
        $this->assertStringContainsString('WebP Express', $this->get('content'));
        $this->assertSame('rollback_failed', $this->h->get_state()['rule_failures']['block_archives']['reason']);
        $this->assertNull($this->h->get_state()['pending']);
        $this->assertFalse(get_option('lsm_hardening_lock'));
    }

    public function test_rollback_failed_when_wp_content_stays_broken_after_the_restore() {
        $this->put('content', self::FOREIGN);
        $broken = LSM_Fake_Server::response(500, 'Internal Server Error');
        $this->server->script('~ticket-ui\.css~', ['pass', $broken, $broken]);

        $result = $this->h->set_rule('block_archives', true);

        $this->assertFalse($result['success']);
        $this->assertSame('rollback_failed', $result['reason']);
        $this->assertSame(self::FOREIGN, $this->get('content'), 'the original bytes are back all the same');
        $this->assertFalse($this->h->get_state()['rules']['block_archives']);
    }
}
```

- [ ] **Step 2: Run and watch it fail**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && vendor/bin/phpunit -c phpunit.xml.dist --filter HardeningApplyTest
```

Expected: FAIL — `Tests: 41, Assertions: 1, Errors: 41.`, each one `Error: Call to undefined method LSM_Testable_Hardening::set_rule()` (for the anonymous test double: `LSM_Testable_Hardening@anonymous::set_rule()`).

- [ ] **Step 3: Implement** — insert above the final `}` of `class LSM_Hardening`, preceded by one blank line:

```php
    // =========================================================================
    // FULL APPLY PROCEDURE
    // =========================================================================

    /**
     * Store the lsm_hardening option (autoloaded: on_init() reads it on every request).
     *
     * @param array $state State.
     */
    private function save_state(array $state) {
        update_option(self::OPTION, $state, true);
    }

    /**
     * Build the top-level shape every REST response has.
     *
     * @param bool        $success  Outcome.
     * @param string|null $reason   Reason code on failure.
     * @param string      $message  Human-readable message.
     * @param array       $warnings Warning codes.
     * @return array
     */
    public function respond($success, $reason, $message, array $warnings = []) {
        return [
            'success'  => (bool) $success,
            'reason'   => $reason,
            'message'  => $message,
            'warnings' => array_values($warnings),
            'status'   => $this->get_status(),
        ];
    }

    /**
     * Turn a rule on (also: adopt a manual block, re-apply after drift) or off.
     *
     * @param string $rule    Rule key.
     * @param bool   $enabled Desired state.
     * @return array respond() shape.
     */
    public function set_rule($rule, $enabled) {
        if (!is_string($rule) || !in_array($rule, self::RULES, true)) {
            return $this->respond(false, 'invalid_rule', 'Unknown hardening rule.');
        }

        $enabled = (bool) $enabled;
        $commit  = ['rules' => [$rule => $enabled]];
        if ($rule === 'block_archives') {
            // Turning the archive rule on or off ends any pause.
            $commit['pause_minutes'] = null;
        }

        return $this->apply($enabled ? 'enable' : 'disable', $rule, $enabled, $commit);
    }

    /**
     * The full safe procedure: preflight, lock, run, finish.
     *
     * A throwable inside execute() is treated like a killed process: lock, `pending`
     * and the snapshot stay where they are and crash recovery cleans up.
     *
     * @param string $action   enable|disable|pause|resume — also pending.op and last_result.action.
     * @param string $rule     Rule the operation is about.
     * @param bool   $in_block Whether the rule must be in the managed block afterwards.
     * @param array  $commit   Committed on success only: ['rules' => [rule => bool]] and/or
     *                         ['pause_minutes' => int|null] (null clears pause_until).
     * @return array respond() shape.
     */
    private function apply($action, $rule, $in_block, array $commit) {
        $unsupported = $this->preflight($rule);
        if ($unsupported !== null) {
            return $this->respond(false, 'unsupported', sprintf('Not supported on this server (%s).', $unsupported));
        }

        if (!$this->acquire_lock()) {
            return $this->respond(false, 'busy', 'Another hardening operation is running on this site. Try again in a moment.');
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $outcome = $this->execute($action, $rule, $in_block);

        return $this->finish($action, $rule, $outcome, $commit);
    }

    /**
     * Steps 2-7 of the procedure. Runs under the lock.
     *
     * @param string $action   Operation.
     * @param string $rule     Rule key.
     * @param bool   $in_block Whether the rule must be in the block afterwards.
     * @return array ['reason' => string|null, 'message' => string, 'warnings' => array]
     */
    private function execute($action, $rule, $in_block) {
        $target  = $this->target_of($rule);
        $current = $this->file_facts($target);

        if ($current['corrupt']) {
            return [
                'reason'   => 'markers_corrupt',
                'message'  => 'The LSM-HARDENING markers in this .htaccess are damaged (a BEGIN without END, or more than one block). Nothing was changed — repair the file by hand.',
                'warnings' => [],
            ];
        }

        // Candidate block: what the file holds right now, plus or minus this rule. The file is
        // the truth — a drifted rule is never re-added as a side effect of touching another one.
        $enabled = [];
        foreach ($this->rules_of($target) as $other) {
            if ($other === $rule ? $in_block : $current['in_block'][$other]) {
                $enabled[] = $other;
            }
        }
        $candidate = $in_block ? $this->strip_manual_blocks($current['content'], $rule) : $current['content'];
        $candidate = $this->replace_block($candidate, $this->build_block($target, $enabled));

        // 2. Baseline before.
        $asset = $this->baseline_asset();
        if (!$this->asset_ok($asset)) {
            return [
                'reason'   => 'loopback_blocked',
                'message'  => sprintf('The site could not fetch its own plugin stylesheet (HTTP %d), so a change could not be verified. Nothing was changed.', $asset['code']),
                'warnings' => [],
            ];
        }
        $home_before = $this->baseline_home();
        if ($home_before['error']) {
            return [
                'reason'   => 'loopback_blocked',
                'message'  => 'The site could not fetch its own homepage, so a change could not be verified. Nothing was changed.',
                'warnings' => [],
            ];
        }

        // 3. Probes before.
        $probes = $this->prepare_probes($rule);
        $before = $this->judge_before($rule, $this->fetch_probes($probes), $current['in_block'][$rule] || $current['manual'][$rule]);
        if ($before['reason'] !== null) {
            return $before;
        }
        $warnings = $before['warnings'];

        // The loopbacks above can take tens of seconds: never write a candidate built from stale bytes.
        $fresh = $this->read_target($target);
        if ($fresh['existed'] !== $current['existed'] || $fresh['content'] !== $current['content']) {
            return [
                'reason'   => 'write_failed',
                'message'  => 'The .htaccess was changed by something else while the self-test was running. Nothing was changed — try again.',
                'warnings' => $warnings,
            ];
        }

        // 4. Snapshot + pending. The original bytes stay in $current for the same-request rollback.
        if (!$this->write_snapshot($target, $current)) {
            return [
                'reason'   => 'snapshot_failed',
                'message'  => 'The backup copy of the .htaccess could not be written and read back. Nothing was changed.',
                'warnings' => $warnings,
            ];
        }
        $state            = $this->get_state();
        $state['pending'] = [
            'target'     => $target,
            'op'         => $action,
            'started_at' => $this->now(),
            'existed'    => $current['existed'],
        ];
        $this->save_state($state);

        // 5. Write, 6. self-test after.
        if (!$this->commit_target($target, $candidate, $current['content'], $current['existed'])) {
            $failure = ['reason' => 'write_failed', 'message' => 'The .htaccess did not read back the way it was written.'];
        } else {
            $failure = $this->self_test_after($rule, $in_block, $probes, $home_before);
        }

        if ($failure['reason'] === null) {
            return ['reason' => null, 'message' => 'Applied and verified', 'warnings' => $warnings];
        }

        // 7. Rollback.
        $failure             = $this->rollback($target, $current, $failure);
        $failure['warnings'] = $warnings;
        return $failure;
    }

    /**
     * Step 4: write .htaccess.lsm-bak beside the file and read it back identical.
     * A file that does not exist has nothing to snapshot (pending.existed covers it).
     *
     * @param string $target  'content' or 'uploads'.
     * @param array  $current Result of file_facts().
     * @return bool
     */
    private function write_snapshot($target, array $current) {
        $snapshot = dirname($this->target_file($target)) . '/' . self::SNAPSHOT_FILE;

        if (!$current['existed']) {
            if (file_exists($snapshot)) {
                @unlink($snapshot);
            }
            return true;
        }

        $this->put_contents($snapshot, $current['content']);
        return @file_get_contents($snapshot) === $current['content'];
    }

    /**
     * Step 6: both baselines again, then the probes.
     *
     * @param string $rule        Rule key.
     * @param bool   $in_block    Whether the rule is in the block that was just written.
     * @param array  $probes      Result of prepare_probes().
     * @param array  $home_before Baseline 2b from before the write.
     * @return array ['reason' => string|null, 'message' => string]
     */
    private function self_test_after($rule, $in_block, array $probes, array $home_before) {
        $asset = $this->baseline_asset();
        if (!$this->asset_ok($asset)) {
            return [
                'reason'  => 'asset_broken',
                'message' => sprintf('After the write the plugin stylesheet below wp-content answered HTTP %d instead of 200.', $asset['code']),
            ];
        }

        $home = $this->baseline_home();
        if ($home['error'] || $home['code'] !== $home_before['code'] || ($home_before['body'] !== '' && $home['body'] === '')) {
            return [
                'reason'  => 'asset_broken',
                'message' => sprintf('After the write the homepage answered HTTP %d (before: %d) for an anonymous visitor.', $home['code'], $home_before['code']),
            ];
        }

        return $this->judge_after($rule, $in_block, $this->fetch_probes($probes));
    }

    /**
     * Step 7: restore the original bytes (or delete a file that did not exist) and
     * re-check baseline 2a.
     *
     * @param string $target  'content' or 'uploads'.
     * @param array  $current Result of file_facts() from before the write.
     * @param array  $failure ['reason', 'message'] that triggered the rollback.
     * @return array ['reason', 'message'] — the original failure, or rollback_failed.
     */
    private function rollback($target, array $current, array $failure) {
        if (!$this->restore_target($target, $current['content'], $current['existed'])) {
            // Last resort: whatever is in the file now, at least take our block out of it.
            $this->strip_managed_block($target);
            return [
                'reason'  => 'rollback_failed',
                'message' => $failure['message'] . ' The original .htaccess could not be restored; the managed block was stripped instead. Check the file by hand.',
            ];
        }

        if (!$this->asset_ok($this->baseline_asset())) {
            return [
                'reason'  => 'rollback_failed',
                'message' => $failure['message'] . ' The original .htaccess was restored, but files below wp-content still do not load. Check the site now.',
            ];
        }

        $failure['message'] .= ' The change was rolled back.';
        return $failure;
    }

    /**
     * Remove the managed block from a target file, best effort.
     *
     * @param string $target 'content' or 'uploads'.
     */
    private function strip_managed_block($target) {
        $current  = $this->read_target($target);
        $stripped = $this->replace_block($current['content'], '');
        if ($stripped !== null && $stripped !== $current['content']) {
            $this->put_contents($this->target_file($target), $stripped);
        }
    }

    /**
     * Step 8, both paths: commit on success, clear pending, write last_result, then delete the
     * snapshot and the probe files, release the lock, log.
     *
     * The state is saved BEFORE the artifacts are deleted: a kill in between then leaves a stray
     * snapshot (harmless, the next operation removes it) instead of a `pending` without a
     * snapshot, which crash recovery could not undo.
     *
     * @param string $action  last_result.action.
     * @param string $rule    Rule key.
     * @param array  $outcome ['reason' => string|null, 'message' => string, 'warnings' => array]
     * @param array  $commit  See apply().
     * @return array respond() shape.
     */
    private function finish($action, $rule, array $outcome, array $commit) {
        $ok    = $outcome['reason'] === null;
        $state = $this->get_state();

        if ($ok) {
            if (isset($commit['rules'])) {
                foreach ($commit['rules'] as $key => $value) {
                    $state['rules'][$key] = $value;
                }
            }
            if (array_key_exists('pause_minutes', $commit)) {
                $state['pause_until'] = $commit['pause_minutes'] === null ? null : $this->now() + $commit['pause_minutes'] * 60;
            }
            unset($state['rule_failures'][$rule]);
        } else {
            $state['rule_failures'][$rule] = ['at' => $this->now(), 'reason' => $outcome['reason']];
        }

        $state['pending']     = null;
        $state['last_result'] = [
            'at'       => $this->now(),
            'action'   => $action,
            'rule'     => $rule,
            'ok'       => $ok,
            'reason'   => $outcome['reason'],
            'warnings' => array_values($outcome['warnings']),
        ];
        $this->save_state($state);
        $this->cleanup_artifacts();
        $this->release_lock();

        LSM_Logger::log($ok ? 'hardening_applied' : 'hardening_failed', $ok ? 'success' : 'error', [
            'action'   => $action,
            'rule'     => $rule,
            'reason'   => $outcome['reason'],
            'warnings' => $outcome['warnings'],
        ]);

        return $this->respond($ok, $outcome['reason'], $outcome['message'], $outcome['warnings']);
    }
```

- [ ] **Step 4: Lint and run the whole suite**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && php -l landeseiten-maintenance/includes/class-lsm-hardening.php && vendor/bin/phpunit -c phpunit.xml.dist
```

Expected: `No syntax errors detected`, then PASS — `OK (139 tests, 774 assertions)`.

- [ ] **Step 5: Commit**

```bash
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening add landeseiten-maintenance/includes/class-lsm-hardening.php tests/HardeningApplyTest.php
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening commit -m "$(cat <<'EOF'
feat(hardening): add the full apply procedure with self-test and rollback

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
EOF
)"
```

---

### Task 9: Pause, pause-while-paused, resume

`pause(minutes)` needs `block_archives` in state `on` (else `not_enabled`; `manual` must be adopted first) and runs the full apply with the archive rule left out of the block — the desired state stays `true`. Pausing while already paused only moves `pause_until` to `now + minutes*60` (no file change, no HTTP — but under the lock). A failed pause leaves the whole previous state, because nothing is committed before the self-test passed. `resume()` is a full apply that re-adds the rule and an idempotent no-op returning fresh status when nothing is paused — the platform's backstop POSTs it blindly.

**Files:**
- Modify: `landeseiten-maintenance/includes/class-lsm-hardening.php` (insert above the final `}`)
- Test: `tests/HardeningPauseTest.php`

**Interfaces:**
- Consumes: `apply($action, $rule, $in_block, array $commit)`, `respond(...)`, `save_state(array $state)` (Task 8); `rule_statuses()`, `get_state()` (Task 5); `acquire_lock()`, `release_lock()` (Task 6); constant `PAUSE_MINUTES` (Task 2); test helper `put_an_edge_cache_in_front()` (Task 2).
- Produces: `public function pause($minutes)` → `respond()` shape — `$minutes` must be an **int** out of `[15, 30, 60]` (the REST callback casts); `public function resume()` → `respond()` shape; `private function move_pause($minutes)`.

- [ ] **Step 1: Write the failing test** — `W/tests/HardeningPauseTest.php`:

```php
<?php

/**
 * pause(), pause while paused, failed pause, resume().
 */
class HardeningPauseTest extends HardeningTestCase {

    protected function setUp(): void {
        parent::setUp();
        $this->h->set_rule('block_archives', true);
        $this->h->set_rule('block_debug_log', true);
        $this->server->requests = [];
        LSM_Test_Env::$log      = [];
    }

    private function htaccess_writes() {
        $counter = new stdClass();
        $counter->n = 0;
        $this->h->put_hook = function ($file) use ($counter) {
            if (basename($file) === '.htaccess') {
                $counter->n++;
            }
            return null;
        };
        return $counter;
    }

    public function test_invalid_minutes() {
        foreach ([0, 45, 61, -15, '60', 60.0, null] as $minutes) {
            $result = $this->h->pause($minutes);
            $this->assertFalse($result['success']);
            $this->assertSame('invalid_minutes', $result['reason']);
        }
        $this->assertNull($this->h->get_state()['pause_until']);
        $this->assertSame([], $this->server->requests);
    }

    public function test_pause_takes_only_the_archive_rule_out_and_keeps_it_desired() {
        $seen_op = null;
        $this->h->put_hook = function ($file) use (&$seen_op) {
            if (basename($file) === '.htaccess') {
                $seen_op = get_option('lsm_hardening')['pending']['op'];
            }
            return null;
        };

        $result = $this->h->pause(60);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['warnings']);
        $this->assertSame('pause', $seen_op);
        $this->assertSame($this->h->build_block('content', ['block_debug_log']) . "\n", $this->get('content'));

        $state = $this->h->get_state();
        $this->assertTrue($state['rules']['block_archives'], 'desired stays true');
        $this->assertSame($this->h->time + 3600, $state['pause_until']);

        $this->assertSame('paused', $result['status']['rules']['block_archives']['state']);
        $this->assertSame('on', $result['status']['rules']['block_debug_log']['state']);
        $this->assertSame($this->h->time + 3600, $result['status']['pause_until']);
        $this->assertFalse($result['status']['pause_overdue']);
        $this->assertSame('pause', $result['status']['last_result']['action']);
        $this->assertSame([], $this->artifacts());
    }

    public function test_each_allowed_duration() {
        foreach ([15 => 900, 30 => 1800] as $minutes => $seconds) {
            $this->assertTrue($this->h->pause($minutes)['success']);
            $this->assertSame($this->h->time + $seconds, $this->h->get_state()['pause_until']);
            $this->h->resume();
        }
    }

    public function test_pause_requires_the_rule_to_be_on() {
        $this->h->set_rule('block_archives', false);

        $result = $this->h->pause(30);
        $this->assertSame('not_enabled', $result['reason']);

        // manual: a hand-written block cannot be paused, it has to be adopted first.
        $this->put('content', LSM_Htaccess_Fixtures::MIDNIGHTBLUE_CONTENT);
        $this->assertSame('manual', $this->h->get_status()['rules']['block_archives']['state']);
        $this->assertSame('not_enabled', $this->h->pause(30)['reason']);

        // drift: desired, but not in the file.
        $this->put('content', '');
        update_option('lsm_hardening', ['rules' => ['block_archives' => true]]);
        $this->assertSame('not_enabled', $this->h->pause(30)['reason']);

        $this->assertNull($this->h->get_state()['pause_until']);
    }

    public function test_pause_on_an_unsupported_server() {
        $this->h->software = '';
        $result = $this->h->pause(15);
        $this->assertSame('unsupported', $result['reason']);
        $this->assertStringContainsString('unknown_server', $result['message']);
    }

    public function test_pausing_while_paused_only_moves_pause_until() {
        $this->h->pause(60);
        $paused_file = $this->get('content');
        $this->server->requests = [];
        $writes = $this->htaccess_writes();
        $this->h->time += 600;

        $result = $this->h->pause(15);

        $this->assertTrue($result['success']);
        $this->assertSame($this->h->time + 900, $this->h->get_state()['pause_until'], 'now + minutes, even when that is earlier than before');
        $this->assertSame(0, $writes->n);
        $this->assertSame($paused_file, $this->get('content'));
        $this->assertSame([], $this->server->requests);
        $this->assertSame('paused', $result['status']['rules']['block_archives']['state']);
        $this->assertSame('pause', $result['status']['last_result']['action']);
        $this->assertFalse(get_option('lsm_hardening_lock'));
    }

    public function test_pausing_while_paused_respects_the_lock() {
        $this->h->pause(60);
        $until = $this->h->get_state()['pause_until'];
        add_option('lsm_hardening_lock', $this->h->time, '', 'no');

        $result = $this->h->pause(15);

        $this->assertSame('busy', $result['reason']);
        $this->assertSame($until, $this->h->get_state()['pause_until']);
    }

    public function test_a_failed_pause_restores_the_whole_previous_state() {
        $with_rule = $this->get('content');
        $before    = $this->h->get_state();
        $this->server->foreign_deny = '~\.wpress$~';

        $result = $this->h->pause(60);

        $this->assertFalse($result['success']);
        $this->assertSame('pause_ineffective_foreign_rule', $result['reason']);
        $this->assertSame($with_rule, $this->get('content'), 'the archive rule is back in the file');

        $after = $this->h->get_state();
        $this->assertNull($after['pause_until'], 'pause_until as before');
        $this->assertSame($before['rules'], $after['rules']);
        $this->assertNull($after['pending']);
        $this->assertSame('on', $result['status']['rules']['block_archives']['state']);
        $this->assertSame('pause', $result['status']['last_result']['action']);
        $this->assertFalse($result['status']['last_result']['ok']);
        $this->assertSame([], $this->artifacts());
    }

    public function test_a_failed_pause_while_the_site_breaks_is_rolled_back_too() {
        $with_rule = $this->get('content');
        $this->server->script('~^http://example\.test/$~', ['pass', LSM_Fake_Server::response(500, 'Internal Server Error')]);

        $result = $this->h->pause(30);

        $this->assertSame('asset_broken', $result['reason']);
        $this->assertSame($with_rule, $this->get('content'));
        $this->assertNull($this->h->get_state()['pause_until']);
    }

    public function test_resume_is_an_idempotent_no_op_when_nothing_is_paused() {
        $before = get_option('lsm_hardening');

        $result = $this->h->resume();

        $this->assertTrue($result['success']);
        $this->assertNull($result['reason']);
        $this->assertSame('on', $result['status']['rules']['block_archives']['state']);
        $this->assertSame($before, get_option('lsm_hardening'));
        $this->assertSame([], $this->server->requests);
    }

    public function test_resume_puts_the_rule_back_and_clears_the_pause() {
        $this->h->pause(60);

        $result = $this->h->resume();

        $this->assertTrue($result['success']);
        $this->assertSame($this->h->build_block('content', ['block_archives', 'block_debug_log']) . "\n", $this->get('content'));
        $this->assertNull($this->h->get_state()['pause_until']);
        $this->assertSame('on', $result['status']['rules']['block_archives']['state']);
        $this->assertSame('resume', $result['status']['last_result']['action']);
        $this->assertNull($result['status']['pause_until']);
    }

    public function test_an_edge_cache_cannot_fake_a_failed_resume() {
        // During the pause the "before" fetch of the fresh .zip probe is a 200 and lands in the edge
        // cache. Were the "after" fetch to use the same URL, "Re-enable now" and the platform backstop
        // would fail with rule_ineffective on every Cloudflare-proxied site and the rule would stay out.
        $this->h->pause(60);
        $edge = $this->put_an_edge_cache_in_front();

        $result = $this->h->resume();

        $this->assertTrue($result['success'], (string) $result['reason']);
        $this->assertCount(1, $edge, 'the edge did cache the "before" 200 of the .zip probe');
        $this->assertSame('on', $result['status']['rules']['block_archives']['state']);
        $this->assertNull($this->h->get_state()['pause_until']);
    }

    public function test_a_failed_resume_stays_paused_and_turns_overdue() {
        $this->h->pause(15);
        $paused_file = $this->get('content');
        $until       = $this->h->get_state()['pause_until'];
        $this->server->bypass_htaccess = '~\.zip$~';

        $result = $this->h->resume();

        $this->assertSame('rule_ineffective', $result['reason']);
        $this->assertSame($paused_file, $this->get('content'));
        $this->assertSame($until, $this->h->get_state()['pause_until']);
        $this->assertSame('paused', $result['status']['rules']['block_archives']['state']);

        $this->h->time = $until + 1;
        $this->assertTrue($this->h->get_status()['pause_overdue']);
    }

    public function test_turning_the_rule_on_or_off_ends_a_pause() {
        $this->h->pause(60);
        $this->assertTrue($this->h->set_rule('block_archives', true)['success']);
        $this->assertNull($this->h->get_state()['pause_until']);
        $this->assertSame('on', $this->h->get_status()['rules']['block_archives']['state']);

        $this->h->pause(60);
        $result = $this->h->set_rule('block_archives', false);
        $this->assertTrue($result['success']);
        $this->assertNull($this->h->get_state()['pause_until']);
        $this->assertSame('off', $result['status']['rules']['block_archives']['state']);
    }
}
```

- [ ] **Step 2: Run and watch it fail**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && vendor/bin/phpunit -c phpunit.xml.dist --filter HardeningPauseTest
```

Expected: FAIL — `Tests: 14, Assertions: 0, Errors: 14.`, each one `Error: Call to undefined method LSM_Testable_Hardening::pause()` (or `resume()`).

- [ ] **Step 3: Implement** — insert above the final `}` of `class LSM_Hardening`, preceded by one blank line:

```php
    // =========================================================================
    // PAUSE AND RESUME
    // =========================================================================

    /**
     * Take the archive rule out of the file for a download.
     *
     * @param int $minutes 15, 30 or 60.
     * @return array respond() shape.
     */
    public function pause($minutes) {
        if (!is_int($minutes) || !in_array($minutes, self::PAUSE_MINUTES, true)) {
            return $this->respond(false, 'invalid_minutes', 'The pause must be 15, 30 or 60 minutes.');
        }

        $statuses = $this->rule_statuses();
        $archives = $statuses['block_archives'];

        if ($archives['state'] === 'unsupported') {
            return $this->respond(false, 'unsupported', sprintf('Not supported on this server (%s).', $archives['unsupported_reason']));
        }

        if ($archives['state'] === 'paused') {
            return $this->move_pause($minutes);
        }

        if ($archives['state'] !== 'on') {
            return $this->respond(false, 'not_enabled', 'The archive rule is not on, so there is nothing to pause.');
        }

        // Desired state stays true: the pause only leaves the rule out of the block.
        return $this->apply('pause', 'block_archives', false, ['pause_minutes' => $minutes]);
    }

    /**
     * Pausing while already paused only moves pause_until. No file change, no self-test.
     *
     * @param int $minutes 15, 30 or 60.
     * @return array respond() shape.
     */
    private function move_pause($minutes) {
        if (!$this->acquire_lock()) {
            return $this->respond(false, 'busy', 'Another hardening operation is running on this site. Try again in a moment.');
        }

        $state                = $this->get_state();
        $state['pause_until'] = $this->now() + $minutes * 60;
        $state['last_result'] = [
            'at'       => $this->now(),
            'action'   => 'pause',
            'rule'     => 'block_archives',
            'ok'       => true,
            'reason'   => null,
            'warnings' => [],
        ];
        $this->save_state($state);
        $this->release_lock();

        LSM_Logger::log('hardening_applied', 'success', ['action' => 'pause', 'rule' => 'block_archives', 'moved' => true]);

        return $this->respond(true, null, sprintf('Already paused — the pause now ends in %d minutes.', $minutes));
    }

    /**
     * Put the archive rule back now. Idempotent: a no-op when nothing is paused.
     *
     * @return array respond() shape.
     */
    public function resume() {
        $state = $this->get_state();
        if ($state['pause_until'] === null) {
            return $this->respond(true, null, 'Nothing is paused.');
        }

        return $this->apply('resume', 'block_archives', true, ['pause_minutes' => null]);
    }
```

- [ ] **Step 4: Lint and run the whole suite**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && php -l landeseiten-maintenance/includes/class-lsm-hardening.php && vendor/bin/phpunit -c phpunit.xml.dist
```

Expected: `No syntax errors detected`, then PASS — `OK (153 tests, 862 assertions)`.

- [ ] **Step 5: Commit**

```bash
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening add landeseiten-maintenance/includes/class-lsm-hardening.php tests/HardeningPauseTest.php
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening commit -m "$(cat <<'EOF'
feat(hardening): add pause, pause-while-paused and idempotent resume

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
EOF
)"
```

---

### Task 10: Auto-resume (light path)

On `init` the plugin does one cheap compare on the autoloaded option: `pause_until` set and `< now`, not CLI, not a request to `lsm/v1/hardening/*`, `last_attempt_at` older than 300 s. Only then it registers a `shutdown` callback at priority 9999 that first flushes the response (`fastcgi_finish_request()` / `litespeed_finish_request()`) and then: lock (busy → return silently, nothing recorded) → `last_attempt_at` → baseline 2a before → re-read the file (changed by something else since the candidate was built → `write_failed`, nothing written, retried after the throttle) → snapshot + `pending(op=resume)` → write → read back → baseline 2a after. No probes. It **fails closed**: the identical block was verified on this host when it was enabled, so it is rolled back **only** when 2a went from 200 to non-200; when the loopback itself does not work (no answer, WAF 403, plugin outside wp-content) the block stays and the result carries the warning `unverified`. `pause_until` is cleared only after the read-back shows the rule in the block; a failed attempt leaves it in the past (`paused` + `pause_overdue`), and the next eligible request and the platform backstop retry.

**Files:**
- Modify: `landeseiten-maintenance/includes/class-lsm-hardening.php` (insert above the final `}`)
- Test: `tests/HardeningAutoResumeTest.php`

**Interfaces:**
- Consumes: `finish($action, $rule, array $outcome, array $commit)`, `save_state`, `write_snapshot($target, array $current)`, `rollback($target, array $current, array $failure)` (Task 8); `baseline_asset()`, `asset_ok($asset)` (Task 7); `file_facts`, `get_state` (Task 5); `acquire_lock`, `release_lock` (Task 6); `replace_block`, `commit_target`, `read_target` (Task 3); `build_block`, `rules_of`, `now`, constant `RESUME_THROTTLE` (Task 2); fake `add_action()` recording into `LSM_Test_Env::$actions`; `$this->h->cli`, `$this->h->finished` (Task 2 test double).
- Produces:
  - `protected function is_cli()` → bool (seam, `PHP_SAPI === 'cli'`); `protected function finish_request()` (seam).
  - `public function on_init()` — the single load-time entry point (Task 11 extends it, Task 14 hooks it).
  - `public function run_auto_resume()` — the `shutdown` callback; `public function auto_resume()` — the light path itself.
  - `last_result.action` = `auto_resume`; pending op = `resume`.

- [ ] **Step 1: Write the failing test** — `W/tests/HardeningAutoResumeTest.php`:

```php
<?php

/**
 * Auto-resume: cheap detection on init, the light path on shutdown, fail closed.
 */
class HardeningAutoResumeTest extends HardeningTestCase {

    /** @var string content of wp-content/.htaccess while paused */
    private $paused_file;

    /** @var int */
    private $until;

    protected function setUp(): void {
        parent::setUp();
        $this->h->set_rule('block_archives', true);
        $this->h->set_rule('block_debug_log', true);
        $this->h->pause(15);

        $this->paused_file = $this->get('content');
        $this->until       = $this->h->get_state()['pause_until'];

        $this->server->requests = [];
        LSM_Test_Env::$log      = [];
        LSM_Test_Env::$actions  = [];
    }

    private function expire() {
        $this->h->time = $this->until + 1;
    }

    private function assertStillPausedAndOverdue() {
        $this->assertSame($this->until, $this->h->get_state()['pause_until'], 'pause_until stays in the past');
        $status = $this->h->get_status();
        $this->assertSame('paused', $status['rules']['block_archives']['state']);
        $this->assertTrue($status['pause_overdue']);
    }

    // -------------------------------------------------------------------------
    // Detection on init
    // -------------------------------------------------------------------------

    public function test_init_registers_a_late_shutdown_callback_once_the_pause_is_overdue() {
        $this->expire();

        $this->h->on_init();

        $this->assertSame([['shutdown', [$this->h, 'run_auto_resume'], 9999]], LSM_Test_Env::$actions);
        $this->assertSame([], $this->server->requests, 'nothing runs inline');
        $this->assertSame($this->paused_file, $this->get('content'));
    }

    public function test_init_does_nothing_while_the_pause_is_running_or_absent() {
        $this->h->on_init();
        $this->h->time = $this->until;
        $this->h->on_init();
        $this->assertSame([], LSM_Test_Env::$actions, 'pause_until must be < now');

        $this->h->resume();
        $this->expire();
        $this->h->on_init();
        $this->assertSame([], LSM_Test_Env::$actions, 'nothing paused');
    }

    public function test_init_never_registers_in_cli() {
        $this->expire();
        $this->h->cli = true;
        $this->h->on_init();
        $this->assertSame([], LSM_Test_Env::$actions);
    }

    public function test_init_never_registers_on_our_own_hardening_routes() {
        $this->expire();

        $_SERVER['REQUEST_URI'] = '/wp-json/lsm/v1/hardening/resume';
        $this->h->on_init();
        unset($_SERVER['REQUEST_URI']);

        $_GET['rest_route'] = '/lsm/v1/hardening/status';
        $this->h->on_init();
        unset($_GET['rest_route']);

        $this->assertSame([], LSM_Test_Env::$actions);

        $_SERVER['REQUEST_URI'] = '/wp-json/lsm/v1/health';
        $this->h->on_init();
        $this->assertCount(1, LSM_Test_Env::$actions, 'other LSM routes (the uptime ping) do trigger it');
    }

    public function test_attempts_are_throttled_to_one_per_300_seconds() {
        $this->expire();
        // A failed attempt: the write never reaches the disk.
        $this->h->put_hook = function ($file, $content) {
            return basename($file) === '.htaccess' ? strlen($content) : null;
        };
        $this->h->auto_resume();
        $this->h->put_hook = null;
        $this->assertSame($this->h->time, $this->h->get_state()['last_attempt_at']);

        $this->h->on_init();
        $this->h->time += 300;
        $this->h->on_init();
        $this->assertSame([], LSM_Test_Env::$actions, '300 s later is still too early');

        $this->h->time += 1;
        $this->h->on_init();
        $this->assertCount(1, LSM_Test_Env::$actions);
    }

    // -------------------------------------------------------------------------
    // The light path
    // -------------------------------------------------------------------------

    public function test_shutdown_callback_answers_the_client_before_it_works() {
        $this->expire();
        $order = [];
        $this->h->put_hook = function ($file) use (&$order) {
            $order[] = 'write while finished=' . $this->h->finished;
            return null;
        };

        $this->h->run_auto_resume();

        $this->assertSame(1, $this->h->finished);
        $this->assertSame('write while finished=1', $order[0]);
        $this->assertNull($this->h->get_state()['pause_until']);
    }

    public function test_light_path_puts_the_rule_back_with_two_loopbacks_and_no_probes() {
        $this->expire();
        $seen_pending = null;
        $this->h->put_hook = function ($file) use (&$seen_pending) {
            if (basename($file) === '.htaccess') {
                $seen_pending = get_option('lsm_hardening')['pending'];
            }
            return null;
        };

        $this->h->auto_resume();

        $this->assertSame($this->h->build_block('content', ['block_archives', 'block_debug_log']) . "\n", $this->get('content'));
        $this->assertSame(['target' => 'content', 'op' => 'resume', 'started_at' => $this->h->time, 'existed' => true], $seen_pending);

        $state = $this->h->get_state();
        $this->assertNull($state['pause_until']);
        $this->assertNull($state['pending']);
        $this->assertSame($this->h->time, $state['last_attempt_at']);
        $this->assertSame(
            ['at' => $this->h->time, 'action' => 'auto_resume', 'rule' => 'block_archives', 'ok' => true, 'reason' => null, 'warnings' => []],
            $state['last_result']
        );
        $this->assertSame('on', $this->h->get_status()['rules']['block_archives']['state']);

        $this->assertCount(2, $this->server->requests);
        $this->assertCount(2, $this->server->requests_matching('~ticket-ui\.css~'));
        $this->assertSame([], $this->artifacts());
        $this->assertFalse(get_option('lsm_hardening_lock'));
        $this->assertSame('hardening_applied', LSM_Test_Env::$log[0][0]);
    }

    private function assertBlockKeptUnverified() {
        $state = $this->h->get_state();
        $this->assertSame($this->h->build_block('content', ['block_archives', 'block_debug_log']) . "\n", $this->get('content'), 'the block is kept');
        $this->assertNull($state['pause_until']);
        $this->assertTrue($state['last_result']['ok']);
        $this->assertSame(['unverified'], $state['last_result']['warnings']);
        $this->assertSame('on', $this->h->get_status()['rules']['block_archives']['state']);
    }

    public function test_fail_closed_when_the_loopback_gets_no_answer_before_the_write() {
        $this->expire();
        $this->server->script('~ticket-ui\.css~', [new WP_Error('http_request_failed', 'cURL error 28')]);
        $this->h->auto_resume();
        $this->assertBlockKeptUnverified();
    }

    public function test_fail_closed_when_a_waf_answers_every_loopback_with_403() {
        $this->expire();
        $this->server->always('~.~', LSM_Fake_Server::response(403, 'Forbidden'));
        $this->h->auto_resume();
        $this->assertBlockKeptUnverified();
    }

    public function test_fail_closed_when_the_loopback_gets_no_answer_after_the_write() {
        $this->expire();
        $this->server->script('~ticket-ui\.css~', ['pass', new WP_Error('http_request_failed', 'cURL error 28')]);
        $this->h->auto_resume();
        $this->assertBlockKeptUnverified();
    }

    public function test_unverified_when_the_plugin_lives_outside_wp_content() {
        $this->expire();
        $this->h->plugin = $this->root . '/elsewhere/landeseiten-maintenance/';

        $this->h->auto_resume();

        $this->assertNull($this->h->get_state()['pause_until']);
        $this->assertSame(['unverified'], $this->h->get_state()['last_result']['warnings']);
        $this->assertSame([], $this->server->requests);
    }

    public function test_rolls_back_only_when_the_stylesheet_goes_from_200_to_non_200() {
        $this->expire();
        $this->server->rejected_directive = 'Require all denied';
        // The paused file still holds the debug.log rule, so take "Require" out of it for a clean 200 before.
        $this->put('content', "Options -Indexes\n");

        $this->h->auto_resume();

        $this->assertSame("Options -Indexes\n", $this->get('content'), 'rolled back to the paused file');
        $this->assertStillPausedAndOverdue();

        $state = $this->h->get_state();
        $this->assertFalse($state['last_result']['ok']);
        $this->assertSame('auto_resume', $state['last_result']['action']);
        $this->assertSame('asset_broken', $state['last_result']['reason']);
        $this->assertSame('asset_broken', $state['rule_failures']['block_archives']['reason']);
        $this->assertNull($state['pending']);
        $this->assertSame([], $this->artifacts());
        $this->assertFalse(get_option('lsm_hardening_lock'));
    }

    public function test_pause_until_is_only_cleared_after_the_read_back() {
        $this->expire();
        // The write "succeeds" but nothing reaches the disk.
        $this->h->put_hook = function ($file, $content) {
            return basename($file) === '.htaccess' ? strlen($content) : null;
        };

        $this->h->auto_resume();

        $this->assertSame($this->paused_file, $this->get('content'));
        $this->assertStillPausedAndOverdue();
        $this->assertSame('write_failed', $this->h->get_state()['last_result']['reason']);
    }

    public function test_corrupt_markers_leave_the_pause_overdue() {
        $this->expire();
        $this->put('content', "# BEGIN LSM-HARDENING\n");

        $this->h->auto_resume();

        $this->assertSame("# BEGIN LSM-HARDENING\n", $this->get('content'));
        $this->assertStillPausedAndOverdue();
        $this->assertSame('markers_corrupt', $this->h->get_state()['last_result']['reason']);
    }

    public function test_a_file_changed_while_the_stylesheet_was_fetched_is_never_overwritten() {
        $this->expire();
        $server   = $this->server;
        $htaccess = $this->htaccess('content');
        $appended = false;
        // Something else rewrites wp-content/.htaccess while our "before" loopback runs.
        LSM_Test_Env::$http = function ($url, $args) use ($server, $htaccess, &$appended) {
            if (!$appended) {
                $appended = true;
                file_put_contents($htaccess, "ErrorDocument 404 /404.html\n", FILE_APPEND);
            }
            return $server->handle($url, $args);
        };

        $this->h->auto_resume();

        $this->assertTrue($appended);
        $this->assertSame($this->paused_file . "ErrorDocument 404 /404.html\n", $this->get('content'), 'the stale candidate never reached the disk');
        $this->assertStillPausedAndOverdue();
        $this->assertSame('write_failed', $this->h->get_state()['last_result']['reason']);
        $this->assertNull($this->h->get_state()['pending']);
        $this->assertSame([], $this->artifacts());
        $this->assertFalse(get_option('lsm_hardening_lock'));

        // The next attempt starts from the fresh bytes and keeps the foreign line.
        $this->h->auto_resume();
        $this->assertNull($this->h->get_state()['pause_until']);
        $this->assertStringContainsString("ErrorDocument 404 /404.html\n", $this->get('content'));
        $this->assertSame('on', $this->h->get_status()['rules']['block_archives']['state']);
    }

    public function test_busy_returns_silently() {
        $this->expire();
        $before = get_option('lsm_hardening');
        add_option('lsm_hardening_lock', $this->h->time, '', 'no');

        $this->h->auto_resume();

        $this->assertSame($before, get_option('lsm_hardening'), 'no last_attempt_at, no last_result');
        $this->assertSame([], $this->server->requests);
        $this->assertSame([], LSM_Test_Env::$log);
        $this->assertSame($this->h->time, get_option('lsm_hardening_lock'));
    }

    public function test_nothing_happens_when_the_pause_ended_between_init_and_shutdown() {
        $this->expire();
        $this->h->on_init();
        $this->h->resume();
        $this->server->requests = [];
        $last_result = $this->h->get_state()['last_result'];

        $this->h->run_auto_resume();

        $this->assertSame($last_result, $this->h->get_state()['last_result']);
        $this->assertSame([], $this->server->requests);
        $this->assertFalse(get_option('lsm_hardening_lock'));
    }

    public function test_a_rule_somebody_already_put_back_just_clears_the_pause() {
        $this->expire();
        $this->put('content', $this->h->build_block('content', ['block_archives', 'block_debug_log']) . "\n");
        $writes = 0;
        $this->h->put_hook = function ($file) use (&$writes) {
            if (basename($file) === '.htaccess') {
                $writes++;
            }
            return null;
        };

        $this->h->auto_resume();

        $this->assertSame(0, $writes);
        $this->assertNull($this->h->get_state()['pause_until']);
    }
}
```

- [ ] **Step 2: Run and watch it fail**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && vendor/bin/phpunit -c phpunit.xml.dist --filter HardeningAutoResumeTest
```

Expected: FAIL — `Tests: 18, Assertions: 0, Errors: 18.`, each one `Error: Call to undefined method LSM_Testable_Hardening::on_init()` (or `auto_resume()`, `run_auto_resume()`).

- [ ] **Step 3: Implement** — insert above the final `}` of `class LSM_Hardening`, preceded by one blank line:

```php
    // =========================================================================
    // AUTO-RESUME (LIGHT PATH)
    // =========================================================================

    /**
     * Are we running under WP-CLI / CLI cron?
     *
     * @return bool
     */
    protected function is_cli() {
        return PHP_SAPI === 'cli';
    }

    /**
     * Flush the response to the client so the work after it costs the visitor nothing.
     */
    protected function finish_request() {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
    }

    /**
     * Is this a request to one of our own lsm/v1/hardening/* routes?
     *
     * @return bool
     */
    private function is_hardening_request() {
        $checks = [
            $_SERVER['REQUEST_URI'] ?? '',
            $_GET['rest_route'] ?? '',
            $_SERVER['PATH_INFO'] ?? '',
            $_SERVER['REDIRECT_URL'] ?? '',
        ];
        foreach ($checks as $value) {
            if (strpos(urldecode((string) $value), '/lsm/v1/hardening') !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Load-time work, hooked on `init`. One cheap compare on the autoloaded option;
     * the actual re-apply runs on `shutdown`, never inline in a visitor or uptime request.
     */
    public function on_init() {
        $state = $this->get_state();

        $overdue = $state['pause_until'] !== null && $state['pause_until'] < $this->now();
        if (!$overdue || $this->is_cli() || $this->is_hardening_request()) {
            return;
        }

        $last_attempt = (int) $state['last_attempt_at'];
        if ($this->now() - $last_attempt > self::RESUME_THROTTLE) {
            add_action('shutdown', [$this, 'run_auto_resume'], 9999);
        }
    }

    /**
     * Shutdown callback: answer the client first, then put the archive rule back.
     */
    public function run_auto_resume() {
        $this->finish_request();
        $this->auto_resume();
    }

    /**
     * Put the archive rule back after an expired pause. Fails closed: the block was
     * verified on this host when it was enabled, so it is only rolled back when the
     * plugin stylesheet goes from 200 to non-200. pause_until is cleared only after
     * the read-back shows the rule in the block; a failed attempt leaves it in the
     * past (paused + overdue) and is retried after RESUME_THROTTLE seconds.
     */
    public function auto_resume() {
        if (!$this->acquire_lock()) {
            return;
        }

        $state = $this->get_state();
        if ($state['pause_until'] === null || $state['pause_until'] >= $this->now()) {
            // Resumed or moved between init and shutdown.
            $this->release_lock();
            return;
        }

        $state['last_attempt_at'] = $this->now();
        $this->save_state($state);

        $this->finish('auto_resume', 'block_archives', $this->execute_auto_resume(), ['pause_minutes' => null]);
    }

    /**
     * The light path. Runs under the lock.
     *
     * @return array ['reason' => string|null, 'message' => string, 'warnings' => array]
     */
    private function execute_auto_resume() {
        $current = $this->file_facts('content');
        if ($current['corrupt']) {
            // Damaged markers — or a file that cannot be read (file_facts() reports both as corrupt).
            return ['reason' => 'markers_corrupt', 'message' => 'The .htaccess cannot be read or its LSM-HARDENING markers are damaged.', 'warnings' => []];
        }

        $enabled = ['block_archives'];
        foreach ($this->rules_of('content') as $other) {
            if ($current['in_block'][$other]) {
                $enabled[] = $other;
            }
        }
        $candidate = $this->replace_block($current['content'], $this->build_block('content', $enabled));

        $asset_before = $this->baseline_asset();
        $comparable   = $asset_before !== null && $this->asset_ok($asset_before);

        // The loopback above can take seconds: never write a candidate built from stale bytes.
        // The attempt fails, the pause stays overdue and the next attempt starts from the new bytes.
        $fresh = $this->read_target('content');
        if ($fresh['existed'] !== $current['existed'] || $fresh['content'] !== $current['content']) {
            return [
                'reason'   => 'write_failed',
                'message'  => 'The .htaccess was changed by something else while the self-test was running. Nothing was changed.',
                'warnings' => [],
            ];
        }

        if (!$this->write_snapshot('content', $current)) {
            return ['reason' => 'snapshot_failed', 'message' => 'The backup copy of the .htaccess could not be written.', 'warnings' => []];
        }
        $state            = $this->get_state();
        $state['pending'] = [
            'target'     => 'content',
            'op'         => 'resume',
            'started_at' => $this->now(),
            'existed'    => $current['existed'],
        ];
        $this->save_state($state);

        $written = $this->commit_target('content', $candidate, $current['content'], $current['existed']);
        $after   = $this->file_facts('content');
        if (!$written || !$after['in_block']['block_archives']) {
            $failure             = $this->rollback('content', $current, ['reason' => 'write_failed', 'message' => 'The archive rule did not read back from the .htaccess.']);
            $failure['warnings'] = [];
            return $failure;
        }

        if (!$comparable) {
            // The loopback itself does not work here: keep the block, say so.
            return ['reason' => null, 'message' => 'Archive rule restored (not verified).', 'warnings' => ['unverified']];
        }

        $asset_after = $this->baseline_asset();
        if ($asset_after['error']) {
            return ['reason' => null, 'message' => 'Archive rule restored (not verified).', 'warnings' => ['unverified']];
        }
        if ($asset_after['code'] !== 200) {
            $failure             = $this->rollback('content', $current, ['reason' => 'asset_broken', 'message' => sprintf('After the write the plugin stylesheet answered HTTP %d instead of 200.', $asset_after['code'])]);
            $failure['warnings'] = [];
            return $failure;
        }

        return ['reason' => null, 'message' => 'Archive rule restored.', 'warnings' => []];
    }
```

- [ ] **Step 4: Lint and run the whole suite**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && php -l landeseiten-maintenance/includes/class-lsm-hardening.php && vendor/bin/phpunit -c phpunit.xml.dist
```

Expected: `No syntax errors detected`, then PASS — `OK (171 tests, 948 assertions)`.

- [ ] **Step 5: Commit**

```bash
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening add landeseiten-maintenance/includes/class-lsm-hardening.php tests/HardeningAutoResumeTest.php
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening commit -m "$(cat <<'EOF'
feat(hardening): auto-resume an expired pause on shutdown, fail closed

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
EOF
)"
```

---

### Task 11: Crash recovery

On `init`, a `pending` older than 180 s means the operation was killed between write and finish. Recovery takes the lock first (the dead process's own lock is stale by then), makes no HTTP request, and derives the file from `pending.target` — never from a stored path. op `resume` → roll **forward**: make sure the archive rule is in the block, then clear `pause_until` (restoring the snapshot would restore the *exposed* file). Any other op → restore the snapshot if present; without a snapshot and with `existed === false`, take our block out of the file and delete it if nothing else is in it. Always: delete the snapshot and every `lsm-probe-*` in both directories, clear `pending`, record `last_result.reason = 'crash_recovered'` (`action` `crash_recovery`, `ok` false, `rule` = `block_archives` for pause/resume, else `null` — `pending` does not store the rule). Recovery is not skipped on our own REST routes: it runs at `init`, before REST dispatch, so the platform's next call is not answered `busy`. `on_init()` runs on every request, so it reads `pending.started_at` through `isset()`: a damaged option (DB restore, partial write, manual edit) must not raise "Undefined array key" on every page load — a `pending` without a start time counts as old and is recovered.

The tests simulate a crash honestly: the fake server throws from the first stylesheet request after the write, which — because Task 8 has no `try/finally` — leaves lock, `pending`, snapshot and probes behind like a killed process.

**Files:**
- Modify: `landeseiten-maintenance/includes/class-lsm-hardening.php` (insert above the final `}`; one edit inside `on_init()`)
- Test: `tests/HardeningRecoveryTest.php`

**Interfaces:**
- Consumes: `on_init()`, `auto_resume()` (Task 10); `set_rule`, `save_state` (Task 8); `pause` (Task 9); `cleanup_artifacts` (Task 7); `acquire_lock`, `release_lock` (Task 6); `file_facts`, `get_state` (Task 5); `read_target`, `replace_block`, `commit_target`, `restore_target`, `target_file` (Task 3); constants `RECOVERY_AFTER`, `SNAPSHOT_FILE` (Task 2); `LSM_Fake_Server::script()` with a `Throwable` (Task 1).
- Produces: `private function recover()`, `private function roll_forward_resume()` → bool, `private function restore_from_snapshot($target, $existed)`; `on_init()` now recovers before it looks at the pause. Log action `hardening_crash_recovered` (`warning`).

- [ ] **Step 1: Write the failing test** — `W/tests/HardeningRecoveryTest.php`:

```php
<?php

/**
 * Crash recovery. A crash is simulated by a throwable from the fake server after
 * the write: like a killed PHP process, it leaves lock, pending, snapshot and probes behind.
 */
class HardeningRecoveryTest extends HardeningTestCase {

    const FOREIGN = "# BEGIN WebP Express\nAddType image/webp .webp\n# END WebP Express\n";

    /**
     * Run $operation and kill it at the first stylesheet request after the write.
     */
    private function crash_after_write(callable $operation) {
        $this->server->script('~ticket-ui\.css~', ['pass', new RuntimeException('killed')]);
        try {
            $operation();
            $this->fail('the operation was expected to die');
        } catch (RuntimeException $e) {
            $this->assertSame('killed', $e->getMessage());
        }
        $this->server->requests = [];
    }

    private function assertRecovered($rule) {
        $state = $this->h->get_state();
        $this->assertNull($state['pending']);
        $this->assertSame(
            ['at' => $this->h->time, 'action' => 'crash_recovery', 'rule' => $rule, 'ok' => false, 'reason' => 'crash_recovered', 'warnings' => []],
            $state['last_result']
        );
        $this->assertSame([], $this->artifacts(), 'snapshot and probe files are gone');
        $this->assertFalse(get_option('lsm_hardening_lock'));
        $this->assertSame([], $this->server->requests, 'recovery makes no HTTP request');
        $this->assertSame(['hardening_crash_recovered', 'warning'], array_slice(end(LSM_Test_Env::$log), 0, 2));
    }

    public function test_a_killed_operation_leaves_lock_pending_snapshot_and_probes_behind() {
        $this->put('content', self::FOREIGN);

        $this->crash_after_write(function () {
            $this->h->set_rule('block_archives', true);
        });

        $this->assertStringContainsString('LSM-HARDENING', $this->get('content'), 'the write had happened');
        $this->assertSame(['target' => 'content', 'op' => 'enable', 'started_at' => $this->h->time, 'existed' => true], $this->h->get_state()['pending']);
        $this->assertSame($this->h->time, get_option('lsm_hardening_lock'));
        $this->assertCount(3, $this->artifacts());
        $this->assertFalse($this->h->get_state()['rules']['block_archives'], 'rules are only committed after the self-test');
    }

    public function test_nothing_is_recovered_before_180_seconds_have_passed() {
        $this->put('content', self::FOREIGN);
        $this->crash_after_write(function () {
            $this->h->set_rule('block_archives', true);
        });

        $this->h->time += 180;
        $this->h->on_init();

        $this->assertNotNull($this->h->get_state()['pending']);
        $this->assertStringContainsString('LSM-HARDENING', $this->get('content'));
        $this->assertSame('busy', $this->h->set_rule('block_debug_log', true)['reason'], 'the dead lock still holds');
    }

    public function test_killed_enable_is_restored_from_the_snapshot() {
        $this->put('content', self::FOREIGN);
        $this->crash_after_write(function () {
            $this->h->set_rule('block_archives', true);
        });

        $this->h->time += 181;
        $this->h->on_init();

        $this->assertSame(self::FOREIGN, $this->get('content'));
        $this->assertRecovered(null);
        $this->assertSame('off', $this->h->get_status()['rules']['block_archives']['state']);
    }

    public function test_killed_enable_on_a_site_without_the_file_deletes_it_again() {
        $this->crash_after_write(function () {
            $this->h->set_rule('block_uploads_php', true);
        });
        $this->assertFalse($this->h->get_state()['pending']['existed']);
        $this->assertSame('uploads', $this->h->get_state()['pending']['target']);

        $this->h->time += 181;
        $this->h->on_init();

        $this->assertNull($this->get('uploads'));
        $this->assertRecovered(null);
    }

    public function test_killed_enable_without_snapshot_keeps_what_others_wrote_since() {
        $this->crash_after_write(function () {
            $this->h->set_rule('block_uploads_php', true);
        });
        file_put_contents($this->htaccess('uploads'), "Options -Indexes\n", FILE_APPEND);

        $this->h->time += 181;
        $this->h->on_init();

        $this->assertSame("Options -Indexes\n", $this->get('uploads'));
    }

    public function test_killed_pause_puts_the_archive_rule_back() {
        $this->h->set_rule('block_archives', true);
        $with_rule = $this->get('content');
        $this->crash_after_write(function () {
            $this->h->pause(60);
        });
        $this->assertStringNotContainsString('wpress', $this->get('content'));

        $this->h->time += 181;
        $this->h->on_init();

        $this->assertSame($with_rule, $this->get('content'));
        $this->assertNull($this->h->get_state()['pause_until']);
        $this->assertSame('on', $this->h->get_status()['rules']['block_archives']['state']);
        $this->assertRecovered('block_archives');
    }

    public function test_killed_disable_puts_the_rule_back() {
        $this->h->set_rule('block_debug_log', true);
        $with_rule = $this->get('content');
        $this->crash_after_write(function () {
            $this->h->set_rule('block_debug_log', false);
        });
        $this->assertSame('disable', $this->h->get_state()['pending']['op']);

        $this->h->time += 181;
        $this->h->on_init();

        $this->assertSame($with_rule, $this->get('content'));
        $this->assertTrue($this->h->get_state()['rules']['block_debug_log']);
        $this->assertRecovered(null);
    }

    public function test_killed_resume_is_rolled_forward_never_back() {
        $this->h->set_rule('block_archives', true);
        $with_rule = $this->get('content');
        $this->h->pause(15);
        $this->h->time += 901;
        $this->crash_after_write(function () {
            $this->h->auto_resume();
        });
        $this->assertSame('resume', $this->h->get_state()['pending']['op']);
        // Worst case: the process died before its write reached the disk.
        $this->put('content', '');

        $this->h->time += 181;
        $this->h->on_init();

        $this->assertSame($with_rule, $this->get('content'), 'the archive rule is in the file');
        $this->assertNull($this->h->get_state()['pause_until'], 'cleared after the read-back');
        $this->assertSame('on', $this->h->get_status()['rules']['block_archives']['state']);
        $this->assertRecovered('block_archives');
        $this->assertSame([], LSM_Test_Env::$actions, 'nothing left to auto-resume');
    }

    public function test_killed_resume_with_corrupt_markers_stays_paused_and_overdue() {
        $until = $this->h->time - 10;
        update_option('lsm_hardening', [
            'rules'       => ['block_archives' => true],
            'pause_until' => $until,
            'pending'     => ['target' => 'content', 'op' => 'resume', 'started_at' => $this->h->time - 500, 'existed' => true],
        ]);
        $this->put('content', "# END LSM-HARDENING\n");

        $this->h->on_init();

        $this->assertSame("# END LSM-HARDENING\n", $this->get('content'));
        $this->assertSame($until, $this->h->get_state()['pause_until']);
        $this->assertNull($this->h->get_state()['pending']);
        $this->assertTrue($this->h->get_status()['pause_overdue']);
        $this->assertCount(1, LSM_Test_Env::$actions, 'auto-resume is retried on this very request');
    }

    public function test_recovery_takes_the_lock_first() {
        $this->put('content', self::FOREIGN);
        $this->crash_after_write(function () {
            $this->h->set_rule('block_archives', true);
        });
        $this->h->time += 181;
        // Another request got there first and is recovering right now.
        update_option('lsm_hardening_lock', $this->h->time);
        $written = $this->get('content');

        $this->h->on_init();

        $this->assertSame($written, $this->get('content'));
        $this->assertNotNull($this->h->get_state()['pending']);
    }

    public function test_the_target_file_comes_from_the_enum_never_from_a_path() {
        $victim = $this->root . '/wp-config.php';
        file_put_contents($victim, '<?php // secrets');
        $this->put('content', $this->h->build_block('content', ['block_archives']) . "\n");
        file_put_contents($this->content . '/.htaccess.lsm-bak', self::FOREIGN);
        update_option('lsm_hardening', ['pending' => [
            'target'     => $victim,
            'file'       => $victim,
            'op'         => 'enable',
            'started_at' => $this->h->time - 181,
            'existed'    => false,
        ]]);

        $this->h->on_init();

        $this->assertSame('<?php // secrets', file_get_contents($victim));
        $this->assertSame(self::FOREIGN, $this->get('content'), 'an unknown target means wp-content/.htaccess');
    }

    public function test_a_damaged_pending_without_a_start_time_counts_as_old_and_is_recovered() {
        // DB restore, partial write, manual edit. on_init() runs on every request: an undefined
        // array key here would be a PHP warning on every page load.
        update_option('lsm_hardening', ['pending' => ['target' => 'content']]);

        $this->h->on_init();

        $state = $this->h->get_state();
        $this->assertNull($state['pending']);
        $this->assertSame('crash_recovered', $state['last_result']['reason']);
        $this->assertNull($this->get('content'), 'nothing to restore, nothing created');
        $this->assertFalse(get_option('lsm_hardening_lock'));
    }

    public function test_recovery_also_runs_on_our_own_rest_routes_so_the_next_call_is_not_busy() {
        $this->put('content', self::FOREIGN);
        $this->crash_after_write(function () {
            $this->h->set_rule('block_archives', true);
        });
        $this->h->time += 181;
        $_SERVER['REQUEST_URI'] = '/wp-json/lsm/v1/hardening/rule';

        $this->h->on_init();
        $result = $this->h->set_rule('block_archives', true);

        $this->assertTrue($result['success']);
        $this->assertSame(self::FOREIGN . "\n" . $this->h->build_block('content', ['block_archives']) . "\n", $this->get('content'));
    }
}
```

- [ ] **Step 2: Run and watch it fail**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && vendor/bin/phpunit -c phpunit.xml.dist --filter HardeningRecoveryTest
```

Expected: FAIL — `Tests: 13, Assertions: 39, Failures: 9.` Every `test_killed_*` test, `test_the_target_file_comes_from_the_enum_never_from_a_path` and `test_a_damaged_pending_without_a_start_time_counts_as_old_and_is_recovered` fail because nothing recovers yet (first one: `Failed asserting that two strings are identical.`); the four tests about what a crash leaves behind already pass.

- [ ] **Step 3: Implement the recovery** — insert above the final `}` of `class LSM_Hardening`, preceded by one blank line:

```php
    // =========================================================================
    // CRASH RECOVERY
    // =========================================================================

    /**
     * A pending operation older than RECOVERY_AFTER was killed between write and finish.
     * No HTTP here: this runs inline on `init`.
     *
     * - op resume: roll forward — make sure the archive rule is in the block, then clear the pause.
     * - any other op: put the snapshot back (or take our block out of a file that did not exist).
     * - always: delete snapshot and probe files, clear pending, record crash_recovered.
     */
    private function recover() {
        if (!$this->acquire_lock()) {
            return;
        }

        $state   = $this->get_state();
        $pending = $state['pending'];
        // A pending without a start time (damaged option) counts as old and is recovered.
        if (!is_array($pending) || $this->now() - (isset($pending['started_at']) ? (int) $pending['started_at'] : 0) <= self::RECOVERY_AFTER) {
            $this->release_lock();
            return;
        }

        // The file comes from the enum, never from a stored path.
        $target  = isset($pending['target']) && $pending['target'] === 'uploads' ? 'uploads' : 'content';
        $op      = isset($pending['op']) ? (string) $pending['op'] : '';
        $resumed = false;

        if ($op === 'resume') {
            $resumed = $this->roll_forward_resume();
        } else {
            $this->restore_from_snapshot($target, !empty($pending['existed']));
        }

        $this->cleanup_artifacts();

        if ($resumed) {
            $state['pause_until'] = null;
        }
        $state['pending']     = null;
        $state['last_result'] = [
            'at'       => $this->now(),
            'action'   => 'crash_recovery',
            'rule'     => in_array($op, ['pause', 'resume'], true) ? 'block_archives' : null,
            'ok'       => false,
            'reason'   => 'crash_recovered',
            'warnings' => [],
        ];
        $this->save_state($state);
        $this->release_lock();

        LSM_Logger::log('hardening_crash_recovered', 'warning', ['op' => $op, 'target' => $target]);
    }

    /**
     * Recovery of a killed resume: the light path without HTTP.
     *
     * @return bool True when the read-back shows the archive rule in the block.
     */
    private function roll_forward_resume() {
        $current = $this->file_facts('content');
        if ($current['corrupt']) {
            return false;
        }

        $enabled = ['block_archives'];
        foreach ($this->rules_of('content') as $other) {
            if ($current['in_block'][$other]) {
                $enabled[] = $other;
            }
        }
        $candidate = $this->replace_block($current['content'], $this->build_block('content', $enabled));

        if (!$this->commit_target('content', $candidate, $current['content'], $current['existed'])) {
            $this->restore_target('content', $current['content'], $current['existed']);
            return false;
        }

        $after = $this->file_facts('content');
        return $after['in_block']['block_archives'];
    }

    /**
     * Recovery of any other killed operation: back to the bytes from before it started.
     *
     * @param string $target  'content' or 'uploads'.
     * @param bool   $existed pending.existed.
     */
    private function restore_from_snapshot($target, $existed) {
        $snapshot = dirname($this->target_file($target)) . '/' . self::SNAPSHOT_FILE;

        if (is_file($snapshot)) {
            $original = @file_get_contents($snapshot);
            if ($original !== false) {
                $this->restore_target($target, $original, true);
            }
            return;
        }

        if (!$existed) {
            // No snapshot because there was no file: take our block out, delete the file if nothing else is in it.
            $current  = $this->read_target($target);
            $stripped = $this->replace_block($current['content'], '');
            if ($stripped !== null) {
                $this->commit_target($target, $stripped, $current['content'], false);
            }
        }
    }
```

- [ ] **Step 4: Call it from `on_init()`** — in `P/includes/class-lsm-hardening.php`, in `on_init()` (added in Task 10), find:

```php
    public function on_init() {
        $state = $this->get_state();

        $overdue = $state['pause_until'] !== null && $state['pause_until'] < $this->now();
```

and replace it with:

```php
    public function on_init() {
        $state = $this->get_state();

        // isset(): this runs on every request, and a damaged option must not raise a warning on
        // every page load. A pending without a start time counts as old.
        if (is_array($state['pending']) && $this->now() - (isset($state['pending']['started_at']) ? (int) $state['pending']['started_at'] : 0) > self::RECOVERY_AFTER) {
            $this->recover();
            $state = $this->get_state();
        }

        $overdue = $state['pause_until'] !== null && $state['pause_until'] < $this->now();
```

- [ ] **Step 5: Lint and run the whole suite**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && php -l landeseiten-maintenance/includes/class-lsm-hardening.php && vendor/bin/phpunit -c phpunit.xml.dist
```

Expected: `No syntax errors detected`, then PASS — `OK (184 tests, 1029 assertions)`.

- [ ] **Step 6: Commit**

```bash
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening add landeseiten-maintenance/includes/class-lsm-hardening.php tests/HardeningRecoveryTest.php
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening commit -m "$(cat <<'EOF'
feat(hardening): recover killed operations on init, rolling a resume forward

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
EOF
)"
```

---

### Task 12: Deactivation cleanup

A deactivated plugin can neither pause nor undo, so deactivation removes both managed blocks with the strict writer, sets all `rules` to false (so a later reactivation truthfully shows Off), clears `pause_until` and `pending`, deletes leftovers, logs. It skips the server preflight — deactivation is often run from WP-CLI, where `SERVER_SOFTWARE` is empty — and runs no self-test. It takes the lock when it can but goes ahead either way: there is no "later" for a plugin that is being switched off. Manual blocks, files with corrupt markers and files that cannot be read (`read_target()`'s `unreadable`) are left alone. `last_result.action` = `deactivate`, `rule` = `null`.

**Files:**
- Modify: `landeseiten-maintenance/includes/class-lsm-hardening.php` (insert above the final `}`)
- Test: `tests/HardeningDeactivateTest.php`

**Interfaces:**
- Consumes: `read_target`, `replace_block`, `commit_target`, `restore_target` (Task 3); `cleanup_artifacts` (Task 7); `acquire_lock`, `release_lock` (Task 6); `get_state` (Task 5); `save_state`, `set_rule` (Task 8); `pause` (Task 9).
- Produces: `public function deactivate()` → void (Task 14 calls it from `Landeseiten_Maintenance::deactivate()`). Log action `hardening_deactivated` (`info`) with context `['removed_from' => ['content', 'uploads']]`.

- [ ] **Step 1: Write the failing test** — `W/tests/HardeningDeactivateTest.php`:

```php
<?php

/**
 * Deactivation cleanup.
 */
class HardeningDeactivateTest extends HardeningTestCase {

    const FOREIGN = "# BEGIN WebP Express\nAddType image/webp .webp\n# END WebP Express\n";

    public function test_deactivation_removes_both_blocks_and_resets_the_state() {
        $this->put('content', self::FOREIGN);
        $this->h->set_rule('block_archives', true);
        $this->h->set_rule('block_debug_log', true);
        $this->h->set_rule('block_uploads_php', true);
        $this->h->pause(60);
        $this->server->requests = [];
        LSM_Test_Env::$log      = [];

        $this->h->deactivate();

        $this->assertSame(self::FOREIGN, $this->get('content'), 'foreign bytes untouched');
        $this->assertSame('', $this->get('uploads'), 'the uploads file held nothing but our block');

        $state = $this->h->get_state();
        $this->assertSame(['block_archives' => false, 'block_debug_log' => false, 'block_uploads_php' => false], $state['rules']);
        $this->assertNull($state['pause_until']);
        $this->assertNull($state['pending']);
        $this->assertSame(
            ['at' => $this->h->time, 'action' => 'deactivate', 'rule' => null, 'ok' => true, 'reason' => null, 'warnings' => []],
            $state['last_result']
        );
        $this->assertFalse(get_option('lsm_hardening_lock'));

        $this->assertSame([], $this->server->requests, 'no self-test');
        $this->assertSame([['hardening_deactivated', 'info', ['removed_from' => ['content', 'uploads']]]], LSM_Test_Env::$log);

        $rules = $this->h->get_status()['rules'];
        $this->assertSame(['off', 'off', 'off'], array_column($rules, 'state'), 'reactivation truthfully shows Off');
    }

    public function test_deactivation_skips_the_server_preflight() {
        $this->h->set_rule('block_archives', true);
        // WP-CLI: no SERVER_SOFTWARE.
        $this->h->software = '';
        $this->h->cli      = true;

        $this->h->deactivate();

        $this->assertStringNotContainsString('LSM-HARDENING', (string) $this->get('content'));
    }

    public function test_deactivation_leaves_manual_blocks_and_corrupt_files_alone() {
        $this->put('uploads', LSM_Htaccess_Fixtures::AUDITED_UPLOADS);
        $corrupt = "# BEGIN LSM-HARDENING\n<Files \"debug.log\">\n";
        $this->put('content', $corrupt);

        $this->h->deactivate();

        $this->assertSame(LSM_Htaccess_Fixtures::AUDITED_UPLOADS, $this->get('uploads'));
        $this->assertSame($corrupt, $this->get('content'));
    }

    public function test_deactivation_cleans_up_after_a_killed_operation() {
        file_put_contents($this->content . '/.htaccess.lsm-bak', 'x');
        file_put_contents($this->content . '/lsm-probe-0123456789abcdef.wpress', 'x');
        update_option('lsm_hardening', ['pending' => ['target' => 'content', 'op' => 'enable', 'started_at' => $this->h->time, 'existed' => true]]);
        add_option('lsm_hardening_lock', $this->h->time, '', 'no');

        $this->h->deactivate();

        $this->assertSame([], $this->artifacts());
        $this->assertNull($this->h->get_state()['pending']);
        $this->assertFalse(get_option('lsm_hardening_lock'));
    }

    public function test_deactivation_on_a_site_that_never_used_the_feature_touches_no_file() {
        $this->h->deactivate();

        $this->assertNull($this->get('content'));
        $this->assertNull($this->get('uploads'));
    }
}
```

- [ ] **Step 2: Run and watch it fail**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && vendor/bin/phpunit -c phpunit.xml.dist --filter HardeningDeactivateTest
```

Expected: FAIL — `Tests: 5, Assertions: 0, Errors: 5.`, each one `Error: Call to undefined method LSM_Testable_Hardening::deactivate()`.

- [ ] **Step 3: Implement** — insert above the final `}` of `class LSM_Hardening`, preceded by one blank line:

```php
    // =========================================================================
    // DEACTIVATION
    // =========================================================================

    /**
     * Plugin deactivation: a deactivated plugin can neither pause nor undo, so both
     * managed blocks come out. Skips the server preflight (often run from WP-CLI,
     * where SERVER_SOFTWARE is empty) and runs no self-test — removing deny rules
     * cannot take a site down.
     */
    public function deactivate() {
        // Take the lock if we can; deactivation goes ahead either way, there is no later.
        $this->acquire_lock();

        $removed = [];
        foreach (['content', 'uploads'] as $target) {
            $current  = $this->read_target($target);
            $stripped = $this->replace_block($current['content'], '');
            // Unreadable, corrupt markers, or no managed block: leave the file alone.
            if (!empty($current['unreadable']) || $stripped === null || $stripped === $current['content']) {
                continue;
            }
            if (!$this->commit_target($target, $stripped, $current['content'], $current['existed'])) {
                $this->restore_target($target, $current['content'], $current['existed']);
                continue;
            }
            $removed[] = $target;
        }

        $this->cleanup_artifacts();

        $state = $this->get_state();
        foreach (self::RULES as $rule) {
            $state['rules'][$rule] = false;
        }
        $state['pause_until'] = null;
        $state['pending']     = null;
        $state['last_result'] = [
            'at'       => $this->now(),
            'action'   => 'deactivate',
            'rule'     => null,
            'ok'       => true,
            'reason'   => null,
            'warnings' => [],
        ];
        $this->save_state($state);
        $this->release_lock();

        LSM_Logger::log('hardening_deactivated', 'info', ['removed_from' => $removed]);
    }
```

- [ ] **Step 4: Lint and run the whole suite**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && php -l landeseiten-maintenance/includes/class-lsm-hardening.php && vendor/bin/phpunit -c phpunit.xml.dist
```

Expected: `No syntax errors detected`, then PASS — `OK (189 tests, 1047 assertions)`.

- [ ] **Step 5: Commit**

```bash
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening add landeseiten-maintenance/includes/class-lsm-hardening.php tests/HardeningDeactivateTest.php
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening commit -m "$(cat <<'EOF'
feat(hardening): remove managed blocks and reset desired state on deactivation

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
EOF
)"
```

---

### Task 13: REST endpoints

Four thin callbacks in `LSM_API`. House style: no `args` on the routes, manual validation in the callback. Every response is HTTP 200 with `success` / `reason` at the top level and **no `data` wrapper** (the platform's `handleResponse()` would unwrap `{success:true,data}` and lose `success`), and carries `Cache-Control: no-store, private` (one host caches plugin REST GETs for 28 days). `(bool) "false"` is `true`, so `enabled` goes through `rest_is_boolean()` + `rest_sanitize_boolean()`; a missing or non-boolean `enabled` is rejected with `invalid_rule` (the fixed reason list has no separate code for it) instead of being read as "disable". `minutes` may arrive as an int or as a numeric string and is cast before it reaches the engine, which insists on an int.

**Files:**
- Modify: `landeseiten-maintenance/includes/class-lsm-api.php` — routes after line 324, callbacks after line 2190 (master numbering); `tests/bootstrap.php` (append 1 line)
- Test: `tests/HardeningRestTest.php`

**Interfaces:**
- Consumes: `LSM_Hardening::instance()`, `LSM_Hardening::RULES`, `LSM_Hardening::PAUSE_MINUTES` (Task 2); `respond($success, $reason, $message, array $warnings = [])`, `set_rule($rule, $enabled)` (Task 8); `pause($minutes)`, `resume()` (Task 9); fakes `register_rest_route`, `rest_ensure_response`, `WP_REST_Request`, `WP_REST_Response`, `rest_is_boolean`, `rest_sanitize_boolean`, `LSM_Test_Env::$routes` (Task 1). `HardeningTestCase::setUp()` already makes the testable engine the shared instance via `LSM_Hardening::set_instance()`.
- Produces: routes `GET lsm/v1/hardening/status`, `POST lsm/v1/hardening/rule`, `POST lsm/v1/hardening/pause`, `POST lsm/v1/hardening/resume`, all with `permission_callback => [$this, 'authenticate']`; callbacks `get_hardening_status()`, `set_hardening_rule($request)`, `pause_hardening($request)`, `resume_hardening()`; `private function hardening_response($result)`.

- [ ] **Step 1: Load `LSM_API` in the tests** — append to the end of `W/tests/bootstrap.php`:

```php
require_once LSM_PLUGIN_DIR . 'includes/class-lsm-api.php';
```

- [ ] **Step 2: Write the failing test** — `W/tests/HardeningRestTest.php`:

```php
<?php

/**
 * The four lsm/v1/hardening/* endpoints.
 */
class HardeningRestTest extends HardeningTestCase {

    /** @var LSM_API */
    private $api;

    protected function setUp(): void {
        parent::setUp();
        $this->api = new LSM_API();
    }

    private function assertHardeningShape(WP_REST_Response $response) {
        $this->assertSame(200, $response->get_status());
        $this->assertSame(['Cache-Control' => 'no-store, private'], $response->get_headers());

        $data = $response->get_data();
        $this->assertSame(['success', 'reason', 'message', 'warnings', 'status'], array_keys($data), 'top level, no data wrapper');
        $this->assertIsBool($data['success']);
        $this->assertIsString($data['message']);
        $this->assertIsArray($data['warnings']);
        $this->assertSame(
            ['plugin_version', 'server', 'rules', 'pause_until', 'pause_overdue', 'archive_attachments', 'last_result'],
            array_keys($data['status'])
        );
        $this->assertSame(LSM_Hardening::RULES, array_keys($data['status']['rules']));
        foreach ($data['status']['rules'] as $rule) {
            $this->assertSame(['state', 'desired', 'unsupported_reason', 'last_failure'], array_keys($rule));
        }
        return $data;
    }

    public function test_the_four_routes_are_registered_behind_authenticate() {
        $this->api->register_routes();

        $expected = [
            'GET lsm/v1/hardening/status'  => 'get_hardening_status',
            'POST lsm/v1/hardening/rule'   => 'set_hardening_rule',
            'POST lsm/v1/hardening/pause'  => 'pause_hardening',
            'POST lsm/v1/hardening/resume' => 'resume_hardening',
        ];
        foreach ($expected as $route => $method) {
            $this->assertArrayHasKey($route, LSM_Test_Env::$routes);
            $this->assertSame([$this->api, $method], LSM_Test_Env::$routes[$route]['callback']);
            $this->assertSame([$this->api, 'authenticate'], LSM_Test_Env::$routes[$route]['permission_callback']);
            $this->assertArrayNotHasKey('args', LSM_Test_Env::$routes[$route], 'house style: manual validation in the callback');
        }
    }

    public function test_status() {
        LSM_Test_Env::$db_var = 3;

        $data = $this->assertHardeningShape($this->api->get_hardening_status());

        $this->assertTrue($data['success']);
        $this->assertNull($data['reason']);
        $this->assertSame([], $data['warnings']);
        $this->assertSame(3, $data['status']['archive_attachments']);
        $this->assertSame('off', $data['status']['rules']['block_archives']['state']);
        $this->assertSame([], $this->server->requests, 'a status read makes no loopback request');
    }

    public function test_rule_enable_and_the_exact_json() {
        $response = $this->api->set_hardening_rule(new WP_REST_Request(['rule' => 'block_archives', 'enabled' => true]));
        $data     = $this->assertHardeningShape($response);

        $off = ['state' => 'off', 'desired' => false, 'unsupported_reason' => null, 'last_failure' => null];
        $this->assertSame([
            'success'  => true,
            'reason'   => null,
            'message'  => 'Applied and verified',
            'warnings' => [],
            'status'   => [
                'plugin_version' => LSM_VERSION,
                'server'         => 'Apache',
                'rules'          => [
                    'block_archives'    => ['state' => 'on', 'desired' => true, 'unsupported_reason' => null, 'last_failure' => null],
                    'block_debug_log'   => $off,
                    'block_uploads_php' => $off,
                ],
                'pause_until'         => null,
                'pause_overdue'       => false,
                'archive_attachments' => 0,
                'last_result'         => ['at' => $this->h->time, 'action' => 'enable', 'rule' => 'block_archives', 'ok' => true, 'reason' => null, 'warnings' => []],
            ],
        ], $data);
    }

    public function test_rule_accepts_the_boolean_spellings_a_form_post_sends() {
        $this->api->set_hardening_rule(new WP_REST_Request(['rule' => 'block_debug_log', 'enabled' => 'true']));
        $this->assertSame('on', $this->h->get_status()['rules']['block_debug_log']['state']);

        // (bool) "false" would be true.
        $this->api->set_hardening_rule(new WP_REST_Request(['rule' => 'block_debug_log', 'enabled' => 'false']));
        $this->assertSame('off', $this->h->get_status()['rules']['block_debug_log']['state']);
    }

    public function test_rule_validation() {
        $bad = [
            'unknown rule'     => ['rule' => 'block_xmlrpc', 'enabled' => true],
            'missing rule'     => ['enabled' => true],
            'array rule'       => ['rule' => ['block_archives'], 'enabled' => true],
            'missing enabled'  => ['rule' => 'block_archives'],
            'garbage enabled'  => ['rule' => 'block_archives', 'enabled' => 'yes please'],
            'numeric enabled'  => ['rule' => 'block_archives', 'enabled' => 2],
        ];
        foreach ($bad as $case => $params) {
            $data = $this->assertHardeningShape($this->api->set_hardening_rule(new WP_REST_Request($params)));
            $this->assertFalse($data['success'], $case);
            $this->assertSame('invalid_rule', $data['reason'], $case);
        }
        $this->assertNull($this->get('content'));
        $this->assertSame([], $this->server->requests);
    }

    public function test_a_failure_is_still_http_200_with_the_reason_at_the_top_level() {
        $this->server->bypass_htaccess = '~\.zip$~';

        $data = $this->assertHardeningShape($this->api->set_hardening_rule(new WP_REST_Request(['rule' => 'block_archives', 'enabled' => true])));

        $this->assertFalse($data['success']);
        $this->assertSame('rule_ineffective', $data['reason']);
        $this->assertSame('rule_ineffective', $data['status']['rules']['block_archives']['last_failure']['reason']);
    }

    public function test_pause_and_resume() {
        $this->h->set_rule('block_archives', true);

        $data = $this->assertHardeningShape($this->api->pause_hardening(new WP_REST_Request(['minutes' => 30])));
        $this->assertTrue($data['success']);
        $this->assertSame('paused', $data['status']['rules']['block_archives']['state']);
        $this->assertSame($this->h->time + 1800, $data['status']['pause_until']);

        $data = $this->assertHardeningShape($this->api->resume_hardening());
        $this->assertTrue($data['success']);
        $this->assertSame('on', $data['status']['rules']['block_archives']['state']);
        $this->assertNull($data['status']['pause_until']);

        $data = $this->assertHardeningShape($this->api->resume_hardening());
        $this->assertTrue($data['success'], 'resume is idempotent');
    }

    public function test_pause_accepts_a_numeric_string() {
        $this->h->set_rule('block_archives', true);
        $data = $this->assertHardeningShape($this->api->pause_hardening(new WP_REST_Request(['minutes' => '15'])));
        $this->assertTrue($data['success']);
        $this->assertSame($this->h->time + 900, $data['status']['pause_until']);
    }

    public function test_pause_validation() {
        $this->h->set_rule('block_archives', true);

        foreach ([null, 45, '45', 0, '60abc', 15.5, '15.5', true, [60]] as $minutes) {
            $data = $this->assertHardeningShape($this->api->pause_hardening(new WP_REST_Request(['minutes' => $minutes])));
            $this->assertFalse($data['success']);
            $this->assertSame('invalid_minutes', $data['reason']);
        }
        $this->assertNull($this->h->get_state()['pause_until']);
    }

    public function test_pause_when_the_rule_is_off() {
        $data = $this->assertHardeningShape($this->api->pause_hardening(new WP_REST_Request(['minutes' => 60])));
        $this->assertSame('not_enabled', $data['reason']);
    }
}
```

- [ ] **Step 3: Run and watch it fail**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && vendor/bin/phpunit -c phpunit.xml.dist --filter HardeningRestTest
```

Expected: FAIL — `Tests: 10, Assertions: 1, Errors: 9, Failures: 1.`: `Failed asserting that an array has the key 'GET lsm/v1/hardening/status'.` and nine times `Error: Call to undefined method LSM_API::get_hardening_status()` (or `set_hardening_rule`, `pause_hardening`, `resume_hardening`).

- [ ] **Step 4: Register the routes** — in `P/includes/class-lsm-api.php`, inside `register_routes()`, find (lines 322-324):

```php
            'callback'            => [$this, 'get_security_header_snippets'],
            'permission_callback' => [$this, 'authenticate'],
        ]);
```

and replace it with:

```php
            'callback'            => [$this, 'get_security_header_snippets'],
            'permission_callback' => [$this, 'authenticate'],
        ]);

        // Managed .htaccess hardening
        register_rest_route(self::NAMESPACE, '/hardening/status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_hardening_status'],
            'permission_callback' => [$this, 'authenticate'],
        ]);

        register_rest_route(self::NAMESPACE, '/hardening/rule', [
            'methods'             => 'POST',
            'callback'            => [$this, 'set_hardening_rule'],
            'permission_callback' => [$this, 'authenticate'],
        ]);

        register_rest_route(self::NAMESPACE, '/hardening/pause', [
            'methods'             => 'POST',
            'callback'            => [$this, 'pause_hardening'],
            'permission_callback' => [$this, 'authenticate'],
        ]);

        register_rest_route(self::NAMESPACE, '/hardening/resume', [
            'methods'             => 'POST',
            'callback'            => [$this, 'resume_hardening'],
            'permission_callback' => [$this, 'authenticate'],
        ]);
```

- [ ] **Step 5: Add the callbacks** — in the same file, insert the following block between the closing `}` of `get_security_header_snippets()` and the docblock `/** Get scan progress (lightweight polling endpoint).` of `get_scan_progress()`, with one blank line before and after:

```php
    // =========================================================================
    // SERVER HARDENING (.htaccess)
    // =========================================================================

    /**
     * Send a hardening result: always HTTP 200 with success/reason at the top level
     * (no `data` wrapper), and never cacheable — one host caches plugin REST GETs for 28 days.
     *
     * @param array $result LSM_Hardening::respond() shape.
     * @return WP_REST_Response
     */
    private function hardening_response($result) {
        $response = rest_ensure_response($result);
        $response->header('Cache-Control', 'no-store, private');
        return $response;
    }

    /**
     * Get the hardening status, computed from the .htaccess files.
     *
     * @return WP_REST_Response
     */
    public function get_hardening_status() {
        return $this->hardening_response(LSM_Hardening::instance()->respond(true, null, 'OK'));
    }

    /**
     * Turn a hardening rule on or off.
     *
     * @param WP_REST_Request $request Request with 'rule' and 'enabled'.
     * @return WP_REST_Response
     */
    public function set_hardening_rule($request) {
        $hardening = LSM_Hardening::instance();
        $rule      = $request->get_param('rule');
        $enabled   = $request->get_param('enabled');

        if (!is_string($rule) || !in_array($rule, LSM_Hardening::RULES, true)) {
            return $this->hardening_response($hardening->respond(false, 'invalid_rule', 'Unknown hardening rule.'));
        }

        // (bool) "false" is true — never decide on/off from a loose cast.
        if (!rest_is_boolean($enabled)) {
            return $this->hardening_response($hardening->respond(false, 'invalid_rule', 'Parameter "enabled" must be a boolean.'));
        }

        return $this->hardening_response($hardening->set_rule($rule, rest_sanitize_boolean($enabled)));
    }

    /**
     * Pause the archive rule for a download.
     *
     * @param WP_REST_Request $request Request with 'minutes' (15, 30 or 60).
     * @return WP_REST_Response
     */
    public function pause_hardening($request) {
        $hardening = LSM_Hardening::instance();
        $minutes   = $request->get_param('minutes');

        $is_whole_number = is_scalar($minutes) && (string) (int) $minutes === (string) $minutes;
        if (!$is_whole_number || !in_array((int) $minutes, LSM_Hardening::PAUSE_MINUTES, true)) {
            return $this->hardening_response($hardening->respond(false, 'invalid_minutes', 'The pause must be 15, 30 or 60 minutes.'));
        }

        return $this->hardening_response($hardening->pause((int) $minutes));
    }

    /**
     * Put the archive rule back now.
     *
     * @return WP_REST_Response
     */
    public function resume_hardening() {
        return $this->hardening_response(LSM_Hardening::instance()->resume());
    }
```

- [ ] **Step 6: Lint and run the whole suite**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && php -l landeseiten-maintenance/includes/class-lsm-api.php && vendor/bin/phpunit -c phpunit.xml.dist
```

Expected: `No syntax errors detected`, then PASS — `OK (199 tests, 1371 assertions)`.

- [ ] **Step 7: Commit**

```bash
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening add landeseiten-maintenance/includes/class-lsm-api.php tests/bootstrap.php tests/HardeningRestTest.php
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening commit -m "$(cat <<'EOF'
feat(api): add lsm/v1/hardening status, rule, pause and resume endpoints

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
EOF
)"
```

---

### Task 14: Wiring — require, init hook, deactivate(), scanner exclusions

Small edits in two existing files (line numbers are `master`'s and shift as you insert — locate each spot by its snippet). The load-time call goes into `Landeseiten_Maintenance::init()` (hooked on `init`, priority 0) **after** `LSM_Logger::init()` — never into the constructor or `init_security_filters()`, which run while plugins are still being included, before `pluggable.php`: crash recovery and auto-resume log, and `LSM_Logger::log()` calls `wp_get_current_user()`. `activate()` stays untouched — it re-runs after every self-update, and a plugin update must change nothing.

**Scanner exclusion — spec-literal and defensive, never an evasion name.** The spec says `lsm-probe-*` and `.htaccess.lsm-bak` are excluded from the plugin's suspicious-file collectors. The three collectors get one `continue` each, keyed on `LSM_Hardening::is_own_artifact()`, which (Task 7) matches **exactly** the names the engine can create: `lsm-probe-<16hex>.zip`, `lsm-probe-<16hex>.wpress`, `.htaccess.lsm-bak`. Today's collectors would not flag those three names anyway (they look for PHP extensions, double extensions and dot-prefixed PHP files), so the exclusion changes no finding — it keeps the spec's promise if the collectors ever grow. What it must **never** do is hide a file the engine cannot have created: the uploads PHP probe is never written, so `uploads/lsm-probe-….php`, `lsm-probe-….php.jpg` or a PHP-in-image `lsm-probe-….ico` is somebody else's file and has to be reported — this fleet has a live self-restoring kit, and a prefix-only exclusion would be a documented evasion name. `find_files_by_extension()` also feeds `find_php_in_images()`, which is why the exact match matters there too.

The main plugin file cannot be loaded without WordPress (it boots the whole plugin at the bottom), so its wiring is asserted on the source text; the scanner is exercised for real against files below the fake `WP_CONTENT_DIR`, and the presence of the three `continue` lines is asserted on the scanner's source.

**Files:**
- Modify: `landeseiten-maintenance/landeseiten-maintenance.php` — `includes()` after line 85, `init()` after line 222, `deactivate()` lines 425-429
- Modify: `landeseiten-maintenance/includes/class-lsm-security-scanner.php` — after lines 485, 512 and 542
- Modify: `tests/bootstrap.php` (append 1 line)
- Test: `tests/HardeningWiringTest.php`

**Interfaces:**
- Consumes: `LSM_Hardening::instance()` (Task 2), `on_init()` (Tasks 10-11), `deactivate()` (Task 12), `LSM_Hardening::is_own_artifact($name)` (Task 7); constants `WP_CONTENT_DIR`, `ABSPATH`, `DAY_IN_SECONDS`, `LSM_PLUGIN_DIR` and `LSM_Test_Env::$uploads_dir` (= `WP_CONTENT_DIR . '/uploads'` after `reset()`) from Task 1.
- Produces: nothing new — the feature is live in the plugin after this task.

- [ ] **Step 1: Load the scanner in the tests** — append to the end of `W/tests/bootstrap.php`:

```php
require_once LSM_PLUGIN_DIR . 'includes/class-lsm-security-scanner.php';
```

- [ ] **Step 2: Write the failing test** — `W/tests/HardeningWiringTest.php`:

```php
<?php

use PHPUnit\Framework\TestCase;

/**
 * Wiring into the main plugin file and the scanner.
 *
 * The main file cannot be loaded without WordPress (it boots the whole plugin),
 * so its wiring is asserted on the source. The scanner is exercised for real.
 */
class HardeningWiringTest extends TestCase {

    /** @var string[] files created below the fake WP_CONTENT_DIR */
    private $created = [];

    protected function setUp(): void {
        LSM_Test_Env::reset();
    }

    protected function tearDown(): void {
        foreach ($this->created as $file) {
            @unlink($file);
        }
    }

    private function main_file() {
        return file_get_contents(LSM_PLUGIN_DIR . 'landeseiten-maintenance.php');
    }

    /**
     * Source of one method of the main class, from its signature to the next docblock.
     */
    private function method_source($name) {
        $this->assertSame(1, preg_match('/function ' . $name . '\(\) \{.*?\n    \}\n/s', $this->main_file(), $m), $name . '() not found');
        return $m[0];
    }

    private function touch_file($path, $content = 'x') {
        file_put_contents($path, $content);
        $this->created[] = $path;
    }

    public function test_the_class_file_is_required_with_the_other_includes() {
        $this->assertStringContainsString(
            "require_once LSM_PLUGIN_DIR . 'includes/class-lsm-hardening.php';",
            $this->method_source('includes')
        );
    }

    public function test_load_time_work_is_hooked_on_init_after_the_logger() {
        $init = $this->method_source('init');

        $logger    = strpos($init, 'LSM_Logger::init();');
        $hardening = strpos($init, 'LSM_Hardening::instance()->on_init();');
        $this->assertNotFalse($logger);
        $this->assertNotFalse($hardening);
        $this->assertGreaterThan($logger, $hardening, 'LSM_Logger::log() needs pluggable.php, so never earlier than init');

        $this->assertStringNotContainsString('LSM_Hardening', $this->method_source('init_security_filters'));
        $this->assertStringNotContainsString('LSM_Hardening', $this->method_source('__construct'));
    }

    public function test_deactivation_removes_the_blocks_and_activation_never_writes() {
        $this->assertStringContainsString('LSM_Hardening::instance()->deactivate();', $this->method_source('deactivate'));
        // activate() re-runs after every self-update: plugin updates must change nothing.
        $this->assertStringNotContainsString('LSM_Hardening', $this->method_source('activate'));
    }

    public function test_no_uninstall_handler_in_v1() {
        $this->assertFileDoesNotExist(LSM_PLUGIN_DIR . 'uninstall.php');
        $this->assertStringNotContainsString('register_uninstall_hook', $this->main_file());
    }

    public function test_the_three_file_collectors_skip_the_engines_own_artifacts() {
        $source = file_get_contents(LSM_PLUGIN_DIR . 'includes/class-lsm-security-scanner.php');

        foreach (['find_files_by_extension', 'find_double_extensions', 'find_hidden_files'] as $collector) {
            $this->assertSame(1, preg_match('/private function ' . $collector . '\(.*?\n    \}\n/s', $source, $m), $collector . '() not found');
            $this->assertStringContainsString('LSM_Hardening::is_own_artifact(', $m[0], $collector);
        }
    }

    public function test_the_scanner_never_hides_a_file_that_is_only_named_like_a_probe() {
        $uploads = WP_CONTENT_DIR . '/uploads';
        // The uploads PHP probe is never created: these can only be somebody else's files.
        $this->touch_file($uploads . '/lsm-probe-0123456789abcdef.php', '<?php // not ours');
        $this->touch_file($uploads . '/lsm-probe-0123456789abcdef.ico', '<?php // not ours');
        $this->touch_file(WP_CONTENT_DIR . '/lsm-probe-0123456789abcdef.php.zip');
        $this->touch_file($uploads . '/evil.php', '<?php');
        $this->touch_file(WP_CONTENT_DIR . '/invoice.php.jpg');
        // What a killed run of the engine really leaves behind.
        $this->touch_file(WP_CONTENT_DIR . '/lsm-probe-0123456789abcdef.zip');
        $this->touch_file(WP_CONTENT_DIR . '/lsm-probe-0123456789abcdef.wpress');
        $this->touch_file(WP_CONTENT_DIR . '/.htaccess.lsm-bak', "php_value auto_prepend_file x.php\n");

        $scanner  = new LSM_Security_Scanner();
        $findings = $scanner->public_detect_suspicious_files()['findings'];
        $files    = array_column($findings, 'file');

        $this->assertContains('wp-content/uploads/lsm-probe-0123456789abcdef.php', $files, 'PHP file in uploads');
        $this->assertContains('wp-content/uploads/lsm-probe-0123456789abcdef.ico', $files, 'PHP code inside image');
        $this->assertContains('wp-content/lsm-probe-0123456789abcdef.php.zip', $files, 'double extension');
        $this->assertContains('wp-content/uploads/evil.php', $files);
        $this->assertContains('wp-content/invoice.php.jpg', $files);

        $this->assertNotContains('wp-content/lsm-probe-0123456789abcdef.zip', $files);
        $this->assertNotContains('wp-content/lsm-probe-0123456789abcdef.wpress', $files);
        $this->assertNotContains('wp-content/.htaccess.lsm-bak', $files);
    }
}
```

- [ ] **Step 3: Run and watch it fail**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && vendor/bin/phpunit -c phpunit.xml.dist --filter HardeningWiringTest
```

Expected: FAIL — `Tests: 6, Assertions: 19, Failures: 4.`: the three main-file source assertions fail (`Failed asserting that '…' contains "require_once LSM_PLUGIN_DIR . 'includes/class-lsm-hardening.php';"`, …) and `test_the_three_file_collectors_skip_the_engines_own_artifacts` fails with `find_files_by_extension` / `Failed asserting that '…' contains "LSM_Hardening::is_own_artifact(".` Two tests pass already: `test_no_uninstall_handler_in_v1`, and — on purpose — `test_the_scanner_never_hides_a_file_that_is_only_named_like_a_probe`: today's collectors do not flag the three genuine artifact names, and they must go on reporting the impostors after Steps 7-9. That test is the guard that keeps the exclusion from ever growing into an evasion name; it has to stay green before **and** after.

- [ ] **Step 4: Require the class** — in `P/landeseiten-maintenance.php`, `includes()`, find (line 85):

```php
        require_once LSM_PLUGIN_DIR . 'includes/class-lsm-media.php';
```

and replace it with:

```php
        require_once LSM_PLUGIN_DIR . 'includes/class-lsm-media.php';
        require_once LSM_PLUGIN_DIR . 'includes/class-lsm-hardening.php';
```

- [ ] **Step 5: Hook the load-time work** — same file, `init()`, find (lines 221-222):

```php
        // Initialize PHP error handling
        LSM_Php_Errors::init();
```

and replace it with:

```php
        // Initialize PHP error handling
        LSM_Php_Errors::init();

        // Managed .htaccess hardening: crash recovery and pause expiry. Must stay on `init`,
        // after LSM_Logger::init() — both log, and the logger needs pluggable.php.
        LSM_Hardening::instance()->on_init();
```

- [ ] **Step 6: Clean up on deactivation** — same file, `deactivate()`, find (lines 426-429):

```php
        $settings['maintenance_mode'] = false;
        update_option('lsm_settings', $settings);

        flush_rewrite_rules();
```

and replace it with:

```php
        $settings['maintenance_mode'] = false;
        update_option('lsm_settings', $settings);

        // A deactivated plugin can neither pause nor undo: take the managed .htaccess blocks out
        LSM_Hardening::instance()->deactivate();

        flush_rewrite_rules();
```

Steps 7-9 are the spec-literal, defensive exclusion described above: one `continue` per collector, keyed on the **exact-name** check. They change no finding today; do not "simplify" the check to a prefix match in the scanner either.

- [ ] **Step 7: Scanner, PHP-in-uploads and PHP-in-images pass** — in `P/includes/class-lsm-security-scanner.php`, `find_files_by_extension()`, find (lines 484-488):

```php
            if ($this->is_timed_out()) break;
            if (!$file->isFile()) continue;

            $ext = strtolower($file->getExtension());
            if (in_array($ext, $extensions)) {
```

and replace it with:

```php
            if ($this->is_timed_out()) break;
            if (!$file->isFile()) continue;
            if (LSM_Hardening::is_own_artifact($file->getFilename())) continue;

            $ext = strtolower($file->getExtension());
            if (in_array($ext, $extensions)) {
```

- [ ] **Step 8: Scanner, double extensions** — same file, `find_double_extensions()`, find (lines 512-513):

```php
            $name = $file->getFilename();
            // Check for patterns like "something.php.jpg"
```

and replace it with:

```php
            $name = $file->getFilename();
            if (LSM_Hardening::is_own_artifact($name)) continue;
            // Check for patterns like "something.php.jpg"
```

- [ ] **Step 9: Scanner, hidden files** — same file, `find_hidden_files()`, find (lines 542-543):

```php
            $name = $file->getFilename();
            if ($name[0] === '.' && !in_array($name, $skip_hidden)) {
```

and replace it with:

```php
            $name = $file->getFilename();
            if (LSM_Hardening::is_own_artifact($name)) continue;
            if ($name[0] === '.' && !in_array($name, $skip_hidden)) {
```

- [ ] **Step 10: Lint and run the whole suite**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && php -l landeseiten-maintenance/landeseiten-maintenance.php && php -l landeseiten-maintenance/includes/class-lsm-security-scanner.php && vendor/bin/phpunit -c phpunit.xml.dist
```

Expected: twice `No syntax errors detected`, then PASS — `OK (205 tests, 1401 assertions)`.

- [ ] **Step 11: Confirm nothing touched `activate()` or `class-lsm-actions.php`**

```bash
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening diff master --stat
```

Expected: `class-lsm-actions.php` is not in the list; of the pre-existing plugin files only `landeseiten-maintenance.php`, `includes/class-lsm-api.php` and `includes/class-lsm-security-scanner.php` are (plus the new `includes/class-lsm-hardening.php` and the dev tooling).

- [ ] **Step 12: Commit**

```bash
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening add landeseiten-maintenance/landeseiten-maintenance.php landeseiten-maintenance/includes/class-lsm-security-scanner.php tests/bootstrap.php tests/HardeningWiringTest.php
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening commit -m "$(cat <<'EOF'
feat(hardening): wire the engine into plugin load, deactivation and the scanner

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
EOF
)"
```

---

### Task 15: Pre-release fleet check of the adoption matcher

The spec demands that, before release, the fleet's `wp-content/.htaccess` and `uploads/.htaccess` contents are checked once against the matcher — on the motivating sites a manual rule the matcher misses would make "Pause for download" impossible exactly where it is needed. This task builds the checker (dev tooling, never shipped); running it against real fleet data is a checkbox in Task 17. It needs no WordPress and no PHPUnit: the class file only insists on `ABSPATH`.

Input convention: a directory with one file per site and target, named `<site>.content.htaccess` or `<site>.uploads.htaccess`. The platform does not store what the scan collector ships (`ScannerCollectorController` hands `htaccess_files` straight to `ScannerEngine::scanHtaccess()`), so the files are collected over SSH — read-only, by a human, with the recipe in Task 17 A. No agent task contacts a live site.

**Files:**
- Create: `tests/tools/check-manual-blocks.php`
- Test: `tests/ManualBlockToolTest.php`

**Interfaces:**
- Consumes: `parse_markers($content)` (Task 3), `find_manual_blocks($content, $rule)` (Task 4), `rules_of($target)` (Task 2), `LSM_Htaccess_Fixtures` (Task 4).
- Produces: CLI `php tests/tools/check-manual-blocks.php <dir>` — prints `ADOPTABLE`, `MANAGED`, `UNRECOGNISED` or `CORRUPT` lines; exit code 0 = every manual-looking rule is recognised, 1 = at least one `UNRECOGNISED`/`CORRUPT`, 2 = usage error.

- [ ] **Step 1: Write the failing test** — `W/tests/ManualBlockToolTest.php`:

```php
<?php

use PHPUnit\Framework\TestCase;

/**
 * The pre-release fleet check (tests/tools/check-manual-blocks.php).
 */
class ManualBlockToolTest extends TestCase {

    /** @var string */
    private $dir;

    protected function setUp(): void {
        $this->dir = rtrim(sys_get_temp_dir(), '/\\') . '/lsm-fleet-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void {
        array_map('unlink', glob($this->dir . '/*'));
        rmdir($this->dir);
    }

    private function run_tool() {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/tests/tools/check-manual-blocks.php') . ' ' . escapeshellarg($this->dir), $output, $code);
        return [$code, $output];
    }

    public function test_recognised_blocks_pass() {
        file_put_contents($this->dir . '/midnightblue-duck.content.htaccess', LSM_Htaccess_Fixtures::MIDNIGHTBLUE_CONTENT);
        file_put_contents($this->dir . '/drjung.ch.uploads.htaccess', LSM_Htaccess_Fixtures::AUDITED_UPLOADS);
        file_put_contents($this->dir . '/plain.content.htaccess', "Options -Indexes\n");

        list($code, $output) = $this->run_tool();

        $this->assertSame(0, $code);
        $this->assertSame([
            'ADOPTABLE     drjung.ch.uploads.htaccess  block_uploads_php',
            'ADOPTABLE     midnightblue-duck.content.htaccess  block_archives',
            'ADOPTABLE     midnightblue-duck.content.htaccess  block_debug_log',
        ], $output);
    }

    public function test_a_variant_the_matcher_does_not_know_fails_the_gate() {
        file_put_contents($this->dir . '/variant.content.htaccess', "<FilesMatch \"\\.(wpress|zip)$\">\nRequire all denied\n</FilesMatch>\n");

        list($code, $output) = $this->run_tool();

        $this->assertSame(1, $code);
        $this->assertSame(['UNRECOGNISED  variant.content.htaccess  block_archives'], $output);
    }
}
```

- [ ] **Step 2: Run and watch it fail**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && vendor/bin/phpunit -c phpunit.xml.dist --filter ManualBlockToolTest
```

Expected: FAIL — `Tests: 2, Assertions: 3, Failures: 2.`: `Failed asserting that 1 is identical to 0.` and `Failed asserting that two arrays are identical.` (the script does not exist yet: PHP answers `Could not open input file` and exits 1).

- [ ] **Step 3: Implement** — `W/tests/tools/check-manual-blocks.php`:

```php
<?php
/**
 * One-off release gate: run the adoption matcher over .htaccess files pulled from the fleet.
 *
 * Usage:   php tests/tools/check-manual-blocks.php <dir>
 * Input:   one file per site and target, named <site>.content.htaccess or <site>.uploads.htaccess
 * Output:  one line per file and rule: ADOPTABLE (recognised manual block), MANAGED (already ours),
 *          UNRECOGNISED (mentions what the rule is about, but the matcher does not recognise it), or nothing.
 * Exit:    1 when at least one UNRECOGNISED line was printed — read those files before releasing.
 */

// The matcher needs no WordPress: the class file only insists on ABSPATH.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
require dirname(__DIR__, 2) . '/landeseiten-maintenance/includes/class-lsm-hardening.php';

$dir = isset($argv[1]) ? rtrim($argv[1], '/') : '';
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "Usage: php tests/tools/check-manual-blocks.php <dir>\n");
    exit(2);
}

// What a hand-written rule for each key would at least mention.
$hints = [
    'block_archives'    => '/wpress/i',
    'block_debug_log'   => '/debug\.log/i',
    'block_uploads_php' => '/<Files(Match)?\s[^>]*php/i',
];

$hardening    = new LSM_Hardening();
$unrecognised = 0;

foreach (glob($dir . '/*.htaccess') ?: [] as $file) {
    if (!preg_match('/\.(content|uploads)\.htaccess$/', $file, $m)) {
        continue;
    }
    $content = (string) file_get_contents($file);
    $managed = $hardening->parse_markers($content);
    $outside = $managed['found']
        ? substr($content, 0, $managed['start']) . (string) substr($content, $managed['end'])
        : $content;

    foreach ($hardening->rules_of($m[1]) as $rule) {
        if (!empty($hardening->find_manual_blocks($content, $rule))) {
            echo 'ADOPTABLE     ' . basename($file) . '  ' . $rule . "\n";
        } elseif (preg_match($hints[$rule], $outside)) {
            echo 'UNRECOGNISED  ' . basename($file) . '  ' . $rule . "\n";
            $unrecognised++;
        } elseif ($managed['found']) {
            echo 'MANAGED       ' . basename($file) . '  ' . $rule . "\n";
        }
    }
    if ($managed['corrupt']) {
        echo 'CORRUPT       ' . basename($file) . "  markers\n";
        $unrecognised++;
    }
}

exit($unrecognised > 0 ? 1 : 0);
```

- [ ] **Step 4: Lint and run the whole suite**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && php -l tests/tools/check-manual-blocks.php && vendor/bin/phpunit -c phpunit.xml.dist
```

Expected: `No syntax errors detected`, then PASS — `OK (207 tests, 1405 assertions)`.

- [ ] **Step 5: Commit**

```bash
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening add tests/tools/check-manual-blocks.php tests/ManualBlockToolTest.php
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening commit -m "$(cat <<'EOF'
test(hardening): add the pre-release fleet check for the adoption matcher

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
EOF
)"
```

---

### Task 16: Version 2.10.0 and the verification build

The version lives in exactly two places and they have drifted apart once before (`c632c98`): header line 5 and constant line 22 of `P/landeseiten-maintenance.php`. Everything else reads the constant (`class-lsm-api.php`, `class-lsm-updater.php`, the enqueue versions); the updater's hardcoded `'requires' => '5.8'`, `'tested'`, `'requires_php' => '7.4'` are not the plugin version — leave them. The platform recognises hardening-capable plugins by the route existing; older plugins answer 404 and show as `plugin_outdated` with `min_version: '2.10.0'`.

**This task stops at "ready to release" and contains no release command.** `build-release.sh` commits with `git add -A`, tags, **pushes the checked-out branch** and creates the GitHub release that the whole fleet auto-updates from — never run it, or anything else that tags, pushes or publishes, as part of this task. Releasing is a human job, described in the section "Release runbook — HUMAN ONLY" after Task 17, and happens only after Task 17 is fully ticked.

**Files:**
- Modify: `landeseiten-maintenance/landeseiten-maintenance.php` lines 5 and 22
- Test: `tests/VersionTest.php`

**Interfaces:**
- Consumes: `LSM_PLUGIN_DIR` (Task 1).
- Produces: plugin version `2.10.0`; the verification zip `/Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening/landeseiten-maintenance-2.10.0-rc.zip` (inside the worktree, untracked: the repo's `.gitignore` has `*.zip`) used by Task 17.

- [ ] **Step 1: Write the failing test** — `W/tests/VersionTest.php`:

```php
<?php

use PHPUnit\Framework\TestCase;

/**
 * The plugin header and the LSM_VERSION constant have drifted apart once before
 * (c632c98). The self-updater compares the release tag with the constant.
 */
class VersionTest extends TestCase {

    public function test_header_and_constant_agree_and_are_at_least_2_10_0() {
        $source = file_get_contents(LSM_PLUGIN_DIR . 'landeseiten-maintenance.php');

        $this->assertSame(1, preg_match('/^ \* Version: (\S+)$/m', $source, $header));
        $this->assertSame(1, preg_match("/define\('LSM_VERSION', '([^']+)'\);/", $source, $constant));

        $this->assertSame($header[1], $constant[1]);
        $this->assertTrue(version_compare($constant[1], '2.10.0', '>='), 'hardening needs 2.10.0: the platform reports older plugins as plugin_outdated');
    }
}
```

- [ ] **Step 2: Run and watch it fail**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && vendor/bin/phpunit -c phpunit.xml.dist --filter VersionTest
```

Expected: FAIL — `Tests: 1, Assertions: 4, Failures: 1.`: `hardening needs 2.10.0: the platform reports older plugins as plugin_outdated` / `Failed asserting that false is true.`

- [ ] **Step 3: Bump the header** — in `P/landeseiten-maintenance.php` line 5, find:

```php
 * Version: 2.9.4
```

and replace it with:

```php
 * Version: 2.10.0
```

- [ ] **Step 4: Bump the constant** — same file, line 22, find:

```php
define('LSM_VERSION', '2.9.4');
```

and replace it with:

```php
define('LSM_VERSION', '2.10.0');
```

- [ ] **Step 5: Lint everything the feature touched and run the whole suite**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && for f in landeseiten-maintenance/landeseiten-maintenance.php landeseiten-maintenance/includes/class-lsm-hardening.php landeseiten-maintenance/includes/class-lsm-api.php landeseiten-maintenance/includes/class-lsm-security-scanner.php; do php -l "$f"; done && vendor/bin/phpunit -c phpunit.xml.dist
```

Expected: four times `No syntax errors detected`, then PASS — `OK (208 tests, 1409 assertions)`.

- [ ] **Step 6: Commit**

```bash
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening add landeseiten-maintenance/landeseiten-maintenance.php tests/VersionTest.php
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening commit -m "$(cat <<'EOF'
chore(release): bump plugin to 2.10.0

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
EOF
)"
```

- [ ] **Step 7: Build the verification zip (no commit, no tag, no push)** — same zip layout as `build-release.sh`, written **inside the worktree** (the repo's `.gitignore` already ignores `*.zip`, so it can never be committed; nothing outside the worktree is touched):

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && rm -f landeseiten-maintenance-2.10.0-rc.zip && COPYFILE_DISABLE=1 zip -rq landeseiten-maintenance-2.10.0-rc.zip landeseiten-maintenance/ -x "*.DS_Store" -x "*/.DS_Store" -x "*/.git/*" -x "*/node_modules/*" -x "*/.env"
unzip -Z1 /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening/landeseiten-maintenance-2.10.0-rc.zip | grep -c "^landeseiten-maintenance/includes/class-lsm-hardening.php$"
unzip -Z1 /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening/landeseiten-maintenance-2.10.0-rc.zip | grep -vc "^landeseiten-maintenance/" || true
unzip -Z1 /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening/landeseiten-maintenance-2.10.0-rc.zip | grep -cE "phpunit|composer\.json|/tests/" || true
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening status --short
```

Expected: `1`, `0`, `0` — the engine is in the zip, everything in it sits below the top-level folder `landeseiten-maintenance/`, and none of the dev tooling is included — and `git status --short` prints **nothing** (the zip is ignored, everything else is committed).

- [ ] **Step 8: Hand over.** The branch is ready for review and for the human checklist in Task 17. This is the last step an agent executes in this plan: Task 17 and the Release runbook are human-only — report them as "human-only" and stop.

---

### Task 17: Manual real-site verification before release (human checklist)

**AGENTS: do not execute anything in this section. It contacts live client sites / publishes a fleet-wide release. Report "human-only" and stop.** (That includes the platform's live-site tools — SSO login links, plugin updates, maintenance mode, bulk actions — SSH/SFTP to any site, and HTTP requests to any client URL.)

For a human. No code is written in this task. Verify in this order: **(1) landeseiten.de**, **(2) one Apache client site**, **(3) one Hostinger/LiteSpeed site**, if one is available **(4) one nginx-fronted site** (there an honest `rule_ineffective` is the *expected* result), and — mandatory — **(5) one Cloudflare-proxied site (orange cloud)**; it may be the same site as 2 or 3, in which case section F is simply run there as well. Stop at the first site that misbehaves; fix, rebuild the verification zip (Task 16, Step 7), start that site again.

How to drive it: from a local or staging platform running the `feature/htaccess-hardening` branches of lsm-api and lsm-web — project → Security → card "Server hardening (.htaccess)" (wording below). If those branches are not ready, make the same four calls (status, rule, pause, resume) directly against the site's `lsm/v1/hardening/*` routes with its `X-LSM-Key` header; the JSON shows the same fields the card renders.

**A. Before touching any site**

- [ ] The whole suite is green on the branch and Task 16, Step 7 produced `/Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening/landeseiten-maintenance-2.10.0-rc.zip`.
- [ ] Fleet matcher gate, step 1 — collect (read-only: two `cat`s per site, nothing is written on any server). Write `~/lsm-fleet-htaccess/sites.tsv`, one line per site: `<label>`, a TAB, `<ssh target>`, a TAB, `<absolute WordPress root>` (sites with SSH; for the Hostinger accounts the key is `~/.ssh/landeseiten_maintainance` — put it into `~/.ssh/config` for those hosts or add `-i` below). **At minimum every site the audit procedure hardened by hand must be in the list** — drjung.ch, physioteam-dieterich.de, every site of the midnightblue-duck Hostinger account, and every other site with a `wp-audits/` / `wordpress-security-audit/audits/` folder — because those are the sites where a manual rule the matcher misses makes "Pause for download" impossible. Then run:

```bash
# sites.tsv: <label>\t<ssh target>\t<absolute WP root>   — read-only collection, run by a human
mkdir -p ~/lsm-fleet-htaccess
while IFS=$'\t' read -r label target root; do
  ssh -n "$target" "cat '$root/wp-content/.htaccess' 2>/dev/null"         > ~/lsm-fleet-htaccess/"$label".content.htaccess
  ssh -n "$target" "cat '$root/wp-content/uploads/.htaccess' 2>/dev/null" > ~/lsm-fleet-htaccess/"$label".uploads.htaccess
done < ~/lsm-fleet-htaccess/sites.tsv
find ~/lsm-fleet-htaccess -name '*.htaccess' -size 0 -delete        # file absent on the site
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening && php tests/tools/check-manual-blocks.php ~/lsm-fleet-htaccess; echo "exit $?"
```

- [ ] Fleet matcher gate, step 2 — judge. `exit 0` — or every `UNRECOGNISED` / `CORRUPT` line was read by a person and either the matcher was extended (new fixture + near-miss test in Task 4's files, suite green again, verification zip rebuilt) or the site was noted as "will show Off/foreign, handle by hand". Sites without SSH are listed in the PR as "not checked — will show Off/Manual, verify on first enable". The collected directory stays on the releasing person's machine; it is never committed.

**B. On every site (1-5), in this order**

- [ ] Install the verification zip: wp-admin → Plugins → Add New → Upload Plugin → choose the zip → "Replace current with uploaded". The Plugins list shows version 2.10.0. The site looks and works exactly as before (nothing is written on update).
- [ ] Over SFTP/SSH, keep a copy of the current `wp-content/.htaccess` and `wp-content/uploads/.htaccess` (or note that they do not exist).
- [ ] Open the card / read status: plugin version 2.10.0; the server label matches reality (Apache / LiteSpeed); all three rules show **Off** — or **Manual** where an earlier audit left a hand-written rule; nothing shows **Not supported** (if it does, read the reason tooltip: `not_writable` is a permissions problem to fix, `unknown_server` on site 4 may be legitimate).
- [ ] Turn on "block archives" (on a Manual rule the action is labelled **Adopt**). Expect the confirm dialog, then the green toast "“Block backup & archive downloads” is on — applied and verified" and the tag **On**. With archives in the Media Library the dialog warned "N downloadable archives in the media library will stop working" and N is plausible (the archive-count warning is shown for Turn on and Re-apply, not for Adopt).
- [ ] Turn on "block debug.log" and "block PHP in uploads" the same way. Each ends with **On**.
- [ ] Over SFTP/SSH: each of the two files contains exactly one `# BEGIN LSM-HARDENING` … `# END LSM-HARDENING` block; everything that was in the file before is still there byte for byte; an adopted manual block is gone; there is **no** `.htaccess.lsm-bak` and **no** `lsm-probe-*` file in `wp-content/` or `wp-content/uploads/`.
- [ ] Anonymous cached page view: in a private browser window open the homepage and one inner page. They render completely — styles, scripts, images, fonts. On sites with WP Rocket or another page cache, confirm the response really was a cache hit (cache comment at the end of the page source or the cache header) and still renders.
- [ ] Logged in: wp-admin renders, the Media Library shows thumbnails, the page builder of the site opens.
- [ ] Anonymous, from outside: the URL of a real backup under `wp-content/ai1wm-backups/` answers 403; `/wp-content/debug.log` answers 403; any `/wp-content/uploads/….php` name answers 403; a normal uploaded image answers 200.
- [ ] Response caching: two status reads around a change show different data, and the status response carries `Cache-Control: no-store, private` (matters on the host that caches REST GETs for 28 days).

**C. Pause, real download, auto-resume (every site)**

- [ ] In wp-admin → All-in-One WP Migration → Backups, click Download on a real backup while the rule is On: it fails with 403. (This is the problem the feature exists for.)
- [ ] On the card choose a duration beside the button (take 15 minutes for the test) and click **Pause for download**. The confirm text says backups are publicly downloadable for N minutes and that an interrupted download resumed after that will fail. Result: tag **Paused** with a running countdown.
- [ ] Download the same backup again: it starts, completes, and the file size matches the size AI1WM shows.
- [ ] During the pause `/wp-content/debug.log` still answers 403 (only the archive rule is out) and `wp-content/.htaccess` still holds the block with the other rule.
- [ ] Pause while paused. The card offers no second pause (in state Paused it shows only the countdown and "Re-enable now"), so send the call through the platform so its pause row is superseded too: `POST /api/v1/projects/<id>/lsm/hardening/pause` with body `{"minutes":30}` and your session token. Only if the platform branches are not running, call the plugin directly: `curl -s -X POST -H 'X-LSM-Key: <key>' -H 'Content-Type: application/json' -d '{"minutes":30}' https://<site>/wp-json/lsm/v1/hardening/pause`. Expect `success: true` and `status.pause_until` = now + 1800. After reloading the card the countdown shows the new duration, and the modification time of `wp-content/.htaccess` did not change.
- [ ] Auto-resume: do **not** click "Re-enable now". Let the countdown run out, then make one request that **really runs WordPress** — the light path is registered on `init`, and on the 125 of 141 fleet sites with WP Rocket (and behind any rewrite- or advanced-cache page cache) an anonymous front-end page view is served from the cache without loading a single plugin, so "open any page" triggers nothing. Any of these works: a wp-admin page; the homepage with a random query string (`/?lsm_nocache=<random>` — query strings bypass the page cache); or simply wait for the platform's next uptime ping (it requests `/wp-json/lsm/v1/health`, which loads the plugin). Then refresh the card within about a minute. Expect: tag **On**, no pause, and in the `GET /api/v1/projects/<id>/lsm/hardening` response (DevTools → Network) `status.last_result` is `{action: 'auto_resume', ok: true}`; note it if `status.last_result.warnings` contains `unverified` (loopback blocked on that host — the block is kept on purpose). The backup URL answers 403 again. The page view that triggered it was not noticeably slow.
- [ ] If the card still shows Paused with the overdue warning after two such **uncached** requests at least 5 minutes apart (the 300 s throttle allows one attempt per 5 minutes): read `status.last_result.reason` in that same response (the card does not show last_result), stop, investigate before release. A cached page view does not count as an attempt — check that `last_result.at` moved before you call it a failure.
- [ ] Pause again and this time click **Re-enable now**: **On** within seconds, toast "Archive block is back on".

**D. The LiteSpeed question (site 3 only) — decide before release**

- [ ] With "block archives" On, request a name that does not exist, anonymously: `/wp-content/does-not-exist-12345.zip`.
- [ ] Answer **403**: LiteSpeed authorises before it looks for the file, like Apache. The URL-only probes (debug.log, the never-created uploads `.php` name) are valid on LiteSpeed. Write "LiteSpeed: 403 for non-existent covered names" into the PR.
- [ ] Answer **404**: LiteSpeed looks for the file first. Then "block PHP in uploads" (and "block debug.log" on a site without a debug.log) will already have failed above with `rule_ineffective`. **Do not release.** The uploads/debug.log probes need real files on LiteSpeed — that is a design decision for the team (spec Part 4), to be taken now, not after the release.

**E. nginx-fronted site (site 4, if available)**

- [ ] Turn on "block archives". Expected and correct: it fails with `rule_ineffective` and the message names the `.zip` probe (the front-end nginx serves static files itself); the sticky line "Could not be applied on this server: …" appears under the rule; `wp-content/.htaccess` is byte-identical to before; no leftover files.
- [ ] If it says "Applied and verified" instead, prove it by hand: a real `.zip` placed under `wp-content/` answers 403 from outside. If it answers 200, stop — the self-test reported a false success.

**F. Cloudflare-proxied site (site 5) — enable and "Re-enable now" behind an edge cache**

Cloudflare caches `.zip` by extension (a 200 for about two hours, keyed by URL + query string) and ignores the request's `Cache-Control: no-cache`. The self-test fetches every probe before and after the write; this section proves that the "after" fetch is not answered from the edge.

- [ ] Confirm the site really is proxied and caches `.zip` under `wp-content`: upload a small file `wp-content/lsm-cf-check.zip` over SFTP and request it twice from outside: `curl -sI https://<site>/wp-content/lsm-cf-check.zip | grep -i -E '^(server|cf-cache-status):'` → `server: cloudflare`, then `cf-cache-status: MISS` (or `EXPIRED`) followed by `HIT` on the second request. (With `DYNAMIC` / `BYPASS` a cache rule excludes the path — pick another site for this section.)
- [ ] Enable behind the edge: if section B has not been run on this site yet, its "Turn on block archives" step is this check; otherwise turn "block archives" Off (admin only) and straight On again. Expect **"Applied and verified"** — not `rule_ineffective`. A `rule_ineffective` naming the `.zip` probe here means the "after" probe was answered by the edge: stop, do not release.
- [ ] **Pause for download**, then **Re-enable now**: "Applied and verified", tag **On**. (The "before" fetch of a resume is a 200 during the pause — the case an edge cache would poison.)
- [ ] Expected and documented, not a failure: `https://<site>/wp-content/lsm-cf-check.zip` can go on answering 200 with `cf-cache-status: HIT` for up to ~2 h although the origin now denies it (the spec's "already-cached copies … stay available until they expire"). Prove the origin: the same URL with a fresh query string (`?x=<random>`) answers **403**.
- [ ] Delete `wp-content/lsm-cf-check.zip` again.

**G. Forced broken rule — on landeseiten.de only (our own site, never on a client site)**

- [ ] Turn one rule Off first (you need something to turn on). Keep a copy of `wp-content/.htaccess`.
- [ ] Variant 1 (Apache): over SFTP, in the site's copy of `includes/class-lsm-hardening.php`, change the text `Require all denied` inside `rule_lines()` to `Require all deniedX` (an invalid directive is a 500 on Apache). Turn the rule on. Expect: failure `asset_broken`, message ends with "The change was rolled back."; the site never stayed broken (reload the homepage in a private window right after); `.htaccess` is byte-identical to the copy; no `.htaccess.lsm-bak`, no `lsm-probe-*`; the rule shows Off with the sticky failure line.
- [ ] Variant 2 (any server — use this one on LiteSpeed, which ignores unknown directives): instead, change the first `zip` in the `block_archives` pattern inside `definitions()` to `zipx`. Turn "block archives" on. Expect: failure `rule_ineffective` naming the `.zip` probe, rolled back, file byte-identical, no leftovers.
- [ ] Put the original plugin file back (re-upload the verification zip as in B). Turn the rule on again: "Applied and verified", the sticky failure line disappears.
- [ ] Deactivation: deactivate the plugin in wp-admin. Both managed blocks are gone from the two files, everything else in them is intact. Reactivate: all three rules show **Off** (truthfully). Turn them on again.

**H. Sign-off**

- [ ] Every box above is ticked for sites 1-3 and 5 (and 4 if available); the LiteSpeed answer from D and the Cloudflare result from F are written into the PR; on each client site the rules were left in the state agreed for that site.
- [ ] Only now: hand the branch to the person who runs the Release runbook below.

---

## Release runbook — HUMAN ONLY, never dispatched to an implementer

**AGENTS: do not execute anything in this section. It contacts live client sites / publishes a fleet-wide release. Report "human-only" and stop.** This section is not a task: it has no checkbox steps for an implementer, it is never handed to a subagent, and nothing in Tasks 1-16 depends on it.

**For the human who releases, after the branch is reviewed and Task 17 is fully ticked.** `./build-release.sh 2.10.0` tags `v2.10.0`, pushes `master` and publishes the GitHub release the whole fleet auto-updates from within 12 h — there is no undo short of releasing 2.10.1. Run from a clean checkout of `master`; the main `lsm-wp` working tree is dirty on another branch and `build-release.sh` stages *everything* with `git add -A`, so use a dedicated worktree:

```bash
# HUMAN ONLY — publishes a fleet-wide release. Agents: stop, report "human-only".
# 1. a clean master checkout that cannot pick up anybody's uncommitted work
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp worktree add /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-release master
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-release
git pull --ff-only origin master
git status --short                      # must print nothing

# 2. merge the feature (the repo's habit: a merge commit that names the branch)
git merge --no-ff feature/htaccess-hardening -m "Merge feature/htaccess-hardening: managed .htaccess hardening (2.10.0)"
composer install --no-interaction --no-progress && vendor/bin/phpunit -c phpunit.xml.dist

# 3. release: sets the version (already 2.10.0), zips landeseiten-maintenance/, tags v2.10.0,
#    pushes master + tags, creates the GitHub release with the zip. Needs `gh auth status` OK.
./build-release.sh 2.10.0

# 4. replace the script's one-line notes with real ones
gh release edit v2.10.0 --notes "$(cat <<'EOF'
Managed .htaccess hardening (all rules default to OFF - updating changes nothing on any site).

- New: three server-level deny rules the platform can turn on per site: backup/archive files under wp-content (.wpress, .sql, .zip, .tar, .tgz, .bak and their .gz forms), wp-content/debug.log, and PHP execution in uploads.
- Every change is written between "# BEGIN LSM-HARDENING" / "# END LSM-HARDENING" markers, snapshotted, self-tested over loopback (plugin asset, anonymous homepage, real probe files) and rolled back automatically if anything breaks or the rule has no effect.
- "Pause for download": take the archive rule out for 15/30/60 minutes (All-in-One WP Migration downloads); it comes back by itself, fail-closed.
- Hand-written rules from earlier audits are recognised and can be adopted.
- New REST routes: lsm/v1/hardening/status|rule|pause|resume. Requires platform support to be used; older platforms are unaffected.
- The plugin's own scanner skips the engine's short-lived files (lsm-probe-<random>.zip / .wpress, .htaccess.lsm-bak) and nothing else: a PHP file that merely carries the prefix is still reported.
EOF
)"

# 5. keep the versioned copy next to the earlier ones, then tidy up
cp landeseiten-maintenance.zip /Users/bmarkovic/Documents/Projects/LSMPlatform/landeseiten-maintenance-2.10.0.zip
cd /Users/bmarkovic/Documents/Projects/LSMPlatform && git -C lsm-wp worktree remove lsm-wp-release
```

After the release: sites see 2.10.0 within 12 h (the updater caches its check in the transient `lsm_github_update_check`; the platform proxy `/v1/plugin/latest-release` is asked first). `version_compare('2.10.0', '2.9.4', '>')` is true, and the updater takes the first `.zip` asset of the release. Rollout order per spec: plugin 2.10.0 first (inert: everything off), then lsm-api, then lsm-web. When the feature branch is merged everywhere: `git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp worktree remove /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp-hardening` (the git-ignored `vendor/` and `landeseiten-maintenance-2.10.0-rc.zip` go with it).

Follow-up outside this plan (spec Part 4): narrow the manual archive pattern in the security-audit skill the same way (bare `.gz` can break WP Super Cache expert-mode sites) and, once 2.10.0 is out, enable the toggles instead of appending unmarked rules.

---

## Contract assumptions

Where the spec is silent on something the other two repos can observe, this plan takes the most literal reading. The lsm-api and lsm-web plans were written in parallel from the same spec; if they assumed differently, these are the places to reconcile.

1. **`status.last_result` is `null`** until the first operation has run on a site. Inside it, `rule` is `null` for `deactivate` and for a `crash_recovery` of an enable/disable (the fixed `pending` shape does not store the rule); it is `block_archives` for a crash-recovered pause/resume. A crash recovery is recorded as `ok: false, reason: 'crash_recovered'`, also when a killed resume was rolled forward successfully.
2. **`status.server` is a short label**: `Apache`, `LiteSpeed`, `OpenLiteSpeed`, otherwise the raw `SERVER_SOFTWARE` string, or `unknown` when that is empty (WP-CLI).
3. **`GET /hardening/status`** answers `success: true, reason: null, message: 'OK', warnings: []` plus `status`. It never makes a loopback request.
4. **A missing or non-boolean `enabled`** on `POST /hardening/rule` is rejected with `reason: 'invalid_rule'` (the fixed list has no dedicated code) — the plugin never reads garbage as "disable". Accepted spellings are what `rest_is_boolean()` accepts: `true`/`false`, `"true"`/`"false"`, `1`/`0`, `"1"`/`"0"`.
5. **`minutes`** is accepted as an int or a numeric string of a whole number (`60`, `"60"`); anything else, and any value outside {15, 30, 60}, is `invalid_minutes`.
6. **Rejections do not leave traces:** `busy`, `invalid_rule`, `invalid_minutes`, `not_enabled` and `unsupported` change neither `last_result` nor `last_failure` nor the log. For `unsupported` the machine-readable cause is `status.rules[rule].unsupported_reason`; the top-level `message` only repeats it in prose.
7. **`warnings` can be non-empty on a failure response too** (e.g. `already_blocked_elsewhere` noticed before a later `rule_ineffective`).
8. **`already_blocked_elsewhere` can be raised for any of the three rules**, not only for `block_archives`: an unexplained 403 before the write makes the verification equally vacuous for the URL-only probes.
9. **`rule_failures` / `last_failure` for `block_archives` also records failed pauses, resumes and auto-resumes** (e.g. `pause_ineffective_foreign_rule`), and any later successful operation on that rule clears it.
10. **Turning `block_archives` on or off while it is paused ends the pause** (`pause_until` becomes `null` on success). Pausing while paused answers `success: true` and only moves `pause_until`.
11. **`POST /hardening/resume` when nothing is paused** answers `success: true, reason: null` with a fresh `status` and changes nothing (not even `last_result`).
12. **A rule in state `manual` cannot be turned off through the toggle:** for `block_archives` the attempt fails with `pause_ineffective_foreign_rule` (the hand-written block still answers 403); for the other two it succeeds as a no-op and the state stays `manual`. Manual blocks are only ever removed by adoption (turning the rule on).
13. **All timestamps** (`pause_until`, `last_result.at`, `last_failure.at`) are unix seconds from the site's `time()`.
14. **`message` strings are plain English** and not a contract; consumers key on `reason` / `warnings`.
15. **The fleet's `.htaccess` contents for the pre-release matcher check are collected by a human over SSH, read-only** (recipe in Task 17 A) — the platform does not store what the scan collector ships, and neither sibling plan adds storage for it. Task 15 fixes the input format of the checker; sites without SSH are listed in the PR as "not checked".
16. **`unsupported_reason: 'not_writable'` also covers an existing `.htaccess` that PHP can write but not read.** An engine that cannot read the bytes must not replace them; the spec's fixed list has no separate code for it.
17. **`reason: 'write_failed'` can also mean "nothing was written":** when something else changed the `.htaccess` while the self-test's loopbacks were running, the operation stops before the snapshot (message: "changed by something else … try again"). The file is untouched; a retry is the right reaction, as for any `write_failed`.
18. **Loopback URLs carry a `lsm_hardening=<8hex>` query parameter** on the plugin stylesheet and on every probe request (never on the homepage). Anything on the platform side that inspects a site's access log will see those.

Cross-check, read-only, after this plan was finished: the two sibling plans in this directory (`2026-09-21-htaccess-hardening-api.md`, `…-web.md`) agree with the points above where they touch them — the API plan sends `enabled` as a real JSON boolean, `minutes` as a real integer, an empty body on resume and one extra `_` query parameter on the GET (all accepted by Task 13), and its fixtures use `last_result: null`; the web plan types `last_result` as nullable with `rule: … | null` and keys only on `last_result?.reason === 'crash_recovered'`. No conflict found. Points 16-18 were added in the review round: both sibling plans pass `reason` / `unsupported_reason` through unchanged, so nothing breaks; the web plan's i18n texts for `not_writable` ("cannot write the .htaccess file (or its folder)") and `write_failed` ("could not be written. The original was restored.") are slightly narrower than the two new sub-cases — the plugin's own `message` says precisely what happened, and widening the two web texts is an optional wording follow-up for that plan, not a contract change.

Deliberate readings inside the plugin (not visible to the other repos, listed so a reviewer does not have to rediscover them): the candidate block is built from what the file holds plus/minus the operated rule, never from the option (Task 8); a file is deleted only when it did not exist before *that* operation, so turning the last rule off leaves an empty `.htaccess` that an earlier operation created (Task 3); the last-resort "strip only the managed block" runs only when the original bytes could not be restored, not when they were restored and wp-content is still broken (Task 8); the homepage body must be non-empty only if it was non-empty before (Task 8); a manual block needs at least one deny directive and balanced `<IfModule>` lines to be recognised (Task 4); a throwable inside an operation is treated as a crash and left to crash recovery (Tasks 8 and 11); every probe fetch gets a fresh cache-buster although the spec names one only for baseline 2a — the same URL is fetched before and after the write and the spec itself says Cloudflare caches `.zip` for ~2 h (Task 7); "`lsm-probe-*` … excluded from the scanner" is read as "every file the plugin can create" — `lsm-probe-<16hex>.zip|.wpress` and `.htaccess.lsm-bak`, matched exactly, never a PHP name — and the same exact match limits what `cleanup_artifacts()` deletes (Tasks 7 and 14); a file that exists but cannot be read is `not_writable`, never "empty" (Tasks 3, 5, 12); the file is re-read right before the snapshot and a change since the candidate was built ends the operation with `write_failed`, nothing written — the spec holds the original bytes at step 4, after the loopbacks (Tasks 8 and 10); `finish()` saves the state before it deletes the snapshot and probes, so a kill in between leaves stray files, never a `pending` without a snapshot (Task 8); a `pending` without `started_at` counts as old (Task 11). The lock insert is a raw `INSERT IGNORE` followed by `update_option()` for the cache, exactly as core's upgrade lock does (Task 6).

## Self-Review

Run by the plan's author, not left to the implementer — and run again after the review round that changed Tasks 1-3, 5-12, 14 and 16-17. The document's own code blocks and find/replace edits were replayed task by task into a fresh `git archive master` export (`850eaf3`): each task's new tests fail before its implementation with the quoted numbers and the whole suite passes after it, with exactly the counts quoted in the tasks; every "find" snippet of every edit matched its file exactly once. Final state: **OK (208 tests, 1409 assertions)** on PHP 8.3.22 with PHPUnit 9.6.36, the same suite green on **PHP 7.4.33** (docker `php:7.4-cli`, PHPUnit 9.6 phar, as non-root; as root the five chmod-based tests skip by design: `Tests: 208, Assertions: 1391, Skipped: 5`) and under the **zero-install fallback** (PHPUnit 12.5.3 from lsm-api). `php -l` is clean on PHP 7.4 and 8.3 for all touched files.

The tests added in the review round were each checked the other way round as well — the fix was reverted in a scratch copy and the suite re-run: without the per-fetch cache-buster `test_an_edge_cache_cannot_fake_rule_ineffective`, `test_an_edge_cache_cannot_fake_a_failed_resume` and `test_every_probe_fetch_carries_a_fresh_cache_buster` fail (plus the two URL assertions); with `finish()` in the old order `test_the_state_is_committed_before_the_snapshot_is_deleted` fails; with an unreadable file read as empty the three `unreadable` tests fail (Writer, Status, Apply); without the re-read before the snapshot the two "changed by something else" tests fail (Apply, AutoResume); without `isset()` the damaged-`pending` test errors with "Undefined array key"; with a prefix-only `is_own_artifact()` the two Loopback tests and `test_the_scanner_never_hides_a_file_that_is_only_named_like_a_probe` fail.

### Spec requirement → task

| Spec requirement (Part 1, plugin bullets of Part 4, release) | Task |
|---|---|
| New file `includes/class-lsm-hardening.php`, class `LSM_Hardening`, instance class, seams `http_request($url, $args)`, `content_dir()`, `uploads_dir()`, `now()`, `server_software()`, static facade `instance()` | 2 |
| Required from `Landeseiten_Maintenance::includes()`; no existing behaviour changes | 14 |
| Rules table: three keys, files, exact patterns (case-insensitive, no bare `.gz`, `.phtml .pht .phps .phar`, double extensions) | 2 |
| Rule body in both syntaxes, never a "granted" directive | 2 |
| All rules default off; install/update changes nothing; paths from `WP_CONTENT_DIR` / `wp_upload_dir()` | 2 (seams), 5 (defaults), 14 (`activate()` untouched, asserted) |
| `archive_attachments` (one COUNT query) | 5 |
| State option `lsm_hardening` (autoloaded), exact shape | 5 (read), 8 (write, `pending`, `last_result`, `rule_failures`) |
| `rules` / `pause_until` committed only after the self-test passed | 8 (`finish()`), tested in 8, 9, 11 |
| Status read from the file; six states in priority order; on-while-desired-false; `drift`; nothing auto-writes on drift | 5; "does not resurrect a drifted rule" in 8 |
| Static preflight: `multisite`, `openlitespeed`, `unknown_server`, `not_writable` (incl. "exists but unreadable") | 5; end to end in 8 (`test_an_unreadable_htaccess_is_never_overwritten`) |
| Strict file handling: markers, zero-or-one block, corrupt → `markers_corrupt`, in-place replace / append with blank line, outside bytes preserved (also: an unreadable file is never taken for empty; a file changed during the loopbacks is never overwritten), markers removed with the last rule, delete-if-created, `LOCK_EX` write + sha1 read-back → `write_failed` | 3 (writer, `unreadable`), 5 (`corrupt` for unreadable), 8 and 10 (`markers_corrupt`, `write_failed`, re-read before the snapshot) |
| Lock row `lsm_hardening_lock` via `INSERT IGNORE` (atomic), stale > 180 s, busy changes nothing | 6, busy path in 8 / 9 / 10 |
| Loopback args (5 s, `redirection 0`, no cookies, `https_local_ssl_verify`, `Cache-Control: no-cache`); `@set_time_limit(120)`; no `ignore_user_abort` | 7, 8 |
| Apply step 2: baseline 2a (cache-buster, 200 + non-empty, skipped when plugin outside wp-content) and 2b (anonymous homepage, `WP_Error` → `loopback_blocked`) | 7, 8 |
| Apply step 3: probes before — real `.zip` + `.wpress` files with token, `already_blocked_elsewhere`, debug.log URL, never-created uploads `.php`; a fresh cache-buster per probe fetch so an edge cache (spec: Cloudflare caches `.zip` ~2 h) cannot answer the "after" fetch | 7, 8 (edge-cache test), 9 (edge-cache test for resume), 17 F |
| Apply step 4: snapshot `.htaccess.lsm-bak` read back identical → `snapshot_failed`; `pending` enum, never a path | 8 |
| Apply step 6: `asset_broken`, `rule_ineffective` (names the probe), `pause_ineffective_foreign_rule`, 401 / 5xx / `WP_Error` / `cf-mitigated: challenge` → `loopback_blocked` | 7 (verdicts), 8 (end to end) |
| Apply step 7: rollback from memory, re-check 2a, `rollback_failed` + last resort, `rule_failures[rule]` | 8 |
| Apply step 8: commit, clear pending, `last_result` (saved first), then delete snapshot + probes, release lock, `LSM_Logger::log()` | 8 (`test_the_state_is_committed_before_the_snapshot_is_deleted`) |
| `pause(minutes)` ∈ {15, 30, 60}, requires `on` else `not_enabled`, desired stays true, pause-while-paused only moves `pause_until`, failed pause restores everything | 9 |
| `resume()` full apply, idempotent no-op when nothing is paused | 9 |
| Auto-resume: init detection (overdue, not CLI, not `lsm/v1/hardening/*`, 300 s throttle), `shutdown` late priority, `fastcgi_finish_request()` / `litespeed_finish_request()`, lock-busy silent, light path, rollback only on 200 → non-200, `unverified`, `pause_until` cleared only after read-back, failed attempt stays `paused` + `pause_overdue` | 10 |
| Crash recovery: `pending` older than 180 s, lock first, resume → roll forward, other ops → restore snapshot, always delete snapshot + probes, clear pending, `crash_recovered`, target from the enum | 11 |
| Adoption: grammar (3 openings, 7 body lines, matching close tag), outside our markers, `manual` state, adopt in the same safe write, fixtures from midnightblue-duck / drjung.ch / the audit skill, near-misses never match | 4 (grammar), 5 (`manual`), 8 (adopt) |
| Before release: fleet `.htaccess` contents checked once against the matcher | 15 (tool), 17 A (human-run read-only SSH collection recipe + run) |
| Deactivation: lock, no server preflight, remove both blocks with the strict writer, all `rules` false, clear `pause_until`, log, no self-test; `activate()` never writes; no uninstall handler | 12, wired + asserted in 14 |
| Own scanner: `lsm-probe-*` and `.htaccess.lsm-bak` excluded from the suspicious-file collectors — for every file the plugin can create (`lsm-probe-<16hex>.zip` / `.wpress`), never for a PHP name that merely carries the prefix | 7 (`is_own_artifact`, exact match), 14 (wired; impostors still reported) |
| REST: four routes, `authenticate`, manual validation (`in_array(..., true)`, `rest_sanitize_boolean`), HTTP 200 always, `Cache-Control: no-store, private`, exact top-level shape without `data` wrapper | 13 |
| Load-time work on `init` after `LSM_Logger::init()` (map §10.1) | 14 |
| Part 4 plugin tests: PHPUnit 9.6 at the repo root outside the zip, `vendor/` + `.phpunit.result.cache` ignored, stub bootstrap (in-memory options, temp dirs, canned HTTP) | 1 |
| Part 4 test list: block generation; parser absent/present/corrupt, outside content, removal + file deletion; grammar fixtures + near-misses; each failure reason → rollback + unchanged state; busy; pause / pause-while-paused / failed pause; auto-resume throttle, fail-closed, read-back; crash recovery per op; status incl. `drift` + `manual`; deactivation | 2; 3; 4; 8; 8; 9; 10; 11; 5; 12 |
| Version 2.10.0 in every place the map lists (header line 5, constant line 22); tag `v2.10.0`; release from `master` via `build-release.sh`; release notes | 16 (version, rc zip inside the worktree); Release runbook (human only: tag, push, GitHub release, notes) |
| Real-site verification before release, in order, incl. LiteSpeed non-existent-file question and forced broken rule (plus one Cloudflare-proxied site; auto-resume triggered by an uncached request) | 17 (human only) |
| Rollout order: plugin first (inert), then API, then web | Release runbook (closing note) |

Every reason code, warning, state and `last_result.action` appears in at least one test:

| Value | Test file(s) |
|---|---|
| `busy` | Apply, Pause, AutoResume (silent), Recovery |
| `invalid_rule` | Apply, Rest |
| `invalid_minutes` | Pause, Rest |
| `not_enabled` | Pause, Rest |
| `unsupported` (+ `multisite`, `openlitespeed`, `unknown_server`, `not_writable` incl. the unreadable file) | Status, Apply (`test_unsupported`, `test_an_unreadable_htaccess_is_never_overwritten`), Pause; `unreadable` itself in Writer |
| `loopback_blocked` | Loopback, Apply (5 variants incl. `cf-mitigated`) |
| `markers_corrupt` | Writer (parser), Apply, AutoResume |
| `snapshot_failed` | Apply |
| `write_failed` | Apply (short write; file changed by something else during the self-test), AutoResume (write not on disk; file changed while the stylesheet was fetched) |
| `asset_broken` | Apply (stylesheet, homepage status, homepage body), Pause, AutoResume |
| `rule_ineffective` | Loopback, Apply, Pause (failed resume), Rest — and *not* raised behind an edge cache: Apply (`test_an_edge_cache_cannot_fake_rule_ineffective`), Pause (`test_an_edge_cache_cannot_fake_a_failed_resume`), Loopback (`test_every_probe_fetch_carries_a_fresh_cache_buster`) |
| `pause_ineffective_foreign_rule` | Loopback, Apply, Pause |
| `rollback_failed` | Apply (restore fails; wp-content stays broken) |
| `crash_recovered` | Recovery (incl. a damaged `pending` without `started_at`) |
| warnings `already_blocked_elsewhere`, `unverified` | Loopback + Apply; AutoResume |
| states `on`, `off`, `paused`, `manual`, `drift`, `unsupported` | Status (all six), plus Apply / Pause / AutoResume |
| actions `enable`, `disable`, `pause`, `resume`, `auto_resume`, `crash_recovery`, `deactivate` | Apply; Apply; Pause; Pause; AutoResume; Recovery; Deactivate |
| `pending.op` `enable`, `disable`, `pause`, `resume` | Apply / Recovery; Recovery; Pause; AutoResume |

### Shared names and where they are defined

| Name | Kind | Defined in |
|---|---|---|
| `lsm_hardening` | option (autoloaded) | Task 2 (constant), Task 5 (shape), Task 8 (writes) |
| `lsm_hardening_lock` | option (lock) | Task 2 (constant), Task 6 |
| `# BEGIN LSM-HARDENING` / `# END LSM-HARDENING` | file markers | Task 2 |
| `.htaccess.lsm-bak`, `lsm-probe-<16hex>.zip`, `lsm-probe-<16hex>.wpress` | artifact file names (exactly these — `is_own_artifact()`) | Task 2 (constants), Task 7 |
| `lsm-probe-<16hex>.php` | URL-only probe name below uploads — never a file | Task 7 |
| `lsm_hardening=<8hex>` | cache-buster query parameter (plugin stylesheet, every probe fetch) | Task 7 |
| `block_archives`, `block_debug_log`, `block_uploads_php` | rule keys | Task 2 (`LSM_Hardening::RULES`) |
| `GET lsm/v1/hardening/status`, `POST …/rule`, `POST …/pause`, `POST …/resume` | REST routes | Task 13 |
| `rule`, `enabled`, `minutes` | request JSON keys | Task 13 |
| `success`, `reason`, `message`, `warnings`, `status` | response top level | Task 8 (`respond()`), Task 13 |
| `plugin_version`, `server`, `rules`, `pause_until`, `pause_overdue`, `archive_attachments`, `last_result` | `status` keys | Task 5 (`get_status()`) |
| `state`, `desired`, `unsupported_reason`, `last_failure` (`at`, `reason`) | per-rule keys | Task 5 (`rule_statuses()`) |
| `at`, `action`, `rule`, `ok`, `reason`, `warnings` | `last_result` keys | Task 8 (`finish()`), Tasks 9, 11, 12 |
| states `unsupported`, `paused`, `on`, `manual`, `drift`, `off` | values | Task 5 |
| `multisite`, `openlitespeed`, `unknown_server`, `not_writable` | `unsupported_reason` values | Task 5 (`preflight()`) |
| `invalid_rule` | reason | Task 8 (`set_rule()`), Task 13 |
| `invalid_minutes`, `not_enabled` | reasons | Task 9 (`pause()`), Task 13 |
| `unsupported`, `busy` | reasons | Task 8 (`apply()`), Task 9 |
| `loopback_blocked`, `rule_ineffective`, `pause_ineffective_foreign_rule` | reasons | Task 7 (verdicts), Task 8 |
| `markers_corrupt`, `snapshot_failed`, `write_failed`, `asset_broken`, `rollback_failed` | reasons | Task 8 (also Task 10 for the light path) |
| `crash_recovered` | `last_result.reason` | Task 11 |
| `already_blocked_elsewhere` | warning | Task 7 |
| `unverified` | warning | Task 10 |
| `enable`, `disable` | `last_result.action` / `pending.op` | Task 8 |
| `pause`, `resume` | `last_result.action` / `pending.op` | Task 9 (`resume` also Task 10 as `pending.op`) |
| `auto_resume`, `crash_recovery`, `deactivate` | `last_result.action` | Tasks 10, 11, 12 |
| `Cache-Control: no-store, private` | response header | Task 13 |
| `2.10.0` / tag `v2.10.0` | plugin version (= the platform's `min_version`) | Task 16 |
| `LSM_Hardening::instance()`, `set_instance()`, seams | PHP API | Task 2 |
| `target_file`, `read_target`, `parse_markers`, `replace_block`, `put_contents`, `commit_target`, `restore_target` | PHP API | Task 3 |
| `find_manual_blocks`, `strip_manual_blocks`, `normalize_line` | PHP API | Task 4 |
| `get_state`, `preflight`, `file_facts`, `rule_statuses`, `get_status`, `count_archive_attachments` | PHP API | Task 5 |
| `acquire_lock`, `release_lock` | PHP API | Task 6 |
| `plugin_dir`, `loopback`, `baseline_asset`, `asset_ok`, `baseline_home`, `prepare_probes`, `fetch_probes`, `judge_before`, `judge_after`, `is_own_artifact`, `cleanup_artifacts` | PHP API | Task 7 |
| `respond`, `set_rule`, `apply`, `save_state`, `write_snapshot`, `rollback`, `finish` | PHP API | Task 8 |
| `pause`, `resume` | PHP API | Task 9 |
| `is_cli`, `finish_request`, `on_init`, `run_auto_resume`, `auto_resume` | PHP API | Task 10 (`on_init` extended in Task 11) |
| `deactivate` | PHP API | Task 12 |
| `get_hardening_status`, `set_hardening_rule`, `pause_hardening`, `resume_hardening`, `hardening_response` | `LSM_API` methods | Task 13 |
| `hardening_applied`, `hardening_failed`, `hardening_crash_recovered`, `hardening_deactivated` | `LSM_Logger` actions | Tasks 8, 8, 11, 12 |
| `LSM_Test_Env`, `LSM_Fake_Server`, `LSM_Test_Wpdb`, WordPress fakes | test harness | Task 1 |
| `LSM_Testable_Hardening`, `HardeningTestCase` (helpers `htaccess`, `put`, `get`, `artifacts`, `put_an_edge_cache_in_front`) | test doubles | Task 2 |
| `LSM_Htaccess_Fixtures` | fixtures | Task 4 |
