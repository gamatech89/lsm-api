# Managed .htaccess hardening — design (v2)

Date: 2026-09-21 (v2 same day, after a code-mapping + adversarial review pass)
Repos: lsm-wp (plugin), lsm-api, lsm-web
Branch in each repo: `feature/htaccess-hardening` (from `main` in lsm-api, from `master` in lsm-web and lsm-wp)
Plugin release: 2.10.0

## Problem

On sites we cleaned or audited, the security-audit procedure appends a deny rule
for `.wpress|sql|zip|tar|gz|bak` to `wp-content/.htaccess` by hand over SSH. The
rule is intentional (drjung.ch and physioteam-dieterich.de had full-site backups
publicly downloadable), but it has no marker comment, nobody on the team can see
or control it, and it makes the All-in-One WP Migration (AI1WM) download button
return 403. The only way to get a backup today is SFTP, which the team does not
want.

The LSM plugin itself writes no `.htaccess` outside its own
`uploads/lsm-backups/` folder. Its existing security toggles are database
options applied in PHP.

## Goal

Three server-level hardening rules that the team can see, turn on, and (for the
archive rule) pause for a download, from the platform Security panel — with the
write done so carefully that a bad rule cannot take a site down, and a failed
step never leaves a site silently exposed.

Out of scope for v1: security headers via `.htaccess`, XML-RPC / author blocks,
any write to the root `.htaccess`, any UI inside WP admin, a platform activity
log, a plugin-side authenticated `.wpress` download, multisite, scanning foreign
or nested `.htaccess` files for overrides, drift alerting through the health
check, counting hardening in the panel's security score.

## What changed from v1 (review outcome)

1. Bare `.gz` is no longer matched: WP Super Cache (expert mode) and
   precompressed-asset rewrites serve `*.html.gz` / `*.css.gz` from under
   `wp-content`, and `FilesMatch` applies to the rewritten filename. Compound
   forms (`.sql.gz`, `.tar.gz`, …) are still blocked.
2. "Only URLs under wp-content are affected" was wrong: WP Rocket (125 of 141
   fleet sites) and similar caches rewrite anonymous page views to files under
   `wp-content/cache/`. The self-test now includes the homepage fetched as an
   anonymous visitor. The plugin's REST endpoints stay reachable either way, so
   same-request rollback still works.
3. The self-test is differential (before/after) with real probe files for `.zip`
   and `.wpress`; `.bak` alone gave false "verified" results behind nginx static
   serving and ModSecurity CRS.
4. `insert_with_markers()` cannot remove a block and writes locale-dependent
   comment lines; the engine uses its own small strict writer.
5. Status is computed from the file, not from the option. New state `drift`.
6. Auto-resume fails closed: `pause_until` is cleared only after the block is
   confirmed back in the file; a failed attempt is retried (throttled) and shows
   as paused + overdue.
7. Auto-resume runs on `shutdown` after the response is flushed, never inline
   in a visitor or uptime request, never in CLI.
8. Atomic lock (`INSERT IGNORE` options row), time budget, snapshot only on disk while an
   operation is pending, `pending` stores an enum never a path.
9. Rule patterns are case-insensitive; the uploads rule also covers
   `.phtml .pht .phps .phar` and double extensions.
10. The PHP-in-uploads rule is verified without ever creating a PHP file in
    uploads (request for a non-existent name: 403 with the rule, 404 without).
11. Platform: write-ahead pause rows, typed error mapping incl. "plugin older
    than 2.10.0", short scheduler mutex, one overdue notification, cache-buster
    on plugin GETs, throttle on the POST routes.
12. lsm-web has no test runner; the gate there is typecheck + lint + build +
    manual browser verification, as in every earlier web plan.

## Rules

| Key | File | `FilesMatch` pattern |
|---|---|---|
| `block_archives` | `WP_CONTENT_DIR/.htaccess` | `(?i)\.((wpress\|sql\|zip\|tar\|tgz\|bak)\|(sql\|tar\|bak\|wpress\|zip)\.gz)$` |
| `block_debug_log` | `WP_CONTENT_DIR/.htaccess` | `<Files "debug.log">` |
| `block_uploads_php` | `wp_upload_dir()['basedir']/.htaccess` | `(?i)\.(php[0-9]?\|phtml?\|pht\|phps\|phar)(\.\|$)` |

(The `\|` above is markdown escaping; the emitted regex uses plain `|`.)

