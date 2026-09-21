# Managed .htaccess hardening — design

Date: 2026-09-21
Repos: lsm-wp (plugin), lsm-api, lsm-web
Branch in each repo: `feature/htaccess-hardening`
Plugin release: 2.10.0

## Problem

On sites we cleaned or audited, the security-audit procedure appends a deny rule
for `.wpress|sql|zip|tar|gz|bak` to `wp-content/.htaccess` by hand over SSH. The
rule is intentional (drjung.ch and physioteam-dieterich.de had full-site backups
publicly downloadable), but it has no marker comment, nobody on the team can see
or control it, and it makes the All-in-One WP Migration download button return
403. The only way to get a backup today is SFTP, which the team does not want.

The LSM plugin itself writes no `.htaccess` outside its own
`uploads/lsm-backups/` folder. Its existing security toggles are database
options applied in PHP.

## Goal

Three server-level hardening rules that the team can see, turn on, and (for the
archive rule) pause for a download, from the platform Security panel — with the
write done so carefully that a bad rule cannot take a site down.

Out of scope for v1: security headers via `.htaccess`, XML-RPC / author blocks,
any write to the root `.htaccess`, any UI inside WP admin, a platform activity
log for these actions, a plugin-side authenticated `.wpress` download.

## Rules

| Key | What it does | File |
|---|---|---|
| `block_archives` | deny `.wpress .sql .zip .tar .gz .bak` | `wp-content/.htaccess` |
| `block_debug_log` | deny `debug.log` | `wp-content/.htaccess` |
| `block_uploads_php` | deny `.php` (and `.phtml .php5 .php7 .phar`) | `wp-content/uploads/.htaccess` |

All rules default to off. Installing or updating the plugin changes nothing on
any site.

Each rule body is emitted in both syntaxes so a missing module cannot cause a
500:

```apache
<FilesMatch "\.(wpress|sql|zip|tar|gz|bak)$">
  <IfModule mod_authz_core.c>
    Require all denied
  </IfModule>
  <IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
  </IfModule>
</FilesMatch>
```

`block_archives` does not affect `uploads/lsm-backups/` downloads (served
through PHP) or plugin/theme update zips (downloaded by PHP to a temp dir, never
requested over HTTP from `wp-content`).

## Part 1 — plugin: safe-write engine

New file `includes/class-lsm-hardening.php`, class `LSM_Hardening`. No existing
behaviour changes.

### State

Option `lsm_hardening` (autoloaded, one read per request):

```
rules:        { block_archives: bool, block_debug_log: bool, block_uploads_php: bool }  // desired state
pause_until:  int|null      // unix time; applies to block_archives only
pending:      { file, started_at } | null   // crash-recovery flag
last_result:  { at, action, ok, reason }
```

### Never the root file

Only `wp-content/.htaccess` and `wp-content/uploads/.htaccess` are written. A
broken `.htaccess` in those directories affects only URLs below them; the
homepage, wp-admin and the REST API are served from the root, so the plugin
stays reachable and can undo its own change.

### Apply procedure (every change, including pause and resume)

1. **Preflight.** `SERVER_SOFTWARE` is Apache or LiteSpeed; target file (or its
   directory, if the file is absent) is writable; lock acquired (transient
   `lsm_hardening_lock`, 120 s). Any failure → nothing written, rule status
   `unsupported` with the reason.
2. **Baseline.** Request one static asset of our plugin under `wp-content` and,
   for the uploads file, one existing uploads image if any. Record status codes.
   A non-200 baseline is recorded, not treated as our failure.
3. **Snapshot.** Copy the current file to `.htaccess.lsm-bak` beside it (or
   record "file did not exist"). Set `pending`.
4. **Write.** `insert_with_markers($file, 'LSM-HARDENING', $lines)`. The block
   is always regenerated from the desired state, never edited in place. An empty
   rule set removes the block. (`insert_with_markers` lives in
   `wp-admin/includes/misc.php`, which must be required in REST context.)
5. **Self-test.**
   - Baseline URLs return the same status as before.
   - For each rule now on, a probe proves it is effective: create
     `lsm-probe-<random>.bak` in `wp-content` (archives), request `debug.log`
     only if one exists, create `lsm-probe-<random>.php` in uploads whose body is
     a plain string (uploads PHP). Each must return 403. Probes are deleted in a
     `finally`.
   - When `block_archives` is being paused or turned off, the `.bak` probe must
     NOT return 403. If it still does, some other rule on the server blocks
     archives, the pause would be useless: roll back and report
     `pause_ineffective_foreign_rule`. Other rules get no probe when turned off.
   - Probe requests carry a cache-buster and `Cache-Control: no-cache`.
6. **Rollback.** Any failed check → restore the snapshot (or delete the file if
   it did not exist), re-run the baseline check, set `rules` back to the
   previous desired state, record `last_result.ok=false` with a specific reason:
   `asset_broken`, `rule_ineffective` (typical for nginx in front),
   `write_failed`, `loopback_blocked`.
7. **Finish.** Clear `pending`, release the lock, log through `LSM_Logger`.

If loopback requests to the own site are blocked (some hosts), the self-test
cannot run: the change is rolled back with `loopback_blocked`. We do not apply
untested rules.