All rules default to off. Installing or updating the plugin changes nothing on
any site. Paths always come from `WP_CONTENT_DIR` and `wp_upload_dir()`.

Each rule body is emitted in both syntaxes. Never put a "granted" directive in
either branch: LiteSpeed Enterprise ignores `<IfModule>` tests and executes both.

```apache
<FilesMatch "(?i)\.((wpress|sql|zip|tar|tgz|bak)|(sql|tar|bak|wpress|zip)\.gz)$">
  <IfModule mod_authz_core.c>
    Require all denied
  </IfModule>
  <IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
  </IfModule>
</FilesMatch>
```

`<IfModule>` protects against a missing module, not against an `AllowOverride`
without `AuthConfig` — that case is a 500 under wp-content, caught by the
self-test and rolled back.

Known legitimate things `block_archives` breaks (shown as warnings, not
blockers): AI1WM/Duplicator direct downloads (the reason pause exists), archive
files in the Media Library linked from pages, WooCommerce downloadable products
in "Redirect only" mode. Status returns `archive_attachments` (one COUNT query
on attachments with zip/gzip/tar/rar/7z mime types) so the confirm dialog can
say "N downloadable archives in the media library will stop working".

Already-cached copies of an archive at a CDN edge (Cloudflare caches `.zip`,
`.gz`, `.tar` for ~2 h) stay available until they expire; the plugin cannot
purge them.

## Part 1 — plugin: safe-write engine

New file `includes/class-lsm-hardening.php`, class `LSM_Hardening`, required
from `Landeseiten_Maintenance::includes()`. Instance class with overridable
seams for tests (`http_request($url, $args)`, `content_dir()`, `uploads_dir()`,
`now()`, `server_software()`), thin static facade `LSM_Hardening::instance()`.
No existing behaviour changes.

### State — option `lsm_hardening` (autoloaded)

```
rules:           { block_archives: bool, block_debug_log: bool, block_uploads_php: bool }   // desired
pause_until:     int|null     // unix time, block_archives only
last_attempt_at: int|null     // throttle for auto-resume
pending:         { target: 'content'|'uploads', op: 'enable'|'disable'|'pause'|'resume', started_at: int, existed: bool } | null
last_result:     { at, action, rule, ok, reason, warnings[] }
rule_failures:   { <rule>: { at, reason } }   // sticky until the next successful apply of that rule
```

`rules` and `pause_until` are committed only after an operation's self-test
passed (the block is built from the candidate state held in memory), so a crash
leaves the option describing the last good state.

### Status is read from the file

On every status call the engine parses both files (strict marker parser, below).
Per rule:

- `unsupported` — static preflight fails (see below); carries `unsupported_reason`.
- `paused` — `block_archives` only: `pause_until` is set (also when overdue).
- `on` — the managed block contains this rule's lines.
- `manual` — not in the managed block, but a recognised manual block is in the file.
- `drift` — desired is true but the rule is not in the managed block.
- `off` — otherwise.

A rule that is in the managed block while desired is false still reports `on`
(file is truth) and can be turned off normally. `drift` is fixed by turning the
rule on again through the normal safe procedure; nothing auto-writes on drift.

### Static preflight (no write, no HTTP)

`unsupported` with reason: `multisite` (`is_multisite()`), `openlitespeed`
(`$_SERVER['LSWS_EDITION']` starts with `Openlitespeed`), `unknown_server`
(`SERVER_SOFTWARE` contains neither `Apache` nor `LiteSpeed`), `not_writable`
(file, or its directory when the file is absent). `SERVER_SOFTWARE` cannot see
nginx in front of Apache — only the probes can.

### Strict file handling (own writer, not `insert_with_markers`)

Markers: `# BEGIN LSM-HARDENING` / `# END LSM-HARDENING`, one block per file,
regenerated whole from the candidate state.

- Parse: exactly zero or one BEGIN, each BEGIN followed by an END, no END
  without BEGIN. Anything else → `markers_corrupt`, nothing written.
- Replace the block in place, or append it (preceded by a blank line) when
  absent. Content outside the markers is preserved byte for byte.
- Empty rule set → markers removed too; if the file is then empty and
  `pending.existed` was false, delete the file.
- Write with `file_put_contents($file, $content, LOCK_EX)`, re-read, compare
  sha1; mismatch → restore original bytes from memory → `write_failed`.

### Lock and time budget

- Lock: one options row `lsm_hardening_lock` (value = unix time, autoload `no`)
  taken with a raw `INSERT IGNORE`, then `update_option()` for the cache — the
  pattern of core's `WP_Upgrader::create_lock()`. `add_option()` is not used for
  the insert: it checks and then upserts, which is not atomic.
  A lock older than 180 s is stale: delete and retry once. Busy →
  `success:false, reason:'busy'`; busy never changes any state or `last_result`.
- Every loopback: timeout 5 s, `redirection => 0`, no cookies,
  `sslverify => apply_filters('https_local_ssl_verify', false, $url)`,
  header `Cache-Control: no-cache`. Worst case ≈ 12 requests ≈ 60 s.
- Crash-recovery threshold 180 s, and recovery must take the lock first.
- `@set_time_limit(120)` at the start of an apply. Do not rely on
  `ignore_user_abort` (LiteSpeed does not support it).

### Full apply procedure (enable, disable, adopt, pause, manual resume — all platform-initiated REST calls)

1. **Static preflight** and **lock**.
2. **Baseline before.**
   a. plugin asset `LSM_PLUGIN_URL . 'assets/css/ticket-ui.css'` with a
      cache-buster → must be 200 with a non-empty body, else `loopback_blocked`,
      nothing written. (If the plugin dir is not under `WP_CONTENT_DIR` this
      check is skipped; the probes below still run.)
   b. `home_url('/')` as an anonymous visitor: no query string, no cookies →
      record status code. A `WP_Error` here → `loopback_blocked`.
3. **Probes before** (only for rules whose state changes in this operation):
   - `block_archives`: create `lsm-probe-<16hex>.zip` and
     `lsm-probe-<16hex>.wpress` in `WP_CONTENT_DIR`, body = random token. Fetch
     both. Expected 200 + token. Already 403 → warning
     `already_blocked_elsewhere` (continue). Anything else → `loopback_blocked`.
   - `block_debug_log`: request `content_url('debug.log')` whether or not it
     exists; record the code (expected non-403).
   - `block_uploads_php`: request `<uploads>/lsm-probe-<16hex>.php` — the file
     is never created; record the code (expected 404, i.e. non-403).
4. **Snapshot + pending.** Hold the original bytes in memory. Write
   `.htaccess.lsm-bak` beside the file and read it back identical, else
   `snapshot_failed`. Set `pending`.
5. **Write** (strict writer).
6. **Self-test after.**
   - Baseline 2a still 200; 2b same status code as before and non-empty body.
     Otherwise → `asset_broken`.
   - Rule turned on: every probe for it must now be exactly 403, otherwise
     `rule_ineffective` (message names the failing probe, e.g. `.zip` served by
     a front-end nginx).
   - `block_archives` paused or turned off: the `.wpress` probe must be 200 +
     token, otherwise `pause_ineffective_foreign_rule`.
   - 401, 5xx, `WP_Error`, or a `cf-mitigated: challenge` header on any probe
     is never "403 effective" → `loopback_blocked`.
7. **Rollback on any failure:** restore the original bytes (or delete the file
   if it did not exist), re-check baseline 2a. If the restore fails or the
   baseline is still broken → `rollback_failed` (last resort before returning:
   strip only the managed block). Record `rule_failures[rule]`.
8. **Finish** (both paths): commit `rules` / `pause_until` on success, delete
   the snapshot file and all `lsm-probe-*` files, clear `pending`, release the
   lock, write `last_result`, log via `LSM_Logger::log()`.

### Pause, resume, auto-resume

- `pause(minutes)` — minutes ∈ {15, 30, 60}; requires `block_archives` state
  `on` (else `not_enabled`). Runs the full apply with the archive rule omitted;
  on success sets `pause_until = now + minutes*60`. Pausing while already
  paused only moves `pause_until` to `now + minutes*60` (no file change).
  A failed pause restores the whole previous state, including `pause_until`.
- `resume()` (REST) — full apply that re-adds the rule; idempotent no-op
  returning fresh status when nothing is paused. On success clears `pause_until`.