### Crash recovery

On plugin load, if `pending` is set and older than 120 s, restore that file's
snapshot, clear `pending`, record `last_result` with reason `crash_recovered`.

### Pause

`pause(minutes)` — allowed values 15, 30, 60 — sets `pause_until` and runs the
apply procedure with `block_archives` omitted from the block. On every plugin
load, if `pause_until` has passed, clear it and re-apply. `resume()` does the
same immediately. Desired state for the rule stays `true` throughout; the pause
is an overlay.

### Manual rules already on a site

The engine recognises the exact blocks the audit procedure appends (archive
deny, `debug.log` deny, uploads `.php` deny — whitespace-normalised exact
match). Status for that rule is `manual`. Turning the toggle on **adopts** it:
the manual block is removed and the managed block written in the same safe
write. Anything not matching exactly is never touched; if a non-matching rule
already blocks the probe, the rule simply reports `on` after enable, and a later
pause is rolled back with `pause_ineffective_foreign_rule` (see self-test) so
the team knows to fix that site over SSH.

### Deactivation / uninstall

Deactivation removes both managed blocks (best-effort, same snapshot logic, no
self-test dependency) and clears `pause_until`. Plugin updates never write.

### REST endpoints (namespace `lsm/v1`, `authenticate` permission callback)

- `GET  /hardening/status` → per rule `on|off|paused|manual|unsupported`,
  `pause_until`, `server`, `last_result`
- `POST /hardening/rule` `{rule, enabled}`
- `POST /hardening/pause` `{minutes}`
- `POST /hardening/resume`

All POSTs return the fresh status plus `last_result`. A rolled-back change is
HTTP 200 with `success:false` and the reason — and the platform must check
`success` explicitly (the backup feature was bitten by not doing so).

## Part 2 — lsm-api

- `LsmService`: `getHardeningStatus()`, `setHardeningRule()`,
  `pauseHardening()`, `resumeHardening()`; POSTs use `UPDATE_TIMEOUT` (120 s).
- Routes beside `security-settings`:
  `GET /projects/{project}/lsm/hardening`,
  `POST …/hardening/rule`, `POST …/hardening/pause`, `POST …/hardening/resume`.
- `ProjectPolicy` abilities:

| Ability | Granted to |
|---|---|
| `pauseHardening` (pause, resume) | anyone with `update` on the project |
| `enableHardening` (rule → on, adopt) | managers who manage the project, admins |
| `disableHardening` (rule → off) | admins (`isAdmin()`, incl. `is_admin` flag) |

- Table `project_hardening_pauses`: `project_id`, `user_id`, `paused_until`,
  `resumed_at` nullable, timestamps. A row is written on pause, closed on
  resume.
- Command `hardening:resume-expired`, scheduled every 10 minutes
  (`withoutOverlapping`): for each open row past `paused_until`, fetch status;
  if still paused call resume; on success close the row. Unreachable site →
  leave open, retry next run. The status response exposes
  `pause_overdue: true` so the panel can warn.
- `success:false` from the plugin maps to HTTP 422 with the reason.

## Part 3 — lsm-web

New card "Server hardening (.htaccess)" in `SecuritySection.tsx`, extracted as
its own component `HardeningCard.tsx` with a `useHardening` hook (the section
file is already large).

- Three rows, status tag per rule: On / Off / Paused (mm left) / Manual /
  Not supported (+reason tooltip).
- Turning on → confirm modal: writes to `wp-content/.htaccess`, backup made,
  site tested, auto-undo on failure. Result toast: "Applied and verified" or
  "Rolled back: <reason>".
- `block_archives` when on → "Pause for download" button, duration select
  15/30/60 with 60 preselected, confirm text states backups are publicly
  downloadable for that time. While paused: countdown + "Re-enable now".
- Permanent off → admins only, danger confirm.
- Buttons disabled with tooltip when the user lacks the ability; abilities come
  from the status response (`can: {pause, enable, disable}`).
- i18n EN + DE.

## Part 4 — testing and rollout

- Plugin unit tests: block generation (both syntaxes), marker write/removal,
  adoption of each known manual block, rollback per failure reason, crash-flag
  recovery, pause/expiry/resume, deactivation cleanup. HTTP self-test calls go
  through one injectable method so tests can fake responses.
- API feature tests: permission matrix per role, `success:false` → 422,
  pause row lifecycle, `hardening:resume-expired` with a faked plugin
  (expired + still paused → resume; unreachable → stays open).
- Web: component tests for status rendering and permission-disabled states.
- Real-site verification before release, in order: landeseiten.de, one Apache
  client site, one Hostinger/LiteSpeed site. On each: enable all three, probes
  403, site renders, pause, download a real All-in-One backup, confirm
  auto-resume. On the test site also force a broken rule to see rollback happen.
  Also confirm the assumption that a download started during the pause
  continues after the rule returns.
- Release 2.10.0 through the normal GitHub release auto-update. Everything is
  off by default, so the fleet update is inert until someone clicks.
- Follow-up outside this spec: the security-audit procedure should write its
  rules inside `# BEGIN LSM-HARDENING` markers (or simply enable the toggles)
  so new cleanups are managed from day one.