- **Auto-resume (light path).** On `init` the plugin does one cheap compare:
  `pause_until` set and `< now`, `PHP_SAPI !== 'cli'`, not a request to
  `lsm/v1/hardening/*`, and `last_attempt_at` older than 300 s. If so it
  registers a `shutdown` callback (late priority) that first calls
  `fastcgi_finish_request()` / `litespeed_finish_request()` when available, then:
  take the lock (busy → return silently), set `last_attempt_at`, baseline 2a
  before, snapshot + pending(op=resume), write the block with the archive rule,
  read back, baseline 2a after. Roll back **only** if 2a went from 200 to
  non-200. If the loopback itself fails, keep the block (it was verified on
  this host when it was enabled) and record warning `unverified`. Clear
  `pause_until` only after the read-back shows the rule in the block. A failed
  attempt leaves `pause_until` in the past → status stays `paused` +
  `pause_overdue`, and both the next eligible load and the platform backstop
  retry.

A download that started during the pause continues after the rule returns
(authorization is checked once per request); a later HTTP Range resume is a new
request and gets 403. The confirm text says so.

### Crash recovery

On `init`, if `pending` is set and older than 180 s: take the lock, then
- op `resume` → roll forward: make sure the block contains the archive rule
  (light path without HTTP), then clear `pause_until`;
- any other op → restore the snapshot file if present;
- always: delete the snapshot and any `lsm-probe-*` files in both directories,
  clear `pending`, record `last_result.reason = 'crash_recovered'`.
The target file is derived from `pending.target`, never from a stored path.

### Manual rules already on a site (adoption)

A small grammar, not exact text: opening line equals (after whitespace
normalisation) one of `<Files "debug.log">`, `<FilesMatch "\.php$">`,
`<FilesMatch "\.(wpress|sql|zip|tar|gz|bak)$">`; body lines only from
{`Require all denied`, `Deny from all`, `Order deny,allow`, `Order allow,deny`,
`<IfModule mod_authz_core.c>`, `<IfModule !mod_authz_core.c>`, `</IfModule>`};
then the matching close tag. Such a block outside our markers → state `manual`.
Turning the toggle on adopts it: the manual block is removed and the managed
block written in the same safe write. Anything else is never touched. Test
fixtures come from the real audit artifacts (midnightblue-duck, drjung.ch).
Before release, the fleet's `wp-content/.htaccess` and `uploads/.htaccess`
contents (already shipped by the scan collector) are checked once against the
matcher.

### Deactivation

Takes the lock, skips the server preflight (often run from WP-CLI), removes
both managed blocks with the strict writer, sets all `rules` to false, clears
`pause_until`, logs. No self-test. `activate()` never writes (it re-runs after
every self-update). No uninstall handler in v1.

### Own scanner

`lsm-probe-*` and `.htaccess.lsm-bak` are excluded from the plugin's
suspicious-file collectors.

### REST endpoints (namespace `lsm/v1`, `permission_callback` = `authenticate`)

- `GET  /hardening/status`
- `POST /hardening/rule` `{rule, enabled}`
- `POST /hardening/pause` `{minutes}`
- `POST /hardening/resume`

Manual validation in the callback, house style (`in_array(..., true)`,
`rest_sanitize_boolean`). Every response is HTTP 200, sends
`Cache-Control: no-store, private`, and has this top-level shape (no `data`
wrapper, so nothing on the platform can unwrap `success` away):

```json
{
  "success": true,
  "reason": null,
  "message": "Applied and verified",
  "warnings": [],
  "status": {
    "plugin_version": "2.10.0",
    "server": "Apache",
    "rules": {
      "block_archives":    { "state": "on", "desired": true, "unsupported_reason": null, "last_failure": null },
      "block_debug_log":   { "state": "off", "desired": false, "unsupported_reason": null, "last_failure": null },
      "block_uploads_php": { "state": "manual", "desired": false, "unsupported_reason": null, "last_failure": { "at": 1790000000, "reason": "rule_ineffective" } }
    },
    "pause_until": null,
    "pause_overdue": false,
    "archive_attachments": 0,
    "last_result": { "at": 1790000000, "action": "enable", "rule": "block_archives", "ok": true, "reason": null, "warnings": [] }
  }
}
```

`reason` codes (failure): `busy`, `invalid_rule`, `invalid_minutes`,
`not_enabled`, `unsupported`, `loopback_blocked`, `markers_corrupt`,
`snapshot_failed`, `write_failed`, `asset_broken`, `rule_ineffective`,
`pause_ineffective_foreign_rule`, `rollback_failed`. `last_result.reason` may
also be `crash_recovered`. `not_writable` (an `unsupported_reason`) also covers
a file that exists but cannot be read; `write_failed` also covers "the file
changed between building the block and the snapshot — nothing was written". Warnings: `already_blocked_elsewhere`, `unverified`.
`last_result.action`: `enable`, `disable`, `pause`, `resume`, `auto_resume`,
`crash_recovery`, `deactivate`.

## Part 2 — lsm-api

- `LsmService::hardeningRequest(string $method, string $endpoint, array $data = [], int $timeout = 30): array`
  → `['http' => int|null, 'json' => array|null]`. It does **not** go through
  `handleResponse()`. GETs append `_=<microtime>` (one host caches plugin REST
  GETs for 28 days). POSTs use 120 s.
- Routes inside the existing `projects/{project}/lsm` group, after
  `security-headers/snippets`; POSTs additionally behind `throttle:12,1,hardening`
  (the third parameter gives these routes their own counter):
  `GET /hardening`, `POST /hardening/rule`, `POST /hardening/pause`,
  `POST /hardening/resume`.
- `ProjectPolicy` (admins pass everything through `before()`):

| Ability | Body |
|---|---|
| `pauseHardening` | `return $this->update($user, $project);` |
| `enableHardening` | `return $user->role === 'manager' && $this->managesProject($user, $project);` |
| `disableHardening` | `return false;` |

  `POST /hardening/rule`: `$enabled = $request->boolean('enabled');` →
  `Gate::authorize($enabled ? 'enableHardening' : 'disableHardening', $project)`
  → then validate (`rule` in the three keys, `enabled` required boolean). A
  missing/garbage `enabled` is therefore treated as disable (fail closed).
  GET is authorized with `view`.
- Response mapping (all bodies carry `message` for the SPA interceptor and a
  machine `reason`):

| Plugin result | API response |
|---|---|
| 200 `success:true` | 200 `{success:true, message, warnings, status, open_pause, pause_overdue, can}` |
| 200 `success:false`, reason `busy` | 409 `{success:false, reason:'busy', message, status, …}` |
| 200 `success:false`, other | 422 `{success:false, reason, message, status, …}` |
| 404 (route missing) | 409 `{success:false, reason:'plugin_outdated', min_version:'2.10.0', message}` |
| 401 / 403 | 502 `{success:false, reason:'unauthorized', message}` |
| no response / timeout / 5xx | 502 `{success:false, reason:'unreachable', message:'Outcome unknown — refresh status'}` |

  `GET /hardening` always answers 200:
  `{reachable, plugin_outdated, min_version, status|null, open_pause|null, pause_overdue, can:{pause,enable,disable}}`
  — so an old plugin or an unreachable site renders a quiet state, not an error.
  `can` comes from `Gate::allows(...)` per user and project (an unassigned
  manager sees status with all `can` false). `pause_overdue` = an open pause row
  with `paused_until < now()`, or the plugin's own `pause_overdue`.
- Table `project_hardening_pauses`: `project_id` (constrained, cascade),
  `user_id` (nullable, constrained, nullOnDelete), `paused_until`,
  `resumed_at` nullable, `failed_at` nullable, `overdue_notified_at` nullable,
  `note` nullable string, timestamps. Model `ProjectHardeningPause`.
- **Write-ahead pause:** close any open row for the project, insert the new row
  **before** calling the plugin. Explicit `success:false` → delete the row.
  No response → keep the row (the backstop reconciles). Success → set
  `paused_until` from the plugin's `pause_until`.
  Resume success, or turning `block_archives` off → close open rows.
  GET closes an open row opportunistically when a reachable plugin status shows
  `block_archives` not `paused`.
- Command `hardening:resume-expired`, scheduled
  `->everyTenMinutes()->withoutOverlapping(15)->runInBackground()->name('hardening-resume-expired')->onOneServer()`
  (the 24 h default mutex expiry blocked uptime checks for three days in August).
  For each open row past `paused_until`, inside its own try/catch:
  project trashed/missing/not configured → close with a note; otherwise **POST**
  resume (never decide from a GET), 60 s timeout; close the row only when the
  response shows `block_archives` state `on`; `plugin_outdated`/`unauthorized` →
  mark `failed_at`; row older than 24 h → mark `failed_at`; when a row is more
  than 30 min overdue and `overdue_notified_at` is null → one
  `HardeningPauseOverdueNotification` to the pausing user and the admins.
- Enable/disable/pause/resume calls write one `Log::info('hardening', [...])`
  line with user id, project id, action, rule, outcome.

## Part 3 — lsm-web

New `src/features/projects/components/HardeningCard.tsx` and
`src/features/projects/hooks/useHardening.ts`; client functions in
`src/lib/lsm-api.ts`; key `queryKeys.projects.securityHardening(id)`; one import
and one line in `SecuritySection.tsx`. i18n under `projects.hardening.*` in both
languages in `src/lib/i18n.ts` (DE in the informal register used elsewhere).

- Card "Server hardening (.htaccess)", three rows, state tag per rule: On / Off /
  Paused (countdown) / Manual / Drift / Not supported (reason tooltip); a sticky
  line under a rule when `last_failure` is set ("Could not be applied on this
  server: <reason>").
- Quiet states instead of toggles: `plugin_outdated` → "Requires plugin 2.10.0 or
  newer — update the plugin"; `reachable:false` → "Site not reachable".
- Switch `checked` is driven by server state only; `onChange` opens the confirm
  (`App.useApp().modal` per house style). Enable text: writes to
  `wp-content/.htaccess` (or uploads), backup made, site tested, auto-undo on
  failure; for `block_archives` with `archive_attachments > 0` an extra warning
  line. `manual` → action labelled "Adopt". `drift` → "Re-apply".
- `block_archives` on → "Pause for download": duration Select (15/30/60, 60
  preselected) rendered **beside** the button (confirm content does not
  re-render with state), confirm text: backups publicly downloadable for N
  minutes; a download that is interrupted and resumed after that will fail.
  While paused: `Statistic.Countdown` on `pause_until * 1000`, refetch at zero,
  "Re-enable now"; `<Alert type="warning">` when `pause_overdue`.
- Turning a rule off: only when `can.disable`, danger confirm.
- Abilities only from `can` (missing → all false); disabled buttons get a tooltip.
- The three POSTs pass `{ timeout: 130000 }`. Errors arrive in `onError`; map
  `error.response.data.reason` through `projects.hardening.reasons.<reason>`
  with `message` as fallback; also treat 2xx with `success === false` as
  failure. Always invalidate the hardening query on settle. Query:
  `enabled: hasLsmConnection`, `staleTime: 30000`.
- The existing security score is left untouched.

## Part 4 — testing and rollout

- **Plugin:** plain PHPUnit 9.6 (composer dev dependency at the repo root,
  outside the zipped plugin dir; `vendor/` and `.phpunit.result.cache` added to
  `.gitignore`) with a hand-written stub bootstrap (in-memory options, temp
  dirs, canned HTTP). Tests: block generation; strict parser (absent / present /
  corrupt markers, outside content preserved, removal + file deletion);
  adoption grammar against real fixtures and near-misses; each failure reason
  → rollback and unchanged state; busy lock; pause / pause-while-paused /
  failed pause restores `pause_until`; auto-resume throttle, fail-closed
  behaviour, `pause_until` only cleared after read-back; crash recovery per op;
  status-from-file incl. `drift` and `manual`; deactivation cleanup.
- **API:** feature tests: permission matrix through the Gate/HTTP (incl.
  `is_admin` flag, unassigned manager GET 200 / POST 403), every row of the
  response-mapping table with `Http::fake`, write-ahead row lifecycle,
  opportunistic close, `hardening:resume-expired` (expired + still paused →
  POST resume → closed; unreachable → stays open; outdated → failed;
  >30 min → one notification, not two; trashed project → closed).
- **Web:** `npm run typecheck && npm run lint && npm run build`, then manual
  browser verification of every state against a fake/real API.
- **Real-site verification before release**, in order: landeseiten.de, one
  Apache client site, one Hostinger/LiteSpeed site, and if available one
  nginx-fronted site (expect an honest `rule_ineffective`). On each: enable all
  three, check the site incl. an anonymous cached page view, pause, download a
  real AI1WM backup, confirm auto-resume, confirm the non-existent-file probe
  behaviour on LiteSpeed (if LiteSpeed answers 404 despite the rule, the
  uploads/debug.log probes need real files — decide then, before release). On
  the test site also force a broken rule to see the rollback.
- **Rollout order:** plugin 2.10.0 first (inert: everything off), then API, then
  web. The panel handles not-yet-updated plugins through `plugin_outdated`.
- **Follow-up outside this spec:** the security-audit procedure should narrow
  its manual archive pattern the same way (bare `.gz` can break WP Super Cache
  expert-mode sites) and, once 2.10.0 is out, enable the toggles instead of
  appending unmarked rules.
