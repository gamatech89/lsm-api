# Managed .htaccess Hardening — lsm-web Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **The plan has seven tasks (Task 1 – Task 7).** The "Appendix — deploy notes" after Task 7 is for a human: it is never dispatched to an implementer and nothing in it is executed as part of this plan.

**Goal:** Add a "Server hardening (.htaccess)" card to the project Security tab that shows the three managed rules as the server reports them and lets the team turn them on, pause the archive rule for a download, re-enable it and (admins only) turn a rule off.

**Architecture:** A new `HardeningCard` renders only what `GET /projects/{id}/lsm/hardening` reports: rule states come from the file the plugin parsed, abilities come from the API's `can`, and nothing is derived from the user's role or from a click. A new `useHardening` hook owns the status query and the three write mutations: it treats any answer that is not `success: true` as a failure, maps the machine `reason` to a translated text with the API's `message` as fallback, and always re-reads the status afterwards — even after a timeout, when nobody knows whether the change went through. `SecuritySection.tsx` gets one import and one line; the existing security score is not touched.

**Tech Stack:** React 18.3, TypeScript 5.9, antd 5.29.3, @tanstack/react-query 5.90, i18next 25 / react-i18next 16, axios 1.7, Vite 6.4.

**Spec:** /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api/docs/superpowers/specs/2026-09-21-htaccess-hardening-design.md (this plan implements "Part 3 — lsm-web" and the web bullet of "Part 4")

## Global Constraints

- **Branch:** `feature/htaccess-hardening`, created from `master` (`82c6ad4` when this plan was written).
- **Never touch the main checkout.** `/Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web` is on `fix/concurrent-plugin-update-guard` with someone's uncommitted work in three `WordPressManagement*.tsx` files. No `git checkout`, `switch`, `stash`, `commit` or `reset` there. All work happens in the worktree `/Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web-hardening` (Task 1). Every relative path in this plan is relative to that worktree.
- **The plan ends at Task 7.** No `git push`, no merge into `master`, no `ssh`/upload, no `git worktree remove`, no request to any real site. Those steps are in the appendix for a human.
- **The local database holds real client sites.** `lsm-api/database/database.sqlite` has more than a hundred projects with real client URLs, and several carry a `health_check_secret` — the local API forwards every `/projects/{id}/lsm/*` call it receives to that project's live WordPress site. Browser checks therefore run ONLY through the mock dev server (`vite.mock.config.ts`, Task 5), which answers every `/lsm/` call itself — never through `npm run dev`. On the project page, click only inside the "Server hardening (.htaccess)" card and the confirm dialogs it opens (the language and theme switches in the app header are fine): the six Switches in the "Security Controls" card above it are real and would change a live client site.
- **Shared machine.** Never kill a process you did not start: not whatever listens on :3000 / :8000, and not the Chrome DevTools browser profile. `.claude/skills/verify/SKILL.md` suggests `lsof -ti :3000 | xargs kill` and `pkill -f chrome-devtools-mcp/chrome-profile` — do NOT run either. Reuse a running API on :8000, let Vite pick the next free port, and if the browser profile is busy, stop and report.
- **There is no test runner in this repo and the spec says not to add one.** The TDD cycle is replaced, per house practice, by this gate at the end of EVERY task — all three must exit 0:
  `npm run typecheck && npm run lint && npm run build`
  plus the concrete manual check the task describes. `npm run build` is `vite build` only and does not typecheck, which is why the three always run together. Lint runs with `--max-warnings 0`.
- **Lint bans literal query keys.** A raw array under `queryKey:` (or a local named `queryKey` initialised with an array) fails lint. The only key for this feature is `queryKeys.projects.securityHardening(id)`.
- **Routes** (all under the API base path, `basePath(projectId)` = `/projects/${projectId}/lsm`): `GET /hardening`, `POST /hardening/rule` `{rule, enabled}`, `POST /hardening/pause` `{minutes}`, `POST /hardening/resume`.
- **Timeouts:** the three POSTs pass `{ timeout: 130000 }` (the API waits up to 120 s for the plugin; the browser must outlast it); the status GET passes `{ timeout: 40000 }` (the API's own wait is 30 s). Query: `enabled: hasLsmConnection`, `staleTime: 30000`, and a `refetchInterval` of 30 s that applies only once a pause has run out (Task 3) — never a constant poll: every status read is a round-trip to the customer's site.
- **Rule keys:** `block_archives`, `block_debug_log`, `block_uploads_php`.
- **Rule states:** `on`, `off`, `paused`, `manual`, `drift`, `unsupported`. `paused` exists for `block_archives` only.
- **`unsupported_reason` values:** `multisite`, `openlitespeed`, `unknown_server`, `not_writable`.
- **Pause minutes:** 15, 30, 60 — 60 preselected.
- **Plugin failure `reason` codes:** `busy`, `invalid_rule`, `invalid_minutes`, `not_enabled`, `unsupported`, `loopback_blocked`, `markers_corrupt`, `snapshot_failed`, `write_failed`, `asset_broken`, `rule_ineffective`, `pause_ineffective_foreign_rule`, `rollback_failed`. `last_result.reason` may also be `crash_recovered`.
- **API-level `reason` codes:** `plugin_outdated` (HTTP 409, with `min_version: '2.10.0'`), `unauthorized` (502), `unreachable` (502, message `Outcome unknown — refresh status`). `busy` is 409; every other plugin refusal is 422.
- **Warnings:** `already_blocked_elsewhere`, `unverified`.
- **Minimum plugin version:** `2.10.0`.
- **Abilities** come only from `can: { pause, enable, disable }` in the API response. `can` missing → all three false. Never re-derive them with `useIsAdmin` / `useHasRole`.
- **Unknown codes must not break the UI.** A `reason`, warning or `unsupported_reason` this build does not know falls back to the API's `message` (or the raw code) — a newer plugin may send new ones. A rule `state` this build does not know shows its raw name in a grey tag and gets no control (never an enabled Switch).
- **i18n:** every string of the card lives under `projects.hardening.*` in `src/lib/i18n.ts`, EN and DE. DE uses the informal register (du-form) and „…“ quotes, like the rest of the file. `projects.security` is already taken by the security-status labels — do not nest under it.
- **The existing security score in `SecuritySection.tsx` is left untouched.**
- **House style:** `App.useApp()` for `modal` / `message` (never the static antd imports), `color="#1e293b"` on every Tooltip, `<Tag style={{ margin: 0 }}>` inside rows, named export for a standalone card, JSDoc that explains why, 2-space indentation, single quotes.
- **Commits:** conventional-commit messages, each ending with the line
  `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`
  Stage files by explicit path — never `git add -A` / `git add .` (Task 5 leaves an untracked throwaway file in the worktree on purpose).

### What was verified while writing this plan — and what was not

- Every code block below was applied to a scratch copy of `master` (`82c6ad4`, taken with `git archive` — the lsm-web checkout and its git state were not touched) against the installed `node_modules`; `tsc --noEmit`, `eslint . --max-warnings 0` and `vite build` all exited 0 — for the final state and for the intermediate state after Task 5. This was repeated after the review revision of this plan, with the blocks extracted from this file as they stand now. The EN/DE parity script from Task 4 passed when run from stdin exactly as written (101 keys each, no file left behind).
- The mock from Task 5 was started on a private port and exercised with `curl`: un-faked `/lsm/` calls (`updates`, `recovery-status`, `POST security-settings`) answer the mock's 503 and go nowhere, `lsm/status` answers `connected: false`, other methods on `hardening*` answer 405; a `paused` scenario turns into `pause_overdue: true` by itself when its time is up and `resume` clears it; default pause length 900 s; `unknown_state`, `nocan` and `http500` answer as documented.
- The hook's `refetchInterval` function was run against the installed `@tanstack/query-core` with a fake server: no poll while the countdown runs, the poll starts with the refetch at zero, and it stops after a successful resume.
- **Not verified: anything in a browser.** The Chrome DevTools browser profile was in use by another session while this plan was written, and it was left alone (see "Shared machine" above — implementers do the same). So these are untested and are exactly what Tasks 5–7 must look at: the Tooltip over a disabled control (the `Explained` wrapper), the `mm:ss` countdown showing `60:00` for a 60-minute pause, the countdown → zero → one refetch → overdue alert transition, the confirm closing after a failed change, and dark-mode contrast of the warning/danger text.

---

## File Structure

| File | Change | Responsibility |
|---|---|---|
| `src/lib/lsm-api.ts` | modify | Exported TypeScript interfaces for the hardening API contract; four client functions (`getHardening`, `setHardeningRule`, `pauseHardening`, `resumeHardening`) |
| `src/lib/queryKeys.ts` | modify | One key: `queryKeys.projects.securityHardening(id)` |
| `src/features/projects/hooks/useHardening.ts` | create | Status query + three mutations; failure detection, reason → text mapping, toasts, invalidate on settle |
| `src/lib/i18n.ts` | modify | `projects.hardening.*` tree, EN and DE |
| `src/features/projects/components/HardeningCard.tsx` | create | The card: quiet states, three rule rows, state tags, sticky failure line, controls and confirm dialogs |
| `src/features/projects/components/sections/SecuritySection.tsx` | modify | One import, one line: mounts `<HardeningCard project={project} />` |
| `vite.mock.config.ts` | create in Task 5, **delete in Task 7, never committed** | Throwaway dev-server config that fakes the hardening endpoints for manual verification and answers every other `/lsm/` call itself (503), so no browser check can reach a real client site |

---

### Task 1: Worktree and a green baseline

The main checkout is dirty on another branch, so the feature gets its own worktree. A fresh worktree has no `node_modules`, and the three local packages (`@lsm/types`, `@lsm/api-client`, `@lsm/utils`) have a gitignored `dist/` with no `prepare` script — `npm ci` alone leaves them unbuilt and the typecheck fails on every `@lsm/*` import.

**Files:** none changed. Creates the worktree `/Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web-hardening`.

**Interfaces:** Consumes: nothing. Produces: branch `feature/htaccess-hardening` checked out in the worktree, dependencies installed, local packages built, gate green. Every later task runs inside this worktree.

- [ ] **Step 1: Create the worktree from `master`**

```bash
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web worktree add /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web-hardening -b feature/htaccess-hardening master
```

Expected: `Preparing worktree (new branch 'feature/htaccess-hardening')` and `HEAD is now at 82c6ad4 …` (or a newer master commit).

- [ ] **Step 2: Confirm the main checkout was not disturbed**

```bash
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web status --short
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web branch --show-current
```

Expected: the same three ` M src/features/projects/components/WordPressManagement*.tsx` lines as before, and `fix/concurrent-plugin-update-guard`. If anything else changed, stop and report.

- [ ] **Step 3: Install dependencies**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web-hardening
npm ci
```

Expected: exits 0. `tsup` is installed by this (it is in the lockfile as a dev dependency of the local packages).

- [ ] **Step 4: Build the three local packages (`types` first — the other two depend on it)**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web-hardening
npm run build --prefix packages/types
npm run build --prefix packages/api-client
npm run build --prefix packages/utils
```

Expected: each ends with `DTS ⚡️ Build success` and creates `packages/<name>/dist/index.d.ts`. `dist/` is gitignored, so `git status --short` stays empty.

- [ ] **Step 5: Run the gate on the untouched branch**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web-hardening
npm run typecheck && npm run lint && npm run build
```

Expected: all three exit 0 (the build prints a chunk-size warning; that is normal). If this fails before any change was made, stop and report — do not fix unrelated code on this branch.

- [ ] **Step 6: Manual check**

```bash
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web-hardening status --short
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web-hardening branch --show-current
```

Expected: empty status, branch `feature/htaccess-hardening`. Nothing to commit in this task.

---

### Task 2: API contract types, client functions and query key

**Files:**
- Modify: `src/lib/lsm-api.ts` — insert interfaces between the `LsmRecoveryStatus` interface (ends line 84) and `export function createLsmApi` (line 86); insert client functions after `getSecurityHeaderSnippets` (ends line 442), before the `// SECURITY SCANNING` banner (line 444). Line numbers are for `master` `82c6ad4`.
- Modify: `src/lib/queryKeys.ts` — one line after `securityScanLatest` (lines 46-47).

**Interfaces:**
- Consumes: nothing.
- Produces (all exported from `@/lib/lsm-api`): types `HardeningRuleKey`, `HardeningRuleState`, `HardeningUnsupportedReason`, `HardeningPauseMinutes`; interfaces `HardeningRuleStatus`, `HardeningLastResult`, `HardeningStatus`, `HardeningAbilities`, `HardeningOverview` (GET body), `HardeningActionResponse` (200 body of the POSTs), `HardeningErrorBody` (409/422/502 body).
- Produces on `api.lsm`:
  - `getHardening(projectId: number): Promise<AxiosResponse<HardeningOverview>>`
  - `setHardeningRule(projectId: number, rule: HardeningRuleKey, enabled: boolean): Promise<AxiosResponse<HardeningActionResponse>>`
  - `pauseHardening(projectId: number, minutes: HardeningPauseMinutes): Promise<AxiosResponse<HardeningActionResponse>>`
  - `resumeHardening(projectId: number): Promise<AxiosResponse<HardeningActionResponse>>`
- Produces: `queryKeys.projects.securityHardening(id: number | string)` → `['projects', Number(id), 'security', 'hardening']`.

The types live in `src/lib/lsm-api.ts`, not in `packages/types`: that package's `dist/` is gitignored and would have to be rebuilt on every machine and before every deploy.

- [ ] **Step 1: Add the interfaces to `src/lib/lsm-api.ts`**

Insert this block above the line `export function createLsmApi(client: AxiosInstance) {`. Keep one blank line above the new comment (between the closing `}` of the `LsmRecoveryStatus` interface and `// Managed .htaccess hardening …`) and one blank line below the block (between the closing `}` of `HardeningErrorBody` and `export function createLsmApi`):

```ts
// Managed .htaccess hardening — shapes are fixed by the API contract
// (lsm-api docs/superpowers/specs/2026-09-21-htaccess-hardening-design.md).

export type HardeningRuleKey = 'block_archives' | 'block_debug_log' | 'block_uploads_php';

export type HardeningRuleState = 'on' | 'off' | 'paused' | 'manual' | 'drift' | 'unsupported';

export type HardeningUnsupportedReason =
  | 'multisite'
  | 'openlitespeed'
  | 'unknown_server'
  | 'not_writable';

export type HardeningPauseMinutes = 15 | 30 | 60;

export interface HardeningRuleStatus {
  state: HardeningRuleState;
  desired: boolean;
  unsupported_reason: HardeningUnsupportedReason | null;
  /** Sticky until the next successful apply of this rule. `at` is unix seconds. */
  last_failure: { at: number; reason: string } | null;
}

export interface HardeningLastResult {
  at: number;
  /** enable | disable | pause | resume | auto_resume | crash_recovery | deactivate */
  action: string;
  rule: HardeningRuleKey | null;
  ok: boolean;
  reason: string | null;
  warnings: string[];
}

export interface HardeningStatus {
  plugin_version: string;
  server: string;
  rules: Record<HardeningRuleKey, HardeningRuleStatus>;
  /** Unix seconds, block_archives only. Stays set (in the past) while a resume is overdue. */
  pause_until: number | null;
  pause_overdue: boolean;
  archive_attachments: number;
  last_result: HardeningLastResult | null;
}

/** Per user and project, straight from the API's Gate. Never derived from the role client-side. */
export interface HardeningAbilities {
  pause: boolean;
  enable: boolean;
  disable: boolean;
}

/**
 * GET /hardening. Always HTTP 200: an old plugin or an unreachable site comes
 * back as flags with `status: null`, not as an error.
 */
export interface HardeningOverview {
  reachable: boolean;
  plugin_outdated: boolean;
  min_version: string;
  status: HardeningStatus | null;
  /** The platform's own open pause row. Only its presence is used here. */
  open_pause: Record<string, unknown> | null;
  pause_overdue: boolean;
  can?: HardeningAbilities;
}

/** 200 body of the three hardening POSTs. */
export interface HardeningActionResponse {
  success: boolean;
  message: string;
  warnings: string[];
  status: HardeningStatus;
  open_pause: Record<string, unknown> | null;
  pause_overdue: boolean;
  can?: HardeningAbilities;
}

/**
 * Body of a failed hardening POST (409 busy / plugin_outdated, 422 plugin
 * refusal or rollback, 502 unauthorized / unreachable). `reason` is a plain
 * string on purpose: a newer plugin may send codes this build does not know.
 */
export interface HardeningErrorBody {
  success: false;
  reason: string;
  message: string;
  status?: HardeningStatus;
  min_version?: string;
}
```

- [ ] **Step 2: Add the four client functions to `src/lib/lsm-api.ts`**

Find the end of `getSecurityHeaderSnippets`:

```ts
      }>(`${basePath(projectId)}/security-headers/snippets`),
```

Right after that line insert a blank line, then this block — every other banner in the file has a blank line above it. The existing blank line and the `// SECURITY SCANNING` banner stay below the block:

```ts
    // ================================================================
    // SERVER HARDENING (.htaccess)
    // ================================================================

    /**
     * Get managed .htaccess hardening status (always answers 200, see HardeningOverview)
     */
    getHardening: (projectId: number) =>
      // The API waits up to 30 s for the plugin; the client default is 30 s too, so without
      // this the browser gives up first and a hanging site never reaches the quiet state.
      client.get<HardeningOverview>(`${basePath(projectId)}/hardening`, { timeout: 40000 }),

    /**
     * Turn one hardening rule on or off. The API waits up to 120 s for the
     * plugin's write + self-test, so the browser has to wait longer than that.
     */
    setHardeningRule: (projectId: number, rule: HardeningRuleKey, enabled: boolean) =>
      client.post<HardeningActionResponse>(
        `${basePath(projectId)}/hardening/rule`,
        { rule, enabled },
        { timeout: 130000 }
      ),

    /**
     * Pause the archive rule for a download (same long timeout as above)
     */
    pauseHardening: (projectId: number, minutes: HardeningPauseMinutes) =>
      client.post<HardeningActionResponse>(
        `${basePath(projectId)}/hardening/pause`,
        { minutes },
        { timeout: 130000 }
      ),

    /**
     * Put the archive rule back before the pause runs out (same long timeout as above)
     */
    resumeHardening: (projectId: number) =>
      client.post<HardeningActionResponse>(
        `${basePath(projectId)}/hardening/resume`,
        {},
        { timeout: 130000 }
      ),
```

- [ ] **Step 3: Add the query key to `src/lib/queryKeys.ts`**

Find:

```ts
    securityScanLatest: (id: Id) =>
      ['projects', n(id), 'security', 'scan-latest'] as const,
```

Add directly below it:

```ts
    securityHardening: (id: Id) => ['projects', n(id), 'security', 'hardening'] as const,
```

The key sits under `['projects', id, …]` on purpose: anything that invalidates `queryKeys.projects.detail(id)` refreshes it too.

- [ ] **Step 4: Run the gate**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web-hardening
npm run typecheck && npm run lint && npm run build
```

Expected: all three exit 0.

- [ ] **Step 5: Manual check**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web-hardening
grep -c "timeout: 130000" src/lib/lsm-api.ts
grep -n "hardening" src/lib/lsm-api.ts | grep basePath
grep -n "securityHardening" src/lib/queryKeys.ts
```

Expected: `3`; four `basePath` lines ending in `/hardening`, `/hardening/rule`, `/hardening/pause`, `/hardening/resume`; one `securityHardening` line. Then open the spec's Part 2 response block and compare it key by key with `HardeningStatus` / `HardeningOverview` — every key in the spec must exist with the same spelling (`plugin_version`, `server`, `rules`, `state`, `desired`, `unsupported_reason`, `last_failure`, `pause_until`, `pause_overdue`, `archive_attachments`, `last_result`, `reachable`, `plugin_outdated`, `min_version`, `status`, `open_pause`, `can`).

- [ ] **Step 6: Commit**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web-hardening
git add src/lib/lsm-api.ts src/lib/queryKeys.ts
git commit -m "feat(hardening): API types, client functions and query key

Shapes follow the htaccess-hardening spec. The three POSTs wait 130 s
because the API itself waits up to 120 s for the plugin.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 3: `useHardening` hook

No hook in this repo bundles a query with mutations yet — mutations are normally declared inline in components. This one is extracted because its error handling is the part that must not be copied wrong: the existing security toggle in `SecuritySection.tsx` never checks `success` in the body, and here a rolled-back change arrives as a failure that must never read as "applied".

**Files:**
- Create: `src/features/projects/hooks/useHardening.ts`

**Interfaces:**
- Consumes (Task 2): `api.lsm.getHardening`, `api.lsm.setHardeningRule`, `api.lsm.pauseHardening`, `api.lsm.resumeHardening`; `queryKeys.projects.securityHardening(id)`; types `HardeningAbilities`, `HardeningActionResponse`, `HardeningErrorBody`, `HardeningOverview`, `HardeningPauseMinutes`, `HardeningRuleKey` from `@/lib/lsm-api`.
- Consumes (Task 4, as string keys only — the hook compiles without them): `projects.hardening.toasts.{enabled,disabled,paused,resumed,genericError}`, `projects.hardening.reasons.<reason>`, `projects.hardening.warnings.<warning>`, `projects.hardening.rules.<rule>.label`.
- Produces: `useHardening(projectId: number, enabled: boolean)` returning
  - `query: UseQueryResult<HardeningOverview>` — polls every 30 s once a pause has run out (overdue, or `pause_until` in the past), otherwise never
  - `can: HardeningAbilities` — all false when the response has no `can`
  - `setRule(rule: HardeningRuleKey, on: boolean): Promise<void>`
  - `pause(minutes: HardeningPauseMinutes): Promise<void>`
  - `resume(): Promise<void>`
  - `pendingRule: HardeningRuleKey | null`
  - `isPausing: boolean`, `isResuming: boolean`, `isBusy: boolean`

  The three action promises **never reject**: they are handed to antd's `modal.confirm({ onOk })`, and a rejected `onOk` keeps the dialog open and logs an unhandled rejection. Failures are toasted inside the hook.

Behaviour to keep exactly:
- A 2xx answer without `success: true` is thrown from `mutationFn`, so it reaches `onError` like a 4xx.
- `onError` maps `reason` through `projects.hardening.reasons.<reason>` with the body's `message` as `defaultValue`. No usable answer (browser timeout, network, or a body that is not a JSON object — an HTML gateway page, whether it came with a 2xx or an error status) is treated as `unreachable` — outcome unknown. `failureBody()` applies the object check on both paths, the thrown `HardeningFailure` and the axios error; an HTML page must never produce the definite "The change could not be applied".
- `onSettled` returns the invalidation promise, so the mutation stays pending until the fresh status is in. The card therefore never shows a state the server did not report, and the confirm closes at the moment the row is true.
- `refetchInterval` polls every 30 s **only** once a pause has run out: the top-level `pause_overdue` is true, or `status.pause_until` is in the past by the browser's clock (which also covers a browser clock that runs ahead of the server — the card would otherwise sit on `00:00`). The real API answers `pause_overdue: true` at the moment the countdown reaches zero, and the rule comes back on the site's own schedule afterwards; without the poll the alarming overdue alert would stay until the next window focus. No polling while a countdown is still running, and none when nothing is paused — every read is a round-trip to the customer's site. TanStack Query re-evaluates the function after every query update, so the refetch the card fires at zero (Task 6) is what switches the poll on; it pauses by itself while the window is not focused.

- [ ] **Step 1: Create `src/features/projects/hooks/useHardening.ts`**

```ts
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { App } from 'antd';
import { useTranslation } from 'react-i18next';
import { api } from '@/lib/api';
import { queryKeys } from '@/lib/queryKeys';
import type {
  HardeningAbilities,
  HardeningActionResponse,
  HardeningErrorBody,
  HardeningOverview,
  HardeningPauseMinutes,
  HardeningRuleKey,
} from '@/lib/lsm-api';

/** `can` missing from the response (web deployed before the API) means: nothing is allowed. */
const NO_ABILITIES: HardeningAbilities = { pause: false, enable: false, disable: false };

/**
 * A 2xx answer that does not say `success: true`. The API is specified to
 * answer 409/422 for a refused or rolled-back change, but a rolled-back change
 * must never read as "applied" just because something in between passed the
 * plugin's HTTP 200 through. Thrown from mutationFn so it lands in onError
 * exactly like a 4xx.
 */
class HardeningFailure extends Error {
  readonly body: Partial<HardeningErrorBody>;

  constructor(body: Partial<HardeningErrorBody>) {
    super(body.message ?? 'Hardening change failed');
    this.body = body;
  }
}

function unwrap(res: { data: HardeningActionResponse }): HardeningActionResponse {
  if (res.data?.success !== true) {
    throw new HardeningFailure((res.data ?? {}) as Partial<HardeningErrorBody>);
  }
  return res.data;
}

/**
 * The JSON body of a failed call, whichever way it failed. `undefined` when
 * there was no usable answer — nothing came back, or what came back is not a
 * JSON object (an HTML gateway page, with a 2xx or an error status alike).
 */
function failureBody(error: unknown): Partial<HardeningErrorBody> | undefined {
  const data: unknown =
    error instanceof HardeningFailure
      ? error.body
      : (error as { response?: { data?: unknown } } | null)?.response?.data;
  return typeof data === 'object' && data !== null ? (data as Partial<HardeningErrorBody>) : undefined;
}

/**
 * Status query plus the three write actions for the managed .htaccess
 * hardening card.
 *
 * Every write can take up to two minutes (the plugin backs the file up, writes
 * it and tests the live site), and any of them can end in an automatic
 * rollback. So: failures are toasted here by machine `reason`, the status is
 * always re-read afterwards — even after a timeout, when nobody knows whether
 * the change went through — and the mutation only settles once that re-read
 * is in, so the card never shows a state the server did not report.
 */
export function useHardening(projectId: number, enabled: boolean) {
  const { t } = useTranslation();
  const { message } = App.useApp();
  const queryClient = useQueryClient();

  const query = useQuery<HardeningOverview>({
    queryKey: queryKeys.projects.securityHardening(projectId),
    queryFn: () => api.lsm.getHardening(projectId).then(r => r.data),
    enabled,
    staleTime: 30000,
    // Once a pause has run out the rule comes back on the site's own schedule
    // (its next page load, or the platform's 10-minute backstop), so keep
    // looking until it has — otherwise the overdue alert outlives the problem.
    // Not while the countdown is still running: the card refetches at zero
    // anyway, and every read is a round-trip to the customer's site.
    refetchInterval: q => {
      const overview = q.state.data;
      const until = overview?.status?.pause_until;
      return overview?.pause_overdue || (until != null && until * 1000 <= Date.now()) ? 30000 : false;
    },
  });

  const refresh = () =>
    queryClient.invalidateQueries({ queryKey: queryKeys.projects.securityHardening(projectId) });

  const describeError = (error: unknown): string => {
    const body = failureBody(error);
    const fallback =
      (typeof body?.message === 'string' && body.message) || t('projects.hardening.toasts.genericError');
    // No usable answer (browser timeout, network, an HTML gateway page): the
    // change may or may not have been applied — same wording as the API's own
    // 'unreachable'.
    const reason = typeof body?.reason === 'string' ? body.reason : body ? undefined : 'unreachable';
    if (!reason) return fallback;
    return t(`projects.hardening.reasons.${reason}`, {
      defaultValue: fallback,
      version: body?.min_version ?? '2.10.0',
    });
  };

  const showWarnings = (warnings: string[] | undefined) => {
    (warnings ?? []).forEach(warning =>
      message.warning(t(`projects.hardening.warnings.${warning}`, { defaultValue: warning }), 8)
    );
  };

  const onError = (error: unknown) => {
    message.error(describeError(error), 8);
  };

  const ruleMutation = useMutation({
    mutationFn: ({ rule, enabled: on }: { rule: HardeningRuleKey; enabled: boolean }) =>
      api.lsm.setHardeningRule(projectId, rule, on).then(unwrap),
    onSuccess: (body, { rule, enabled: on }) => {
      const label = t(`projects.hardening.rules.${rule}.label`);
      message.success(
        t(on ? 'projects.hardening.toasts.enabled' : 'projects.hardening.toasts.disabled', { rule: label })
      );
      showWarnings(body.warnings);
    },
    onError,
    onSettled: refresh,
  });

  const pauseMutation = useMutation({
    mutationFn: (minutes: HardeningPauseMinutes) =>
      api.lsm.pauseHardening(projectId, minutes).then(unwrap),
    onSuccess: (body, minutes) => {
      message.success(t('projects.hardening.toasts.paused', { minutes }));
      showWarnings(body.warnings);
    },
    onError,
    onSettled: refresh,
  });

  const resumeMutation = useMutation({
    mutationFn: () => api.lsm.resumeHardening(projectId).then(unwrap),
    onSuccess: body => {
      message.success(t('projects.hardening.toasts.resumed'));
      showWarnings(body.warnings);
    },
    onError,
    onSettled: refresh,
  });

  // The confirm dialogs hand these straight to antd's onOk. They must not
  // reject: the failure is already toasted above, and a rejected onOk would
  // keep the dialog open over a card that has just refreshed.
  const settle = (promise: Promise<unknown>): Promise<void> =>
    promise.then(
      () => undefined,
      () => undefined
    );

  return {
    query,
    can: { ...NO_ABILITIES, ...query.data?.can },
    setRule: (rule: HardeningRuleKey, on: boolean) => settle(ruleMutation.mutateAsync({ rule, enabled: on })),
    pause: (minutes: HardeningPauseMinutes) => settle(pauseMutation.mutateAsync(minutes)),
    resume: () => settle(resumeMutation.mutateAsync()),
    /** The rule whose row shows a spinner right now, if any. */
    pendingRule: ruleMutation.isPending ? ruleMutation.variables?.rule ?? null : null,
    isPausing: pauseMutation.isPending,
    isResuming: resumeMutation.isPending,
    /** One write at a time — the plugin answers `busy` to a second one anyway. */
    isBusy: ruleMutation.isPending || pauseMutation.isPending || resumeMutation.isPending,
  };
}
```

- [ ] **Step 2: Run the gate**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web-hardening
npm run typecheck && npm run lint && npm run build
```

Expected: all three exit 0. A lint error `Use queryKeys.* from src/lib/queryKeys.ts` means a key literal slipped in.

- [ ] **Step 3: Manual check**

The hook has no UI of its own; its behaviour is exercised in the browser in Tasks 5–7. Here, check the properties that are easy to lose in an edit:

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web-hardening
grep -c "onSettled: refresh" src/features/projects/hooks/useHardening.ts
grep -n "success !== true" src/features/projects/hooks/useHardening.ts
grep -c "typeof data === 'object'" src/features/projects/hooks/useHardening.ts
grep -c "refetchInterval:" src/features/projects/hooks/useHardening.ts
grep -n "useIsAdmin\|useHasRole\|useCurrentUser" src/features/projects/hooks/useHardening.ts
```

Expected: `3`; one line; `1` (the one object check in `failureBody` covers both failure paths); `1`; no output (abilities never come from the role).

- [ ] **Step 4: Commit**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web-hardening
git add src/features/projects/hooks/useHardening.ts
git commit -m "feat(hardening): useHardening hook

Status query plus rule/pause/resume mutations. Anything that is not
success:true is a failure, reasons map to i18n with the API message as
fallback, and the status is re-read on settle - also after a timeout.
Once a pause has run out the status is polled every 30 s until the
rule is back.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 4: i18n — `projects.hardening.*` in EN and DE

**Files:**
- Modify: `src/lib/i18n.ts` — EN: insert after the `projects.uptime` block (closes at line 320), before `security: {` (line 321). DE: insert after the DE `projects.uptime` block (closes at line 1726 on `master`), before `security: {` (line 1727). After the EN insert the DE line numbers shift by 123 lines — use the anchor text, not the number.

**Interfaces:**
- Consumes: nothing.
- Produces: the complete key tree below, identical in both languages. Later tasks reference these keys as strings: `title`, `titleHint`, `rules.<rule>.{label,description,hint}`, `states.<state>`, `stateHints.{manual,drift}`, `unsupportedReasons.<reason>`, `lastFailure`, `quiet.{pluginOutdated,unreachable,openPause,loadError,retry}`, `actions.{adopt,reapply,pause,resume}`, `pauseOption`, `overdue.{title,description}`, `noPermission.{enable,disable,pause}`, `confirm.archiveAttachments` (plural pair `_one` / `_other`), `confirm.{enable,adopt,reapply}.{title,content,okText}`, `confirm.disable.{title,content,archivesNote,okText}`, `confirm.pause.{title,content,okText}`, `toasts.{enabled,disabled,paused,resumed,genericError}`, `reasons.<reason>`, `warnings.<warning>`.

Notes for the implementer:
- i18next 25 uses the `_one` / `_other` plural suffixes. The single `_plural` key elsewhere in this file is a dead leftover from the old format — do not copy it.
- The reason texts are written to work both as a toast and after the colon of `lastFailure` ("Could not be applied on this server: …").
- The confirm text for the pause carries the sentence the spec requires: a download that is interrupted and resumed after the pause will fail (a later HTTP Range request is a new request and gets 403).
- The cancel button uses the existing `common.cancel` — no new key.

- [ ] **Step 1: Insert the EN block**

Find this in the EN half (around line 314-320):

```ts
          apiKeyConfiguredPlaceholder: '••••••••  (configured — enter a new key to replace)',
        },
```

Insert directly below the `},`:

```ts
        hardening: {
          title: 'Server hardening (.htaccess)',
          titleHint: 'Rules the LSM plugin writes into the .htaccess files under wp-content. Every change is backed up and tested against the live site, and undone automatically if anything breaks.',
          rules: {
            block_archives: {
              label: 'Block backup & archive downloads',
              description: 'Denies direct downloads of .wpress, .sql, .zip, .tar, .tgz and .bak files under wp-content',
              hint: 'A full-site backup left under wp-content can be downloaded by anyone who guesses its name. Compound endings such as .sql.gz and .tar.gz are blocked too; plain .gz is not, because page caches deliver .html.gz files.',
            },
            block_debug_log: {
              label: 'Block debug.log',
              description: 'Denies access to wp-content/debug.log',
              hint: 'debug.log can contain file paths, database queries and personal data.',
            },
            block_uploads_php: {
              label: 'Block PHP in uploads',
              description: 'Denies requests for PHP files inside the uploads folder',
              hint: 'A PHP file dropped into uploads is the most common way a cleaned site gets infected again. No legitimate plugin runs PHP from there.',
            },
          },
          states: {
            on: 'On',
            off: 'Off',
            paused: 'Paused',
            manual: 'Manual',
            drift: 'Drift',
            unsupported: 'Not supported',
          },
          stateHints: {
            manual: 'A hand-written rule with the same effect is already in the file. Adopt it to manage it from here.',
            drift: 'This rule should be on, but it is missing from the file (restore, migration or manual edit). Re-apply it.',
          },
          unsupportedReasons: {
            multisite: 'WordPress multisite is not supported.',
            openlitespeed: 'OpenLiteSpeed does not apply access rules from .htaccess.',
            unknown_server: 'Unknown web server ({{server}}) — only Apache and LiteSpeed apply .htaccess rules.',
            not_writable: 'WordPress cannot write the .htaccess file (or its folder).',
          },
          lastFailure: 'Could not be applied on this server: {{reason}}',
          quiet: {
            pluginOutdated: 'Requires plugin {{version}} or newer — update the plugin',
            unreachable: 'Site not reachable',
            openPause: 'A download pause is still open for this site.',
            loadError: 'Hardening status could not be loaded',
            retry: 'Retry',
          },
          actions: {
            adopt: 'Adopt',
            reapply: 'Re-apply',
            pause: 'Pause for download',
            resume: 'Re-enable now',
          },
          pauseOption: '{{minutes}} min',
          overdue: {
            title: 'The pause is over, but the archive block is not back yet',
            description: 'Backups may still be publicly downloadable. The site retries on its own; you can also re-enable the rule now.',
          },
          noPermission: {
            enable: 'Only admins and the managers of this project can turn rules on',
            disable: 'Only admins can turn a rule off',
            pause: 'You need edit rights on this project to pause or re-enable the rule',
          },
          confirm: {
            archiveAttachments_one: '{{count}} downloadable archive in the media library will stop working.',
            archiveAttachments_other: '{{count}} downloadable archives in the media library will stop working.',
            enable: {
              title: 'Turn on “{{rule}}”?',
              content: 'This writes a rule to {{file}}. The file is backed up first, then the site is tested. If anything breaks, the change is undone automatically. This can take up to two minutes.',
              okText: 'Turn on',
            },
            adopt: {
              title: 'Adopt the manual rule for “{{rule}}”?',
              content: 'The hand-written rule in {{file}} is replaced by the managed one in a single write. The file is backed up first, then the site is tested. If anything breaks, the change is undone automatically.',
              okText: 'Adopt',
            },
            reapply: {
              title: 'Re-apply “{{rule}}”?',
              content: 'The rule is missing from {{file}}. It is written again the safe way: the file is backed up first, then the site is tested. If anything breaks, the change is undone automatically.',
              okText: 'Re-apply',
            },
            disable: {
              title: 'Turn off “{{rule}}”?',
              content: 'The rule is removed from {{file}}. The site stays unprotected until someone turns it on again.',
              archivesNote: 'Just need to download a backup? Use “Pause for download” instead — it turns itself back on.',
              okText: 'Turn off',
            },
            pause: {
              title: 'Pause the archive block for {{minutes}} minutes?',
              content: 'Backups under wp-content are publicly downloadable for {{minutes}} minutes. The rule comes back on its own afterwards. A download that is interrupted and resumed after that will fail.',
              okText: 'Pause',
            },
          },
          toasts: {
            enabled: '“{{rule}}” is on — applied and verified',
            disabled: '“{{rule}}” is off',
            paused: 'Archive block paused for {{minutes}} minutes',
            resumed: 'Archive block is back on',
            genericError: 'The change could not be applied',
          },
          reasons: {
            busy: 'Another hardening change is running on this site. Try again in a minute.',
            invalid_rule: 'Unknown rule.',
            invalid_minutes: 'A pause can last 15, 30 or 60 minutes.',
            not_enabled: 'The archive block is not on, so there is nothing to pause.',
            unsupported: 'This server does not support the rule.',
            loopback_blocked: 'The site could not test itself — a firewall or the host blocks requests from the server to itself. Nothing was changed.',
            markers_corrupt: 'The LSM-HARDENING markers in the .htaccess file are damaged. Nothing was written — repair the file by hand first.',
            snapshot_failed: 'The backup copy of the .htaccess file could not be written. Nothing was changed.',
            write_failed: 'The .htaccess file could not be written. The original was restored.',
            asset_broken: 'The site broke with the new rule, so the change was undone.',
            rule_ineffective: 'The rule was written but had no effect — another server in front (nginx, for example) delivers these files. The change was undone.',
            pause_ineffective_foreign_rule: 'Backups are still blocked by a rule that is not ours (another .htaccess, the host or a firewall). The change was undone.',
            rollback_failed: 'The change failed and the automatic undo failed too. Check the site and its .htaccess now.',
            crash_recovered: 'The last change was interrupted and has been cleaned up. Check the rule states above.',
            plugin_outdated: 'Requires plugin {{version}} or newer — update the plugin.',
            unauthorized: 'The site rejected the platform’s API key. Check the WordPress connection.',
            unreachable: 'No answer from the site — the outcome is unknown. The status is being refreshed.',
          },
          warnings: {
            already_blocked_elsewhere: 'These files were already blocked by something else (host, firewall or another rule) before ours was added.',
            unverified: 'The rule was written, but the site could not confirm it with a test request.',
          },
        },
```

- [ ] **Step 2: Insert the DE block**

Find this in the DE half:

```ts
          apiKeyConfiguredPlaceholder: '••••••••  (konfiguriert — neuen Schlüssel eingeben, um zu ersetzen)',
        },
```

Insert directly below the `},`:

```ts
        hardening: {
          title: 'Server-Härtung (.htaccess)',
          titleHint: 'Regeln, die das LSM-Plugin in die .htaccess-Dateien unter wp-content schreibt. Jede Änderung wird vorher gesichert, an der Live-Seite getestet und automatisch zurückgenommen, wenn etwas kaputtgeht.',
          rules: {
            block_archives: {
              label: 'Backup- & Archiv-Downloads sperren',
              description: 'Sperrt den direkten Download von .wpress-, .sql-, .zip-, .tar-, .tgz- und .bak-Dateien unter wp-content',
              hint: 'Ein Komplett-Backup unter wp-content kann jeder herunterladen, der den Dateinamen errät. Zusammengesetzte Endungen wie .sql.gz und .tar.gz sind mit gesperrt; reines .gz nicht, weil Seiten-Caches .html.gz-Dateien ausliefern.',
            },
            block_debug_log: {
              label: 'debug.log sperren',
              description: 'Sperrt den Zugriff auf wp-content/debug.log',
              hint: 'Die debug.log kann Dateipfade, Datenbankabfragen und personenbezogene Daten enthalten.',
            },
            block_uploads_php: {
              label: 'PHP in uploads sperren',
              description: 'Sperrt Aufrufe von PHP-Dateien im uploads-Ordner',
              hint: 'Eine PHP-Datei im uploads-Ordner ist der häufigste Weg, auf dem eine bereinigte Seite erneut infiziert wird. Kein seriöses Plugin führt dort PHP aus.',
            },
          },
          states: {
            on: 'An',
            off: 'Aus',
            paused: 'Pausiert',
            manual: 'Manuell',
            drift: 'Abweichung',
            unsupported: 'Nicht unterstützt',
          },
          stateHints: {
            manual: 'In der Datei steht bereits eine von Hand geschriebene Regel mit derselben Wirkung. Übernimm sie, um sie von hier aus zu verwalten.',
            drift: 'Diese Regel sollte an sein, fehlt aber in der Datei (Wiederherstellung, Umzug oder manuelle Änderung). Wende sie erneut an.',
          },
          unsupportedReasons: {
            multisite: 'WordPress-Multisite wird nicht unterstützt.',
            openlitespeed: 'OpenLiteSpeed wendet Zugriffsregeln aus der .htaccess nicht an.',
            unknown_server: 'Unbekannter Webserver ({{server}}) — nur Apache und LiteSpeed wenden .htaccess-Regeln an.',
            not_writable: 'WordPress kann die .htaccess-Datei (oder ihren Ordner) nicht beschreiben.',
          },
          lastFailure: 'Ließ sich auf diesem Server nicht anwenden: {{reason}}',
          quiet: {
            pluginOutdated: 'Benötigt Plugin {{version}} oder neuer — aktualisiere das Plugin',
            unreachable: 'Seite nicht erreichbar',
            openPause: 'Für diese Seite ist noch eine Download-Pause offen.',
            loadError: 'Der Härtungs-Status konnte nicht geladen werden',
            retry: 'Erneut versuchen',
          },
          actions: {
            adopt: 'Übernehmen',
            reapply: 'Erneut anwenden',
            pause: 'Für Download pausieren',
            resume: 'Jetzt wieder aktivieren',
          },
          pauseOption: '{{minutes}} Min.',
          overdue: {
            title: 'Die Pause ist vorbei, aber die Archiv-Sperre ist noch nicht zurück',
            description: 'Backups sind möglicherweise noch öffentlich herunterladbar. Die Seite versucht es von selbst erneut; du kannst die Regel auch jetzt wieder aktivieren.',
          },
          noPermission: {
            enable: 'Nur Admins und die Manager dieses Projekts können Regeln einschalten',
            disable: 'Nur Admins können eine Regel ausschalten',
            pause: 'Zum Pausieren oder Wiederaktivieren brauchst du Bearbeitungsrechte für dieses Projekt',
          },
          confirm: {
            archiveAttachments_one: '{{count}} herunterladbares Archiv in der Mediathek funktioniert danach nicht mehr.',
            archiveAttachments_other: '{{count}} herunterladbare Archive in der Mediathek funktionieren danach nicht mehr.',
            enable: {
              title: '„{{rule}}“ einschalten?',
              content: 'Dabei wird eine Regel in {{file}} geschrieben. Die Datei wird vorher gesichert, danach wird die Seite getestet. Geht etwas kaputt, wird die Änderung automatisch zurückgenommen. Das kann bis zu zwei Minuten dauern.',
              okText: 'Einschalten',
            },
            adopt: {
              title: 'Manuelle Regel für „{{rule}}“ übernehmen?',
              content: 'Die von Hand geschriebene Regel in {{file}} wird in einem Schreibvorgang durch die verwaltete ersetzt. Die Datei wird vorher gesichert, danach wird die Seite getestet. Geht etwas kaputt, wird die Änderung automatisch zurückgenommen.',
              okText: 'Übernehmen',
            },
            reapply: {
              title: '„{{rule}}“ erneut anwenden?',
              content: 'Die Regel fehlt in {{file}}. Sie wird auf dem sicheren Weg neu geschrieben: Die Datei wird vorher gesichert, danach wird die Seite getestet. Geht etwas kaputt, wird die Änderung automatisch zurückgenommen.',
              okText: 'Erneut anwenden',
            },
            disable: {
              title: '„{{rule}}“ ausschalten?',
              content: 'Die Regel wird aus {{file}} entfernt. Die Seite bleibt ungeschützt, bis jemand sie wieder einschaltet.',
              archivesNote: 'Du willst nur ein Backup herunterladen? Nimm stattdessen „Für Download pausieren“ — das schaltet sich von selbst wieder ein.',
              okText: 'Ausschalten',
            },
            pause: {
              title: 'Archiv-Sperre für {{minutes}} Minuten pausieren?',
              content: 'Backups unter wp-content sind {{minutes}} Minuten lang öffentlich herunterladbar. Danach kommt die Regel von selbst zurück. Ein Download, der unterbrochen und erst danach fortgesetzt wird, schlägt fehl.',
              okText: 'Pausieren',
            },
          },
          toasts: {
            enabled: '„{{rule}}“ ist an — angewendet und geprüft',
            disabled: '„{{rule}}“ ist aus',
            paused: 'Archiv-Sperre für {{minutes}} Minuten pausiert',
            resumed: 'Archiv-Sperre ist wieder an',
            genericError: 'Die Änderung konnte nicht angewendet werden',
          },
          reasons: {
            busy: 'Auf dieser Seite läuft gerade eine andere Härtungs-Änderung. Versuch es in einer Minute noch einmal.',
            invalid_rule: 'Unbekannte Regel.',
            invalid_minutes: 'Eine Pause kann 15, 30 oder 60 Minuten dauern.',
            not_enabled: 'Die Archiv-Sperre ist nicht an, es gibt also nichts zu pausieren.',
            unsupported: 'Dieser Server unterstützt die Regel nicht.',
            loopback_blocked: 'Die Seite konnte sich nicht selbst testen — eine Firewall oder der Hoster blockiert Anfragen des Servers an sich selbst. Es wurde nichts geändert.',
            markers_corrupt: 'Die LSM-HARDENING-Markierungen in der .htaccess-Datei sind beschädigt. Es wurde nichts geschrieben — repariere die Datei zuerst von Hand.',
            snapshot_failed: 'Die Sicherungskopie der .htaccess-Datei konnte nicht geschrieben werden. Es wurde nichts geändert.',
            write_failed: 'Die .htaccess-Datei konnte nicht geschrieben werden. Das Original wurde wiederhergestellt.',
            asset_broken: 'Mit der neuen Regel war die Seite kaputt, deshalb wurde die Änderung zurückgenommen.',
            rule_ineffective: 'Die Regel wurde geschrieben, hatte aber keine Wirkung — ein vorgeschalteter Server (zum Beispiel nginx) liefert diese Dateien aus. Die Änderung wurde zurückgenommen.',
            pause_ineffective_foreign_rule: 'Backups werden weiterhin von einer Regel gesperrt, die nicht von uns stammt (andere .htaccess, Hoster oder Firewall). Die Änderung wurde zurückgenommen.',
            rollback_failed: 'Die Änderung ist fehlgeschlagen, und das automatische Zurücknehmen auch. Prüfe jetzt die Seite und ihre .htaccess.',
            crash_recovered: 'Die letzte Änderung wurde unterbrochen und wieder aufgeräumt. Prüfe die Regel-Zustände oben.',
            plugin_outdated: 'Benötigt Plugin {{version}} oder neuer — aktualisiere das Plugin.',
            unauthorized: 'Die Seite hat den API-Schlüssel der Plattform abgelehnt. Prüfe die WordPress-Verbindung.',
            unreachable: 'Keine Antwort von der Seite — das Ergebnis ist unbekannt. Der Status wird neu geladen.',
          },
          warnings: {
            already_blocked_elsewhere: 'Diese Dateien waren schon vorher anderweitig gesperrt (Hoster, Firewall oder eine andere Regel), bevor unsere dazukam.',
            unverified: 'Die Regel wurde geschrieben, aber die Seite konnte sie nicht mit einer Testanfrage bestätigen.',
          },
        },
```

- [ ] **Step 3: Run the gate**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web-hardening
npm run typecheck && npm run lint && npm run build
```

Expected: all three exit 0. A typecheck error in `i18n.ts` almost always means a straight `'` ended up inside a single-quoted string — the texts use the typographic `’`, `“…”` and `„…“` on purpose.

- [ ] **Step 4: Manual check — EN/DE parity and spec coverage**

Run this check straight from stdin — do not save it as a file anywhere. Nothing is written outside the worktree, and nothing is left inside it either: ESLint picks up every `.cjs` file in the worktree and has no Node globals configured for it, so a saved copy would make `npm run lint` fail with `'console' is not defined`. The quoted `'EOF'` keeps the script's backticks and `${…}` literal; `node -` reads the script as CommonJS even though the project's `package.json` says `"type": "module"`.

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web-hardening
node - <<'EOF'
// Reads src/lib/i18n.ts relative to the current directory (the worktree root).
// Exit 0 = EN and DE hardening trees match and nothing from the spec is missing.
const fs = require('fs');
const lines = fs.readFileSync('src/lib/i18n.ts', 'utf8').split('\n');
const starts = [];
lines.forEach((line, i) => { if (/^ {8}hardening: \{$/.test(line)) starts.push(i); });
if (starts.length !== 2) { console.error(`expected 2 "hardening: {" blocks, found ${starts.length}`); process.exit(1); }
const keysOf = start => {
  const out = [];
  const stack = [];
  for (let i = start + 1; i < lines.length; i++) {
    if (/^ {8}\},$/.test(lines[i])) break;
    const m = lines[i].match(/^( +)([A-Za-z_]\w*): /);
    if (!m) continue;
    const depth = (m[1].length - 10) / 2;
    stack.length = depth;
    stack[depth] = m[2];
    out.push(stack.join('.'));
  }
  return out;
};
const [en, de] = starts.map(keysOf);
const missing = (a, b) => a.filter(k => !b.includes(k));
const required = [
  ...['busy', 'invalid_rule', 'invalid_minutes', 'not_enabled', 'unsupported', 'loopback_blocked', 'markers_corrupt',
    'snapshot_failed', 'write_failed', 'asset_broken', 'rule_ineffective', 'pause_ineffective_foreign_rule',
    'rollback_failed', 'crash_recovered', 'plugin_outdated', 'unauthorized', 'unreachable'].map(k => `reasons.${k}`),
  ...['already_blocked_elsewhere', 'unverified'].map(k => `warnings.${k}`),
  ...['multisite', 'openlitespeed', 'unknown_server', 'not_writable'].map(k => `unsupportedReasons.${k}`),
  ...['on', 'off', 'paused', 'manual', 'drift', 'unsupported'].map(k => `states.${k}`),
  'confirm.archiveAttachments_one', 'confirm.archiveAttachments_other', 'confirm.pause.content',
];
const problems = [
  ...missing(en, de).map(k => `missing in DE: ${k}`),
  ...missing(de, en).map(k => `missing in EN: ${k}`),
  ...missing(required, en).map(k => `required by the spec, missing: ${k}`),
];
console.log(`EN keys: ${en.length}, DE keys: ${de.length}`);
problems.forEach(p => console.log(p));
process.exit(problems.length ? 1 : 0);
EOF
echo "exit: $?"
```

Expected: `EN keys: 101, DE keys: 101`, no `missing` lines, `exit: 0`. Then read the DE block once as a German speaker would: informal address only (no `Sie` / `Ihr` as address — the one `Sie` in `confirm.reapply.content` is the pronoun for „die Regel“), „…“ quotes.

- [ ] **Step 5: Commit**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web-hardening
git add src/lib/i18n.ts
git commit -m "feat(hardening): i18n EN + DE under projects.hardening

Every plugin and API reason code, both warnings, the unsupported
reasons, state labels and all confirm texts.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 5: Read-only `HardeningCard`, mounted in the Security tab

This task delivers everything the card shows without any control: loading, the three quiet states, the three rows with state tags and tooltips, the sticky failure line, the overdue alert. It is mounted in the same task — that one line is what makes the card checkable in the browser — and the throwaway mock that all later manual checks use is set up here.

**Files:**
- Create: `src/features/projects/components/HardeningCard.tsx`
- Modify: `src/features/projects/components/sections/SecuritySection.tsx` — one import after line 66, one line between line 615 (`</Card>` closing "Security Controls") and line 617 (`{/* Security Headers */}`). Line numbers are for `master` `82c6ad4`.
- Create (untracked, deleted in Task 7): `vite.mock.config.ts`

**Interfaces:**
- Consumes (Task 3): `useHardening(projectId, enabled)` → `{ query }` (the other members are used from Task 6 on).
- Consumes (Task 2): types `HardeningRuleKey`, `HardeningRuleState`, `HardeningRuleStatus`.
- Consumes (Task 4): the `projects.hardening.*` keys.
- Produces: `export function HardeningCard({ project }: { project: { id: number; has_health_check_secret?: boolean } })`.

Design decisions already made (do not re-open them):
- State tag colours: `on` success, `off` default, `paused` warning, `manual` blue, `drift` orange, `unsupported` default.
- `unsupported` carries its reason in a tooltip on the tag; `manual` and `drift` explain themselves the same way.
- The overdue alert reads the **top-level** `pause_overdue` (the API already folds the plugin's own flag into it) and shows in quiet states too — the platform knows about an open pause even when the site cannot be asked.
- `crash_recovered` and `rollback_failed` are the two `last_result` reasons that belong to no rule row (crash recovery), so they get their own quiet line. Nothing else of `last_result` is shown.
- The card has `marginTop: 16` and `marginBottom: 16`: the "HTTP Security Headers" card below it has no margin of its own.

- [ ] **Step 1: Create `src/features/projects/components/HardeningCard.tsx`**

```tsx
/**
 * Server hardening (.htaccess) card
 *
 * Three rules the LSM plugin writes into wp-content/.htaccess and
 * uploads/.htaccess. Everything shown here is the server's view: a switch
 * follows the state the plugin read from the file, never the click, and what
 * the user may do comes from the API's `can`, never from their role.
 */

import { Alert, Button, Card, Space, Spin, Tag, Tooltip, Typography } from 'antd';
import { InfoCircleOutlined, LockOutlined } from '@ant-design/icons';
import { useTranslation } from 'react-i18next';
import { useThemeStore } from '@/stores/theme';
import { useHardening } from '../hooks/useHardening';
import type { HardeningRuleKey, HardeningRuleState, HardeningRuleStatus } from '@/lib/lsm-api';

const { Text } = Typography;

interface HardeningCardProps {
  project: { id: number; has_health_check_secret?: boolean };
}

const RULES: HardeningRuleKey[] = ['block_archives', 'block_debug_log', 'block_uploads_php'];

const STATE_COLOR: Record<HardeningRuleState, string> = {
  on: 'success',
  off: 'default',
  paused: 'warning',
  manual: 'blue',
  drift: 'orange',
  unsupported: 'default',
};

export function HardeningCard({ project }: HardeningCardProps) {
  const { t } = useTranslation();
  const { resolvedTheme } = useThemeStore();
  const isDark = resolvedTheme === 'dark';
  const hasLsmConnection = !!project.has_health_check_secret;

  const { query } = useHardening(project.id, hasLsmConnection);
  const { data, isLoading, isError, refetch } = query;
  const status = data?.status ?? null;

  const ruleLabel = (rule: HardeningRuleKey) => t(`projects.hardening.rules.${rule}.label`);

  const renderStateTag = (rule: HardeningRuleStatus) => {
    // A state this build does not know (newer plugin) shows as its raw name in a
    // grey tag, not as an i18n key.
    const tag = (
      <Tag color={STATE_COLOR[rule.state]} style={{ margin: 0 }}>
        {t(`projects.hardening.states.${rule.state}`, { defaultValue: rule.state })}
      </Tag>
    );
    let hint: string | undefined;
    if (rule.state === 'unsupported' && rule.unsupported_reason) {
      hint = t(`projects.hardening.unsupportedReasons.${rule.unsupported_reason}`, {
        defaultValue: rule.unsupported_reason,
        server: status?.server ?? '?',
      });
    } else if (rule.state === 'manual' || rule.state === 'drift') {
      hint = t(`projects.hardening.stateHints.${rule.state}`);
    }
    return hint ? (
      <Tooltip title={hint} color="#1e293b">
        <span style={{ cursor: 'help' }}>{tag}</span>
      </Tooltip>
    ) : (
      tag
    );
  };

  const renderBody = () => {
    if (isLoading) {
      return (
        <div style={{ textAlign: 'center', padding: 24 }}>
          <Spin />
        </div>
      );
    }

    if (isError || !data) {
      return (
        <Space>
          <Text type="secondary">{t('projects.hardening.quiet.loadError')}</Text>
          <Button type="link" size="small" onClick={() => refetch()}>
            {t('projects.hardening.quiet.retry')}
          </Button>
        </Space>
      );
    }

    // The platform keeps its own record of an open pause, so an overdue one
    // shows even when the site itself cannot be asked.
    const overdueAlert = data.pause_overdue && (
      <Alert
        type="warning"
        showIcon
        message={t('projects.hardening.overdue.title')}
        description={t('projects.hardening.overdue.description')}
        style={{ marginBottom: 12 }}
      />
    );

    if (data.plugin_outdated || !data.reachable || !status) {
      return (
        <div>
          {overdueAlert}
          <Text type="secondary">
            {data.plugin_outdated
              ? t('projects.hardening.quiet.pluginOutdated', { version: data.min_version || '2.10.0' })
              : t('projects.hardening.quiet.unreachable')}
          </Text>
          {!data.pause_overdue && data.open_pause && (
            <div style={{ marginTop: 4 }}>
              <Text type="warning">{t('projects.hardening.quiet.openPause')}</Text>
            </div>
          )}
        </div>
      );
    }

    return (
      <div>
        {overdueAlert}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 0 }}>
          {RULES.map((key, index) => {
            const rule = status.rules[key];
            if (!rule) return null;
            return (
              <div
                key={key}
                style={{
                  display: 'flex',
                  justifyContent: 'space-between',
                  alignItems: 'center',
                  flexWrap: 'wrap',
                  gap: 12,
                  padding: '16px 0',
                  borderBottom:
                    index < RULES.length - 1 ? `1px solid ${isDark ? '#334155' : '#e2e8f0'}` : undefined,
                }}
              >
                <div style={{ flex: 1, minWidth: 240 }}>
                  <Space>
                    <Text strong>{ruleLabel(key)}</Text>
                    <Tooltip title={t(`projects.hardening.rules.${key}.hint`)} color="#1e293b">
                      <InfoCircleOutlined style={{ color: '#94a3b8', cursor: 'help' }} />
                    </Tooltip>
                    {renderStateTag(rule)}
                  </Space>
                  <div>
                    <Text type="secondary" style={{ fontSize: 13 }}>
                      {t(`projects.hardening.rules.${key}.description`)}
                    </Text>
                  </div>
                  {rule.last_failure && (
                    <div style={{ marginTop: 4 }}>
                      <Text type="danger" style={{ fontSize: 12 }}>
                        {t('projects.hardening.lastFailure', {
                          reason: t(`projects.hardening.reasons.${rule.last_failure.reason}`, {
                            defaultValue: rule.last_failure.reason,
                            version: data.min_version || '2.10.0',
                          }),
                        })}
                      </Text>
                    </div>
                  )}
                </div>
              </div>
            );
          })}
        </div>
        {/* The one last_result reason that belongs to no rule row. */}
        {(status.last_result?.reason === 'crash_recovered' || status.last_result?.reason === 'rollback_failed') && (
          <div style={{ marginTop: 8 }}>
            <Text type="secondary" style={{ fontSize: 12 }}>
              {t(`projects.hardening.reasons.${status.last_result.reason}`)}
            </Text>
          </div>
        )}
      </div>
    );
  };

  return (
    <Card
      title={
        <Space>
          <LockOutlined style={{ color: '#3b82f6' }} />
          <span>{t('projects.hardening.title')}</span>
          <Tooltip title={t('projects.hardening.titleHint')} color="#1e293b">
            <InfoCircleOutlined style={{ color: '#94a3b8', cursor: 'help' }} />
          </Tooltip>
        </Space>
      }
      style={{
        marginTop: 16,
        marginBottom: 16,
        borderRadius: 12,
        background: isDark ? '#1e293b' : '#fff',
      }}
    >
      {renderBody()}
    </Card>
  );
}
```

- [ ] **Step 2: Mount it in `SecuritySection.tsx`**

Add the import below the existing `import { queryKeys } from '@/lib/queryKeys';` (line 66):

```tsx
import { HardeningCard } from '../HardeningCard';
```

Find the end of the "Security Controls" card and the start of the headers card:

```tsx
        )}
      </Card>

      {/* Security Headers */}
```

Change it to:

```tsx
        )}
      </Card>

      <HardeningCard project={project} />

      {/* Security Headers */}
```

Nothing else in this file changes — not the score, not the headers card.

- [ ] **Step 3: Run the gate**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web-hardening
npm run typecheck && npm run lint && npm run build
```

Expected: all three exit 0.

- [ ] **Step 4: Create the throwaway mock `vite.mock.config.ts` in the worktree root**

The real hardening endpoints do not exist yet (the API plan runs in parallel), and no local WordPress site can produce `drift`, `unsupported` or an overdue pause on demand. This file is a second Vite config: it fakes the four hardening endpoints, keeps a small in-memory server state so a successful POST really moves a rule, answers the three other Security-tab GETs and `lsm/status` with canned data, and marks every project as "WordPress connected". Only non-LSM routes (login, project list/detail) are proxied to the local API; every un-faked `/lsm/` call answers 503. That last part is a safety rule, not a convenience: the local database holds real client sites, some with a working key, and the local API would forward any `/lsm/` call it receives to the project's live WordPress site (`POST lsm/security-settings` from one of the six "Security Controls" Switches included). Like the real API, the mock reports a pause whose time is up as `pause_overdue` until something resumes it. It is **never committed** and is deleted in Task 7. It is not linted, typechecked or used by `npm run build` (a root-level `.ts` file matches none of the lint globs, `tsc` only covers `src`, and `vite build` reads `vite.config.*`).

```ts
// THROWAWAY — manual verification of the hardening card only. Never commit.
// Run:  VITE_API_URL=/api/v1 npx vite --config vite.mock.config.ts
// Steer: open http://localhost:3000/__hardening?scenario=all_off&role=manager&outcome=ok
//        (&delay=<ms> for the POSTs, &seconds=<n> for the length of the `paused` scenario)
// Only non-LSM routes (login, project list/detail) are proxied to the local API;
// every un-faked /lsm/ call answers 503 — nothing here ever reaches a real site.
import { defineConfig, type Plugin } from 'vite';
import type { IncomingMessage, ServerResponse } from 'node:http';
import react from '@vitejs/plugin-react';
import path from 'path';

const API = 'http://localhost:8000';
const RULES = ['block_archives', 'block_debug_log', 'block_uploads_php'] as const;
type RuleKey = (typeof RULES)[number];

const CAN: Record<string, object | undefined> = {
  admin: { pause: true, enable: true, disable: true },
  manager: { pause: true, enable: true, disable: false },
  developer: { pause: true, enable: false, disable: false },
  unassigned: { pause: false, enable: false, disable: false },
  nocan: undefined, // `can` key missing altogether
};

const now = () => Math.floor(Date.now() / 1000);
const rule = (state: string, extra: object = {}) => ({
  state,
  desired: state === 'on' || state === 'paused' || state === 'drift',
  unsupported_reason: null,
  last_failure: null,
  ...extra,
});
const baseStatus = (rules: Record<RuleKey, object>, extra: object = {}) => ({
  plugin_version: '2.10.0',
  server: 'Apache',
  rules,
  pause_until: null,
  pause_overdue: false,
  archive_attachments: 0,
  last_result: null,
  ...extra,
});
const all = (state: string) =>
  ({ block_archives: rule(state), block_debug_log: rule(state), block_uploads_php: rule(state) });

// Each scenario returns the GET body without `can`.
const SCENARIOS: Record<string, (seconds: number) => any> = {
  all_off: () => ok(baseStatus(all('off'))),
  attachments: () => ok(baseStatus(all('off'), { archive_attachments: 3 })),
  one_attachment: () => ok(baseStatus(all('off'), { archive_attachments: 1 })),
  all_on: () => ok(baseStatus(all('on'))),
  paused: seconds =>
    ok(baseStatus({ ...all('on'), block_archives: rule('paused') }, { pause_until: now() + seconds }), {
      open_pause: { id: 1 },
    }),
  overdue: () =>
    ok(
      baseStatus({ ...all('on'), block_archives: rule('paused') }, { pause_until: now() - 600, pause_overdue: true }),
      { open_pause: { id: 1 }, pause_overdue: true }
    ),
  manual: () =>
    ok(baseStatus({ ...all('off'), block_archives: rule('manual'), block_uploads_php: rule('manual') }, { archive_attachments: 3 })),
  drift: () => ok(baseStatus({ ...all('on'), block_archives: rule('drift') })),
  // A state from a newer plugin that this build has never heard of
  unknown_state: () => ok(baseStatus({ ...all('on'), block_debug_log: rule('quarantined') })),
  unsupported: () =>
    ok(
      baseStatus({
        block_archives: rule('unsupported', { unsupported_reason: 'multisite' }),
        block_debug_log: rule('unsupported', { unsupported_reason: 'openlitespeed' }),
        block_uploads_php: rule('unsupported', { unsupported_reason: 'not_writable' }),
      })
    ),
  unknown_server: () =>
    ok(
      baseStatus(
        {
          block_archives: rule('unsupported', { unsupported_reason: 'unknown_server' }),
          block_debug_log: rule('unsupported', { unsupported_reason: 'unknown_server' }),
          block_uploads_php: rule('unsupported', { unsupported_reason: 'some_future_reason' }),
        },
        { server: 'nginx/1.24.0' }
      )
    ),
  last_failure: () =>
    ok(
      baseStatus(
        {
          block_archives: rule('on', { last_failure: { at: now() - 3600, reason: 'pause_ineffective_foreign_rule' } }),
          block_debug_log: rule('off', { last_failure: { at: now() - 3600, reason: 'some_future_reason' } }),
          block_uploads_php: rule('off', { last_failure: { at: now() - 3600, reason: 'rule_ineffective' } }),
        },
        { last_result: { at: now() - 60, action: 'crash_recovery', rule: null, ok: false, reason: 'crash_recovered', warnings: [] } }
      )
    ),
  outdated: () => ({ reachable: true, plugin_outdated: true, min_version: '2.10.0', status: null, open_pause: null, pause_overdue: false }),
  unreachable: () => ({ reachable: false, plugin_outdated: false, min_version: '2.10.0', status: null, open_pause: null, pause_overdue: false }),
  unreachable_pause: () => ({ reachable: false, plugin_outdated: false, min_version: '2.10.0', status: null, open_pause: { id: 1 }, pause_overdue: false }),
  unreachable_overdue: () => ({ reachable: false, plugin_outdated: false, min_version: '2.10.0', status: null, open_pause: { id: 1 }, pause_overdue: true }),
};

function ok(status: object, extra: object = {}) {
  return { reachable: true, plugin_outdated: false, min_version: '2.10.0', status, open_pause: null, pause_overdue: false, ...extra };
}

// POST outcomes other than 'ok' / 'warn' / 'soft_fail' / 'forbidden': HTTP status per the API's mapping table.
const FAIL_HTTP: Record<string, number> = { busy: 409, plugin_outdated: 409, unauthorized: 502, unreachable: 502 };

let scenario = 'all_off';
let role = 'manager';
let outcome = 'ok';
let delay = 1500;
let body: any = SCENARIOS[scenario](900);

const send = (res: ServerResponse, code: number, json: unknown) => {
  res.statusCode = code;
  res.setHeader('Content-Type', 'application/json');
  res.end(JSON.stringify(json));
};
const readJson = (req: IncomingMessage) =>
  new Promise<any>(resolve => {
    let raw = '';
    req.on('data', chunk => (raw += chunk));
    req.on('end', () => {
      try {
        resolve(raw ? JSON.parse(raw) : {});
      } catch {
        resolve({});
      }
    });
  });
const withCan = (json: object) => (CAN[role] ? { ...json, can: CAN[role] } : json);

function hardeningMock(): Plugin {
  return {
    name: 'lsm-hardening-mock',
    configureServer(server) {
      server.middlewares.use(async (req, res, next) => {
        const url = new URL(req.url ?? '/', 'http://localhost');

        // Control panel
        if (url.pathname === '/__hardening') {
          const q = url.searchParams;
          role = q.get('role') ?? role;
          outcome = q.get('outcome') ?? outcome;
          delay = Number(q.get('delay') ?? delay);
          if (q.get('scenario')) {
            scenario = q.get('scenario')!;
            if (scenario !== 'http500' && !SCENARIOS[scenario]) return send(res, 400, { error: 'unknown scenario', scenarios: [...Object.keys(SCENARIOS), 'http500'] });
            if (scenario !== 'http500') body = SCENARIOS[scenario](Number(q.get('seconds') ?? 900));
          }
          return send(res, 200, { scenario, role, outcome, delay, scenarios: [...Object.keys(SCENARIOS), 'http500'], roles: Object.keys(CAN) });
        }

        // Every project counts as "WordPress connected", so any local project works
        const detail = url.pathname.match(/^\/api\/v1\/projects\/(\d+)$/);
        if (detail && req.method === 'GET') {
          try {
            const upstream = await fetch(API + req.url, {
              headers: { Accept: 'application/json', Authorization: String(req.headers.authorization ?? '') },
            });
            const json: any = await upstream.json();
            if (json?.data) json.data.has_health_check_secret = true;
            return send(res, upstream.status, json);
          } catch {
            return send(res, 502, { message: 'mock: lsm-api is not running on :8000' });
          }
        }

        const lsm = url.pathname.match(/^\/api\/v1\/projects\/\d+\/lsm\/(.+)$/);
        if (!lsm) return next();
        const tail = lsm[1];

        // Just enough for the rest of the Security tab to render without a real WordPress site
        if (req.method === 'GET' && tail === 'health')
          return send(res, 200, { success: true, data: { ssl: { enabled: true }, plugins: { total: 0, active: 0, outdated_count: 0, list: [] } } });
        if (req.method === 'GET' && tail === 'security-settings')
          return send(res, 200, { success: true, data: { comments_enabled: false, registration_enabled: false, xmlrpc_enabled: false, rest_api_public: false, file_editing_disabled: true, debug_enabled: false, security_headers_enabled: false } });
        if (req.method === 'GET' && tail === 'security-headers')
          return send(res, 200, { success: true, data: { headers: {}, score: 0, present_count: 0, total_count: 0 } });

        // Nothing else under /lsm/ may reach the real API: it would forward the call to the
        // project's real WordPress site (the local DB holds real client URLs, some with a key).
        if (!tail.startsWith('hardening')) {
          // connected:false keeps the project page from asking for recovery-status and updates.
          // The Security tab renders anyway — open it by URL, the side nav greys it out.
          if (req.method === 'GET' && tail === 'status')
            return send(res, 200, { configured: true, connected: false, plugin_version: null, message: 'mock' });
          return send(res, 503, { message: `mock: lsm/${tail} is not faked - nothing was sent to a real site` });
        }

        if (req.method === 'GET' && tail === 'hardening') {
          if (scenario === 'http500') return send(res, 500, { message: 'Server Error' });
          // Like the real API: a pause whose time is up reads as overdue until something resumes it.
          const until = body.status?.pause_until;
          if (until != null && until < now()) {
            body.pause_overdue = true;
            body.status.pause_overdue = true;
          }
          return send(res, 200, withCan(body));
        }

        if (req.method !== 'POST') return send(res, 405, { message: 'mock: not faked' });
        const input = await readJson(req);
        await new Promise(resolve => setTimeout(resolve, delay));

        // Quiet scenarios (outdated / unreachable) have no status to move
        if (!body.status) return send(res, 409, { success: false, reason: 'plugin_outdated', min_version: '2.10.0', message: 'mock: no status in this scenario' });

        const tailState = { status: body.status, open_pause: body.open_pause, pause_overdue: body.pause_overdue };
        if (outcome === 'forbidden') return send(res, 403, { message: 'This action is unauthorized.' });
        if (outcome === 'soft_fail')
          return send(res, 200, withCan({ success: false, reason: 'asset_broken', message: 'Rolled back: site check failed', warnings: [], ...tailState }));
        if (outcome !== 'ok' && outcome !== 'warn') {
          const base = { success: false, reason: outcome, message: `Plugin message for ${outcome}` };
          // Like the API: platform-level failures carry no status / open_pause / pause_overdue / can.
          if (outcome === 'plugin_outdated') return send(res, 409, { ...base, min_version: '2.10.0' });
          if (outcome === 'unauthorized' || outcome === 'unreachable') return send(res, 502, base);
          return send(res, FAIL_HTTP[outcome] ?? 422, withCan({ ...base, warnings: [], ...tailState }));
        }

        // Success: move the fake server state, then answer like the API does
        const rules = body.status.rules;
        if (tail === 'hardening/rule') {
          rules[input.rule as RuleKey] = rule(input.enabled ? 'on' : 'off');
        } else if (tail === 'hardening/pause') {
          rules.block_archives = rule('paused');
          body.status.pause_until = now() + Number(input.minutes) * 60;
          body.open_pause = { id: 2 };
        } else if (tail === 'hardening/resume') {
          rules.block_archives = rule('on');
          body.status.pause_until = null;
          body.status.pause_overdue = false;
          body.open_pause = null;
          body.pause_overdue = false;
        }
        return send(
          res,
          200,
          withCan({
            success: true,
            message: 'Applied and verified',
            warnings: outcome === 'warn' ? ['already_blocked_elsewhere', 'unverified'] : [],
            status: body.status,
            open_pause: body.open_pause,
            pause_overdue: body.pause_overdue,
          })
        );
      });
    },
  };
}

export default defineConfig({
  plugins: [react(), hardeningMock()],
  resolve: { alias: { '@': path.resolve(__dirname, './src') } },
  server: {
    port: 3000,
    proxy: { '/api': { target: API, changeOrigin: true } },
  },
});
```

- [ ] **Step 5: Start the local API and the mock dev server**

Read `.claude/skills/verify/SKILL.md` in the worktree first — it documents the local login and the antd traps of browser automation (a synthetic click often misses antd handlers; use a programmatic `.click()`). **Two of its instructions do not apply here and must not be run:** `lsof -ti :3000 | xargs kill` / `lsof -ti :8000 | xargs kill`, and `pkill -f "chrome-devtools-mcp/chrome-profile"`. This is a shared machine (see Global Constraints): another session may own those ports and that browser profile. If the browser profile is busy, stop and report. And do not start the app with `npm run dev` as the skill says — only with the mock config below.

```bash
# Terminal 1 — only if nothing listens on :8000 yet
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan serve

# Terminal 2
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web-hardening
VITE_API_URL=/api/v1 npx vite --config vite.mock.config.ts
```

`VITE_API_URL=/api/v1` matters: with an absolute API URL the browser would talk to `:8000` directly and never pass the mock — and an un-mocked `/lsm/` call reaches a real client site. **If port 3000 or 8000 is already in use, it belongs to someone else's session — do not kill it.** Reuse a running API on `:8000`; for the web, Vite picks the next free port by itself — use the URL it prints (replace `3000` below accordingly).

Before opening any project, prove that the mock is the one answering (use the port Vite printed for YOUR server — if :3000 was taken, the server on :3000 is somebody else's and is not the mock). This must print the mock's own 503 body, not a Laravel 401:

```bash
curl -s http://localhost:3000/api/v1/projects/1/lsm/updates
```

Expected: `{"message":"mock: lsm/updates is not faked - nothing was sent to a real site"}`. Anything else: stop, you are not talking to the mock.

- [ ] **Step 6: Manual check — every read-only state**

Log in, open any project at `http://localhost:3000/projects/<id>?section=security` — by URL: the mock reports `lsm/status` as not connected on purpose, so the WordPress entries in the project's side nav are greyed out. Steer the mock from a second tab with `http://localhost:3000/__hardening?scenario=<name>&role=manager`, then reload the project tab (F5). All steering values persist until changed.

**Do not click anything outside the "Server hardening (.htaccess)" card — the six Switches in "Security Controls" above it are real.** Under the mock their POST is answered with a 503 and goes nowhere, but that is the second line of defence, not a licence. This step needs hovers only (plus, if you like, the card's own "Retry" link).

Check each row of this table:

| `scenario=` | Expected in the "Server hardening (.htaccess)" card |
|---|---|
| `all_off` | Three rows, each with a grey "Off" tag, label, info icon (hover → hint) and a grey description line. No controls yet. The card sits between "Security Controls" and "HTTP Security Headers" with a gap above and below. |
| `all_on` | Three green "On" tags. |
| `paused` | First row: orange "Paused" tag. No alert. (The mock's pause runs 900 s from the moment the scenario is set, or `&seconds=<n>`; once it is up the mock reports it as overdue, like the real API. Set the scenario again to restart it.) |
| `overdue` | Yellow alert on top: "The pause is over, but the archive block is not back yet". First row "Paused". |
| `manual` | Rows 1 and 3: blue "Manual" tag; hover → "A hand-written rule with the same effect…". |
| `drift` | Row 1: orange "Drift" tag; hover → "This rule should be on, but it is missing from the file…". |
| `unsupported` | Three grey "Not supported" tags; hover shows multisite / OpenLiteSpeed / not writable. |
| `unknown_state` | Row 2: a grey tag with the raw text `quarantined` — not the key `projects.hardening.states.quarantined`. Rows 1 and 3 "On". Nothing breaks. |
| `unknown_server` | Rows 1-2 tooltip: "Unknown web server (nginx/1.24.0) — …". Row 3 tooltip shows the raw code `some_future_reason` (unknown code must not break the UI). |
| `last_failure` | Row 1 (On) and row 3 (Off) each show a red line "Could not be applied on this server: …" with the translated reason; row 2 shows the raw `some_future_reason`. Below the rows, a grey line: "The last change was interrupted and has been cleaned up. Check the rule states above." |
| `outdated` | No rows. One grey line: "Requires plugin 2.10.0 or newer — update the plugin". No error toast. |
| `unreachable` | No rows. "Site not reachable". No error toast. |
| `unreachable_pause` | "Site not reachable" plus a yellow line "A download pause is still open for this site." |
| `unreachable_overdue` | The yellow overdue alert plus "Site not reachable". |
| `http500` | After the automatic retry (a few seconds): "Hardening status could not be loaded" with a "Retry" link. No red error toast. |

Also confirm the rest of the tab was not touched (under the mock the score is computed from canned settings, so comparing numbers in the browser proves nothing — check the diff instead):

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web-hardening
git diff --numstat master -- src/features/projects/components/sections/SecuritySection.tsx
```

Expected: `3	0	…SecuritySection.tsx` — three added lines (the import, the card, one blank line), none removed.

- [ ] **Step 7: Commit (the mock stays untracked)**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web-hardening
git add src/features/projects/components/HardeningCard.tsx src/features/projects/components/sections/SecuritySection.tsx
git commit -m "feat(hardening): read-only HardeningCard in the Security tab

Rule rows with state tags, sticky failure line, overdue alert and quiet
states for an outdated plugin or an unreachable site. Controls follow.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
git status --short
```

Expected after the commit: exactly one line, `?? vite.mock.config.ts`.

---

### Task 6: Controls — toggles, Adopt / Re-apply, pause, countdown, re-enable

**Files:**
- Modify: `src/features/projects/components/HardeningCard.tsx` — replace the whole file with the final version below.

**Interfaces:**
- Consumes (Task 3): `useHardening(projectId, enabled)` → `{ query, can, setRule, pause, resume, pendingRule, isPausing, isResuming, isBusy }`; `setRule(rule, on)`, `pause(minutes)` and `resume()` return promises that never reject.
- Consumes (Task 2): types `HardeningPauseMinutes`, `HardeningRuleKey`, `HardeningRuleState`, `HardeningRuleStatus`.
- Consumes (Task 4): `actions.*`, `pauseOption`, `noPermission.*`, `confirm.*`, plus everything Task 5 already uses.
- Produces: the finished card. Same export, same props.

What changes against Task 5 (everything else in the file is identical):
- Imports: `useState`, `ReactNode`; antd `App`, `Select`, `Statistic`, `Switch`; icon `PauseCircleOutlined`; type `HardeningPauseMinutes`.
- `RULES` now carries the file each rule lives in (for the confirm texts); new `PAUSE_OPTIONS`; new helper component `Explained`.
- New in the component: `modal`, `pauseMinutes` state, the full `useHardening` destructure, `confirmEnable`, `confirmDisable`, `confirmPause`, `renderControl`; the row renders `{renderControl(key, file, rule)}` on its right side.

Behaviour to keep exactly:
- **The Switch follows the server.** `checked` is `rule.state === 'on'` and nothing else. `onChange` only opens a confirm; the position changes when the refetched status says so.
- **One confirm function for Turn on / Adopt / Re-apply** — they are the same API call (`setRule(rule, true)`); only the wording differs. `manual` and `drift` render a labelled button instead of a Switch.
- **Archive warning:** for `block_archives` with `archive_attachments > 0` the enable and re-apply confirms show a warning line with the count. Not on Adopt — the manual rule already blocks those files, so nothing changes for visitors.
- **The pause duration Select sits beside the button**, because `modal.confirm` renders its content once and would not follow a Select's state. The value is captured when the confirm opens.
- **While paused:** countdown on `pause_until * 1000` with `format="mm:ss"`, `onFinish` refetches once, "Re-enable now" calls `resume()` directly (tightening needs no confirm). No countdown while `pause_overdue` — the alert says it instead, and a countdown mounted on a past timestamp would fire `onFinish` immediately.
- **What happens at zero, every time:** the real API answers the `onFinish` refetch with `pause_overdue: true` (its open pause row is past `paused_until`, and the site only resumes on a later ordinary page load — never on a request to `hardening/*`). So every real pause goes countdown → zero → overdue alert; the Timer unmounts because of its own refetch, and from there the hook's 30-second poll (Task 3, `refetchInterval`) watches for the rule to come back. The card adds nothing for this — do not add a second timer or a refetch loop here.
- **A state this build does not know** (a newer plugin may add one) shows its raw name in a grey tag and gets no control at all: `renderControl` returns `null` for anything that is not `on` / `off` after the known special states. Never fall through to an enabled "turn on" Switch for a state the card does not understand.
- **The countdown component is `Statistic.Timer type="countdown"`.** In the pinned antd 5.29.3, `Statistic.Countdown` is a deprecated alias that renders exactly this and logs a deprecation warning on every mount.
- **Turning a rule off** needs `can.disable` and uses a danger confirm.
- **Disabled controls explain themselves.** A disabled button swallows mouse events, so a Tooltip attached to it never opens; `Explained` puts the tooltip on a wrapping span. Re-enable is gated on `can.pause` (the spec defines no separate ability for resume).
- **One write at a time:** while any mutation is pending every other control is disabled; only the control that started it shows `loading`.

- [ ] **Step 1: Replace `src/features/projects/components/HardeningCard.tsx` with the final version**

```tsx
/**
 * Server hardening (.htaccess) card
 *
 * Three rules the LSM plugin writes into wp-content/.htaccess and
 * uploads/.htaccess. Everything shown here is the server's view: a switch
 * follows the state the plugin read from the file, never the click, and what
 * the user may do comes from the API's `can`, never from their role.
 */

import { useState } from 'react';
import type { ReactNode } from 'react';
import {
  Alert,
  App,
  Button,
  Card,
  Select,
  Space,
  Spin,
  Statistic,
  Switch,
  Tag,
  Tooltip,
  Typography,
} from 'antd';
import { InfoCircleOutlined, LockOutlined, PauseCircleOutlined } from '@ant-design/icons';
import { useTranslation } from 'react-i18next';
import { useThemeStore } from '@/stores/theme';
import { useHardening } from '../hooks/useHardening';
import type {
  HardeningPauseMinutes,
  HardeningRuleKey,
  HardeningRuleState,
  HardeningRuleStatus,
} from '@/lib/lsm-api';

const { Text } = Typography;

interface HardeningCardProps {
  project: { id: number; has_health_check_secret?: boolean };
}

// The file each rule lives in, for the confirm texts. Paths are not translated.
const RULES: Array<{ key: HardeningRuleKey; file: string }> = [
  { key: 'block_archives', file: 'wp-content/.htaccess' },
  { key: 'block_debug_log', file: 'wp-content/.htaccess' },
  { key: 'block_uploads_php', file: 'uploads/.htaccess' },
];

const STATE_COLOR: Record<HardeningRuleState, string> = {
  on: 'success',
  off: 'default',
  paused: 'warning',
  manual: 'blue',
  drift: 'orange',
  unsupported: 'default',
};

const PAUSE_OPTIONS: HardeningPauseMinutes[] = [15, 30, 60];

/**
 * Explains a disabled control. A disabled button swallows mouse events, so a
 * Tooltip attached to it never opens — the outer span takes the hover instead.
 */
function Explained({ reason, children }: { reason?: string; children: ReactNode }) {
  if (!reason) return <>{children}</>;
  return (
    <Tooltip title={reason} color="#1e293b">
      <span style={{ display: 'inline-block', cursor: 'not-allowed' }}>
        <span style={{ display: 'inline-block', pointerEvents: 'none' }}>{children}</span>
      </span>
    </Tooltip>
  );
}

export function HardeningCard({ project }: HardeningCardProps) {
  const { t } = useTranslation();
  const { modal } = App.useApp();
  const { resolvedTheme } = useThemeStore();
  const isDark = resolvedTheme === 'dark';
  const hasLsmConnection = !!project.has_health_check_secret;

  // Lives beside the button, not inside the confirm: modal.confirm renders its
  // content once and would not follow a Select's state.
  const [pauseMinutes, setPauseMinutes] = useState<HardeningPauseMinutes>(60);

  const { query, can, setRule, pause, resume, pendingRule, isPausing, isResuming, isBusy } =
    useHardening(project.id, hasLsmConnection);
  const { data, isLoading, isError, refetch } = query;
  const status = data?.status ?? null;

  const ruleLabel = (rule: HardeningRuleKey) => t(`projects.hardening.rules.${rule}.label`);

  // Turn on, Adopt and Re-apply are the same call — only the wording differs.
  const confirmEnable = (rule: HardeningRuleKey, file: string, kind: 'enable' | 'adopt' | 'reapply') => {
    // Adopting changes nothing for visitors: the manual rule already blocks these files.
    const attachments =
      rule === 'block_archives' && kind !== 'adopt' ? status?.archive_attachments ?? 0 : 0;
    modal.confirm({
      title: t(`projects.hardening.confirm.${kind}.title`, { rule: ruleLabel(rule) }),
      content: (
        <div>
          {attachments > 0 && (
            <Alert
              type="warning"
              showIcon
              message={t('projects.hardening.confirm.archiveAttachments', { count: attachments })}
              style={{ marginBottom: 12 }}
            />
          )}
          <Text>{t(`projects.hardening.confirm.${kind}.content`, { file })}</Text>
        </div>
      ),
      okText: t(`projects.hardening.confirm.${kind}.okText`),
      cancelText: t('common.cancel'),
      onOk: () => setRule(rule, true),
    });
  };

  const confirmDisable = (rule: HardeningRuleKey, file: string) => {
    modal.confirm({
      title: t('projects.hardening.confirm.disable.title', { rule: ruleLabel(rule) }),
      content: (
        <div>
          <Text>{t('projects.hardening.confirm.disable.content', { file })}</Text>
          {rule === 'block_archives' && (
            <div style={{ marginTop: 8 }}>
              <Text type="secondary">{t('projects.hardening.confirm.disable.archivesNote')}</Text>
            </div>
          )}
        </div>
      ),
      okText: t('projects.hardening.confirm.disable.okText'),
      okButtonProps: { danger: true },
      cancelText: t('common.cancel'),
      onOk: () => setRule(rule, false),
    });
  };

  const confirmPause = () => {
    const minutes = pauseMinutes;
    modal.confirm({
      title: t('projects.hardening.confirm.pause.title', { minutes }),
      content: t('projects.hardening.confirm.pause.content', { minutes }),
      okText: t('projects.hardening.confirm.pause.okText'),
      cancelText: t('common.cancel'),
      onOk: () => pause(minutes),
    });
  };

  const renderStateTag = (rule: HardeningRuleStatus) => {
    // A state this build does not know (newer plugin) shows as its raw name in a
    // grey tag, not as an i18n key.
    const tag = (
      <Tag color={STATE_COLOR[rule.state]} style={{ margin: 0 }}>
        {t(`projects.hardening.states.${rule.state}`, { defaultValue: rule.state })}
      </Tag>
    );
    let hint: string | undefined;
    if (rule.state === 'unsupported' && rule.unsupported_reason) {
      hint = t(`projects.hardening.unsupportedReasons.${rule.unsupported_reason}`, {
        defaultValue: rule.unsupported_reason,
        server: status?.server ?? '?',
      });
    } else if (rule.state === 'manual' || rule.state === 'drift') {
      hint = t(`projects.hardening.stateHints.${rule.state}`);
    }
    return hint ? (
      <Tooltip title={hint} color="#1e293b">
        <span style={{ cursor: 'help' }}>{tag}</span>
      </Tooltip>
    ) : (
      tag
    );
  };

  const renderControl = (key: HardeningRuleKey, file: string, rule: HardeningRuleStatus) => {
    const loading = pendingRule === key;

    if (rule.state === 'paused') {
      return (
        <Space size={12}>
          {!data?.pause_overdue && status?.pause_until != null && (
            <Statistic.Timer
              type="countdown"
              value={status.pause_until * 1000}
              format="mm:ss"
              onFinish={() => refetch()}
              valueStyle={{ fontSize: 14 }}
            />
          )}
          <Explained reason={can.pause ? undefined : t('projects.hardening.noPermission.pause')}>
            <Button
              size="small"
              type="primary"
              loading={isResuming}
              disabled={!can.pause || (isBusy && !isResuming)}
              onClick={() => resume()}
            >
              {t('projects.hardening.actions.resume')}
            </Button>
          </Explained>
        </Space>
      );
    }

    if (rule.state === 'manual' || rule.state === 'drift') {
      const kind = rule.state === 'manual' ? 'adopt' : 'reapply';
      return (
        <Explained reason={can.enable ? undefined : t('projects.hardening.noPermission.enable')}>
          <Button
            size="small"
            loading={loading}
            disabled={!can.enable || (isBusy && !loading)}
            onClick={() => confirmEnable(key, file, kind)}
          >
            {t(`projects.hardening.actions.${kind}`)}
          </Button>
        </Explained>
      );
    }

    if (rule.state === 'unsupported') {
      return <Switch checked={false} disabled />;
    }

    // A state this build does not know (newer plugin): show the tag, offer nothing.
    if (rule.state !== 'on' && rule.state !== 'off') return null;

    const isOn = rule.state === 'on';
    const allowed = isOn ? can.disable : can.enable;
    const denied = t(isOn ? 'projects.hardening.noPermission.disable' : 'projects.hardening.noPermission.enable');
    return (
      <Space size={12}>
        {isOn && key === 'block_archives' && (
          <Space size={4}>
            <Select<HardeningPauseMinutes>
              size="small"
              value={pauseMinutes}
              onChange={setPauseMinutes}
              disabled={!can.pause || isBusy}
              options={PAUSE_OPTIONS.map(minutes => ({
                value: minutes,
                label: t('projects.hardening.pauseOption', { minutes }),
              }))}
              style={{ width: 90 }}
            />
            <Explained reason={can.pause ? undefined : t('projects.hardening.noPermission.pause')}>
              <Button
                size="small"
                icon={<PauseCircleOutlined />}
                loading={isPausing}
                disabled={!can.pause || (isBusy && !isPausing)}
                onClick={confirmPause}
              >
                {t('projects.hardening.actions.pause')}
              </Button>
            </Explained>
          </Space>
        )}
        <Explained reason={allowed ? undefined : denied}>
          <Switch
            checked={isOn}
            loading={loading}
            disabled={!allowed || (isBusy && !loading)}
            onChange={checked => (checked ? confirmEnable(key, file, 'enable') : confirmDisable(key, file))}
          />
        </Explained>
      </Space>
    );
  };

  const renderBody = () => {
    if (isLoading) {
      return (
        <div style={{ textAlign: 'center', padding: 24 }}>
          <Spin />
        </div>
      );
    }

    if (isError || !data) {
      return (
        <Space>
          <Text type="secondary">{t('projects.hardening.quiet.loadError')}</Text>
          <Button type="link" size="small" onClick={() => refetch()}>
            {t('projects.hardening.quiet.retry')}
          </Button>
        </Space>
      );
    }

    // The platform keeps its own record of an open pause, so an overdue one
    // shows even when the site itself cannot be asked.
    const overdueAlert = data.pause_overdue && (
      <Alert
        type="warning"
        showIcon
        message={t('projects.hardening.overdue.title')}
        description={t('projects.hardening.overdue.description')}
        style={{ marginBottom: 12 }}
      />
    );

    if (data.plugin_outdated || !data.reachable || !status) {
      return (
        <div>
          {overdueAlert}
          <Text type="secondary">
            {data.plugin_outdated
              ? t('projects.hardening.quiet.pluginOutdated', { version: data.min_version || '2.10.0' })
              : t('projects.hardening.quiet.unreachable')}
          </Text>
          {!data.pause_overdue && data.open_pause && (
            <div style={{ marginTop: 4 }}>
              <Text type="warning">{t('projects.hardening.quiet.openPause')}</Text>
            </div>
          )}
        </div>
      );
    }

    return (
      <div>
        {overdueAlert}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 0 }}>
          {RULES.map(({ key, file }, index) => {
            const rule = status.rules[key];
            if (!rule) return null;
            return (
              <div
                key={key}
                style={{
                  display: 'flex',
                  justifyContent: 'space-between',
                  alignItems: 'center',
                  flexWrap: 'wrap',
                  gap: 12,
                  padding: '16px 0',
                  borderBottom:
                    index < RULES.length - 1 ? `1px solid ${isDark ? '#334155' : '#e2e8f0'}` : undefined,
                }}
              >
                <div style={{ flex: 1, minWidth: 240 }}>
                  <Space>
                    <Text strong>{ruleLabel(key)}</Text>
                    <Tooltip title={t(`projects.hardening.rules.${key}.hint`)} color="#1e293b">
                      <InfoCircleOutlined style={{ color: '#94a3b8', cursor: 'help' }} />
                    </Tooltip>
                    {renderStateTag(rule)}
                  </Space>
                  <div>
                    <Text type="secondary" style={{ fontSize: 13 }}>
                      {t(`projects.hardening.rules.${key}.description`)}
                    </Text>
                  </div>
                  {rule.last_failure && (
                    <div style={{ marginTop: 4 }}>
                      <Text type="danger" style={{ fontSize: 12 }}>
                        {t('projects.hardening.lastFailure', {
                          reason: t(`projects.hardening.reasons.${rule.last_failure.reason}`, {
                            defaultValue: rule.last_failure.reason,
                            version: data.min_version || '2.10.0',
                          }),
                        })}
                      </Text>
                    </div>
                  )}
                </div>
                {renderControl(key, file, rule)}
              </div>
            );
          })}
        </div>
        {/* The one last_result reason that belongs to no rule row. */}
        {(status.last_result?.reason === 'crash_recovered' || status.last_result?.reason === 'rollback_failed') && (
          <div style={{ marginTop: 8 }}>
            <Text type="secondary" style={{ fontSize: 12 }}>
              {t(`projects.hardening.reasons.${status.last_result.reason}`)}
            </Text>
          </div>
        )}
      </div>
    );
  };

  return (
    <Card
      title={
        <Space>
          <LockOutlined style={{ color: '#3b82f6' }} />
          <span>{t('projects.hardening.title')}</span>
          <Tooltip title={t('projects.hardening.titleHint')} color="#1e293b">
            <InfoCircleOutlined style={{ color: '#94a3b8', cursor: 'help' }} />
          </Tooltip>
        </Space>
      }
      style={{
        marginTop: 16,
        marginBottom: 16,
        borderRadius: 12,
        background: isDark ? '#1e293b' : '#fff',
      }}
    >
      {renderBody()}
    </Card>
  );
}
```

- [ ] **Step 2: Run the gate**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web-hardening
npm run typecheck && npm run lint && npm run build
```

Expected: all three exit 0.

- [ ] **Step 3: Manual check — the write flows**

Start the API and the mock dev server if they are not running:

```bash
# only if nothing listens on :8000 yet
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan serve
# second terminal
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web-hardening
VITE_API_URL=/api/v1 npx vite --config vite.mock.config.ts
```

Never start the app with `npm run dev` for these checks, and never without `VITE_API_URL=/api/v1`: only the mock keeps `/lsm/` calls away from real client sites. If `vite.mock.config.ts` is missing from the worktree (it is untracked), stop and report — do not re-create it from memory. If :8000 or :3000 is already taken, it belongs to someone else: reuse the API, let Vite pick the next free port and use the URL it prints instead of `localhost:3000` below. Do not kill anything you did not start, and if the Chrome DevTools browser profile is busy, stop and report (see "Shared machine" in Global Constraints).

Steering: `http://localhost:<port>/__hardening?scenario=…&role=admin|manager|developer|unassigned|nocan&outcome=ok|warn|soft_fail|forbidden|<reason>&delay=<ms>&seconds=<n>` in a second tab. All values persist until changed; the answer lists the scenario names. Reload the project tab after each change of `scenario` or `role`; `outcome` and `delay` apply to the next POST without a reload. `seconds` is the length of the `paused` scenario (default 900).

Open `http://localhost:3000/projects/<id>?section=security` (by URL — under the mock the WordPress entries of the side nav are greyed out). **Click only inside the "Server hardening (.htaccess)" card and the confirm dialogs it opens.** The six Switches in the "Security Controls" card above it are real controls for a live client site, and the first `.ant-switch` on the page is one of them ("comments"). For automation, scope every selector to the card:

```js
const card = [...document.querySelectorAll('.ant-card')].find(c =>
  c.querySelector('.ant-card-head-title')?.textContent?.includes('.htaccess')
);
card.querySelectorAll('.ant-switch')[0].click(); // the Switch in the card's first row
```

1. `scenario=all_off&role=manager&outcome=ok&delay=1500` → click the Switch in the first row of the "Server hardening (.htaccess)" card (NOT the first Switch on the page). Confirm "Turn on “Block backup & archive downloads”?" names `wp-content/.htaccess`, backup, site test, automatic undo. **The Switch has not moved.** Click "Turn on": the OK button spins ~1.5 s, a green toast "… is on — applied and verified" appears, the dialog closes, the tag is "On", the Switch is on, and "Pause for download" with a "60 min" Select appears beside it.
2. Third row (uploads): the confirm names `uploads/.htaccess`.
3. `scenario=attachments` → the card's first Switch: the confirm shows a yellow line "3 downloadable archives in the media library will stop working." `scenario=one_attachment` → "1 downloadable archive … will stop working." The second and third rows never show that line.
4. `scenario=manual` → rows 1 and 3 show a button "Adopt" (no Switch). Click it: title "Adopt the manual rule for …?", OK button "Adopt", **no** archive warning although the scenario has 3 attachments. Confirm → tag "On".
5. `scenario=drift` → row 1 shows "Re-apply". Confirm title "Re-apply …?", OK "Re-apply" → tag "On".
6. `scenario=all_on` → change the Select to "15 min", click "Pause for download". Confirm title "Pause the archive block for 15 minutes?"; the text says backups are publicly downloadable for 15 minutes and that an interrupted download resumed afterwards will fail. Confirm → toast "Archive block paused for 15 minutes", tag "Paused", a countdown near `15:00` counting down, button "Re-enable now". Repeat with the default: the countdown starts near `60:00` (not `00:00`).
7. `scenario=paused&seconds=20` → reload, watch the countdown reach `00:00`. In DevTools → Network exactly **one** new `GET …/lsm/hardening` fires at zero — no burst, no loop. The countdown disappears and the yellow overdue alert appears (this is what the real API answers at that moment: the site resumes on its next ordinary page load, not on our status read). From then on one GET about every 30 s and nothing in between — that is the overdue poll. (Coming back from the steering tab also fires one refetch on window focus once the data is 30 s old; that is the app's global default, not a loop.) "Re-enable now" still works → alert gone, tag "On", and the polling stops.
8. `scenario=overdue` → yellow alert, no countdown, "Re-enable now" present; the Network tab shows a `GET …/lsm/hardening` about every 30 s while the tab is focused. Click "Re-enable now" → toast "Archive block is back on", alert gone, tag "On"; after this successful re-enable the polling stops. Cross-check with `scenario=paused` (900 s) and with `scenario=all_on`: watch for a minute — no periodic GET while a countdown is still running or when nothing is paused.
9. `scenario=all_on&role=admin` → switch the first rule off: danger confirm (red OK button "Turn off") with the grey hint about "Pause for download". Confirm → tag "Off". On row 2 the hint line is absent.
10. `scenario=all_on&role=manager` → the Switches are disabled; hovering one shows "Only admins can turn a rule off". "Pause for download" is enabled.
11. `scenario=all_off&role=developer` → Switches disabled, tooltip "Only admins and the managers of this project can turn rules on". `scenario=all_on&role=developer` → "Pause for download" enabled, Switches disabled.
12. `role=unassigned` and `role=nocan` with `scenario=all_on`, then `scenario=paused`, then `scenario=manual` → every control disabled, each with its tooltip (pause/re-enable: "You need edit rights…"). `nocan` omits `can` entirely and must behave exactly like `unassigned`.
13. Failures — `scenario=all_off&role=admin`, then before each click set `outcome=`:
    - `rule_ineffective` → red toast with the translated text (nginx in front…), dialog closes, **Switch still off**.
    - `busy` → "Another hardening change is running…".
    - `plugin_outdated` → "Requires plugin 2.10.0 or newer…".
    - `unreachable` → "No answer from the site — the outcome is unknown…".
    - `soft_fail` (HTTP 200 with `success:false`) → red toast "The site broke with the new rule…", **no** green toast.
    - `something_new` → the toast shows the API's message "Plugin message for something_new" (unknown code falls back to `message`).
    - `forbidden` → "This action is unauthorized."
    - `warn` → green toast plus two yellow toasts (already blocked elsewhere; could not confirm).
14. While a POST is running (`delay=5000`): the control that started it spins, every other control in the card is disabled.
15. `scenario=unknown_state&role=admin` → row 2 shows the grey `quarantined` tag and **no control at all** (no Switch, no button); rows 1 and 3 have their Switches and work as usual.
16. DevTools console: no React warning, no antd deprecation warning, no "Uncaught (in promise)".

If a tooltip over a disabled control does not open (step 10-12), the fix belongs in `Explained` — do not move the tooltip onto the disabled element.

- [ ] **Step 4: Commit**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web-hardening
git add src/features/projects/components/HardeningCard.tsx
git commit -m "feat(hardening): toggles, pause/resume and confirm flows

Switches follow the server state and only open a confirm. Adopt and
Re-apply for manual/drift, pause with a duration select beside the
button, countdown with one refetch at zero, admin-only danger confirm
for turning a rule off, tooltips on every disabled control.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
git status --short
```

Expected after the commit: exactly one line, `?? vite.mock.config.ts`.

---

### Task 7: Manual verification matrix, then remove the mock

**This is the last task of the plan.** Pushing, merging and deploying are not part of it — they are described in the appendix for a human and must not be run by an implementer.

Nothing is written in this task. It is the spec's "manual browser verification of every state against a fake/real API", run once over the finished card: every state × role, in both languages and both themes. Language and theme switches are in the app header.

It uses the mock `vite.mock.config.ts` that Task 5 left untracked in the worktree root. **If `vite.mock.config.ts` is missing from the worktree (it is untracked), stop and report. Do not re-create it from memory.** Start the API and the mock dev server if they are not running:

```bash
# only if nothing listens on :8000 yet
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan serve
# second terminal
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web-hardening
VITE_API_URL=/api/v1 npx vite --config vite.mock.config.ts
```

Never start the app with `npm run dev` for these checks, and never without `VITE_API_URL=/api/v1`: only the mock keeps `/lsm/` calls away from real client sites. If :8000 or :3000 is already taken, it belongs to someone else: reuse the API, let Vite pick the next free port and use the URL it prints. Do not kill anything you did not start, and if the Chrome DevTools browser profile is busy, stop and report (see "Shared machine" in Global Constraints — `.claude/skills/verify/SKILL.md` suggests `lsof … | xargs kill` and `pkill -f chrome-devtools-mcp/chrome-profile`; run neither).

Steering: `http://localhost:<port>/__hardening?scenario=…&role=admin|manager|developer|unassigned|nocan&outcome=ok|warn|soft_fail|forbidden|<reason>&delay=<ms>&seconds=<n>` in a second tab. All values persist until changed; the answer lists the scenario names. Reload the project tab after each change of `scenario` or `role`. The `paused` scenario lasts `seconds` (default 900) from the moment it is set and reads as overdue afterwards, like the real API — set it again when you come back to it for the next role.

Open the project by URL, `http://localhost:<port>/projects/<id>?section=security` (under the mock the WordPress entries of the side nav are greyed out). **On the project page, click only inside the "Server hardening (.htaccess)" card and the confirm dialogs it opens** (the language and theme switches in the app header are fine) — the six Switches in the "Security Controls" card above it are real controls for a live client site. For automation, scope every selector to the card:

```js
const card = [...document.querySelectorAll('.ant-card')].find(c =>
  c.querySelector('.ant-card-head-title')?.textContent?.includes('.htaccess')
);
```

**Files:**
- Delete: `vite.mock.config.ts` (untracked)

**Interfaces:** Consumes: the finished card (Task 6) and the mock (Task 5). Produces: a verified branch with a clean working tree.

`role=` presets of the mock and what they stand for: `admin` (all of `can` true), `manager` (pause + enable), `developer` (pause only), `unassigned` (all false — an unassigned manager sees status, can do nothing), `nocan` (`can` missing — web deployed before the API).

- [ ] **Step 1: State × role — tick each cell after looking at it**

Expected controls per state (E = enabled, D = disabled with tooltip, — = not rendered):

| State (`scenario=`) | admin | manager | developer | unassigned | nocan |
|---|---|---|---|---|---|
| off (`all_off`) | Switch E | Switch E | Switch D | Switch D | Switch D |
| on, rows 2-3 (`all_on`) | Switch E | Switch D | Switch D | Switch D | Switch D |
| on, row 1 (`all_on`) | Switch E, Pause E | Switch D, Pause E | Switch D, Pause E | Switch D, Pause D | Switch D, Pause D |
| paused (`paused`) | Re-enable E, countdown | Re-enable E, countdown | Re-enable E, countdown | Re-enable D, countdown | Re-enable D, countdown |
| paused + overdue (`overdue`) | alert, Re-enable E, no countdown | same | same | alert, Re-enable D | alert, Re-enable D |
| manual (`manual`) | Adopt E | Adopt E | Adopt D | Adopt D | Adopt D |
| drift (`drift`) | Re-apply E | Re-apply E | Re-apply D | Re-apply D | Re-apply D |
| unknown state, row 2 (`unknown_state`) | grey tag `quarantined`, no control | = admin | = admin | = admin | = admin |
| unsupported (`unsupported`) | Switch disabled, reason on the tag | = admin | = admin | = admin | = admin |
| plugin outdated (`outdated`) | quiet line, no controls | = admin | = admin | = admin | = admin |
| unreachable (`unreachable`, `unreachable_pause`, `unreachable_overdue`) | quiet line (+ open-pause line / overdue alert), no controls | = admin | = admin | = admin | = admin |
| load error (`http500`) | quiet line + Retry | = admin | = admin | = admin | = admin |

- [ ] (a) all `admin` cells
- [ ] (b) all `manager` cells
- [ ] (c) all `developer` cells
- [ ] (d) all `unassigned` cells
- [ ] (e) all `nocan` cells — must equal `unassigned`

- [ ] **Step 2: German**

Switch the app to Deutsch. Go through `all_off`, `all_on`, `paused`, `overdue`, `manual`, `drift`, `unsupported`, `last_failure`, `outdated`, `unreachable_pause` and open each confirm once (Einschalten, Übernehmen, Erneut anwenden, Pausieren, Ausschalten as admin). Check: no English string inside the card or its dialogs (the rest of the Security tab is English — that is the existing state, not a bug of this branch), no raw key such as `projects.hardening.…` anywhere, „…“ quotes around the rule name, informal address, the plural ("1 herunterladbares Archiv … funktioniert" / "3 herunterladbare Archive … funktionieren"), the Select shows "60 Min.". Trigger `outcome=rule_ineffective` and `outcome=warn` once: toasts are German.

- [ ] **Step 3: Dark theme**

Switch to dark. Go through `all_on` (as admin), `overdue`, `last_failure`, `unsupported`, `unreachable_pause`. Check: the card background matches the "Security Controls" card above it, row separators are visible, the red failure line and the yellow open-pause line are readable, tooltips are dark with light text, the countdown digits are readable, the confirm dialogs follow the theme. Repeat `all_on` and `last_failure` in light.

- [ ] **Step 4: Narrow window**

Shrink the window to ~420 px wide with `scenario=all_on&role=admin`: the controls of row 1 wrap below the text instead of overflowing; nothing is cut off.

- [ ] **Step 5: Neighbours unchanged**

With any scenario: the score card, "Security Checks", "Security Controls" and "HTTP Security Headers" are laid out as before — nothing above or below the new card moved except by the card's own height and its 16 px margins. `git diff --numstat master -- src/features/projects/components/sections/SecuritySection.tsx` still prints `3	0`.

- [ ] **Step 6: Stop the mock dev server and delete the mock**

Stop the Vite process you started in the second terminal with Ctrl+C (only the one you started — leave any other server, and an API on :8000 that was already running, alone), then:

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web-hardening
rm vite.mock.config.ts
git status --short
```

Expected: `git status --short` prints nothing.

- [ ] **Step 7: Final gate and branch check**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web-hardening
npm run typecheck && npm run lint && npm run build
git log --oneline master..HEAD
git diff --stat master..HEAD
git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web status --short
```

Expected: gate exits 0; five commits (Tasks 2, 3, 4, 5 and 6); the diff touches exactly `src/lib/lsm-api.ts`, `src/lib/queryKeys.ts`, `src/lib/i18n.ts`, `src/features/projects/hooks/useHardening.ts`, `src/features/projects/components/HardeningCard.tsx`, `src/features/projects/components/sections/SecuritySection.tsx` — and no `vite.mock.config.ts`; the main checkout still shows only its own three modified files. Nothing to commit in this task.

The plan is finished here. Report the result and stop: do not push, do not merge, do not deploy, do not remove the worktree.

---

## Appendix — deploy notes (human only. NOT a task: never dispatch this section to an implementer)

**This section is not part of the plan's task list.** The plan ends at Task 7. Nothing below is run by an agent or a subagent: it pushes to `master`, uploads into the production web root and touches a live client site for the first time. It is written down so the person who deploys does not have to rediscover it. The bullets are deliberately not checkboxes.

The SPA is built locally and the bundle is uploaded to the web host; there is no CI. The general procedure is in `/Users/bmarkovic/Documents/Projects/LSMPlatform/.agent/workflows/deploy.md` (read it there — it contains credentials and is deliberately not quoted here). The notes below add what earlier web deploys learned the hard way.

- **Rollout order (spec Part 4): plugin 2.10.0 first, then lsm-api, then lsm-web.** Do not ship the web before the API has the `hardening` routes: the card would show "Hardening status could not be loaded" on every project. Shipping it before every site has plugin 2.10.0 is fine — those sites show "Requires plugin 2.10.0 or newer".
- Push the branch and merge it the way earlier features were merged (`--no-ff` into `master`, then `git push origin master`). The main checkout is on another branch with uncommitted work: merge only once its owner has committed, or merge from a clean clone — never stash or reset someone else's tree.
- `.env.production` is tracked (only `.env`, `.env.local`, `.env.*.local` are ignored), so every checkout has it. Build from a clean checkout of the merged `master` (the worktree after the merge, or a fresh clone) — never from the main checkout while it sits on `fix/concurrent-plugin-update-guard` with uncommitted work. If the local packages under `packages/` changed since the last deploy, rebuild them first (`npm run build --prefix packages/types`, then `api-client`, then `utils`) — their `dist/` is gitignored and `vite build` does not build them; a fresh clone needs them built in any case. This feature does not touch them.
- `npm run typecheck && npm run lint && npm run build`, then confirm the bundle points at production: `grep -l "api.wartung-ls.com" dist/assets/*.js` prints a file, and `grep -l "lsm/hardening\|/hardening/rule" dist/assets/*.js` prints a file. Note the new bundle name from `dist/index.html` (`index-<hash>.js`).
- **Back up the live `index.html` first:** on `wartung-ls`, copy `~/websites/0Am1u87de/public_html/index.html` to `index.html.bak-<date>` in the same folder.
- Upload as a tarball, in separate steps (`scp -r` breaks on this host, and the home directory is read-only — go through `/tmp`): `tar -czf /tmp/lsm-web-dist.tgz -C dist .` → `ssh wartung-ls 'cat > /tmp/lsm-web-dist.tgz' < /tmp/lsm-web-dist.tgz` → `ssh wartung-ls 'tar -xzf /tmp/lsm-web-dist.tgz -C ~/websites/0Am1u87de/public_html'`. Run each as its own command.
- Verify live: the served `index.html` references the new `index-<hash>.js`, that file answers 200, and it contains the string `hardening/rule`.
- Smoke test in production, logged in: a project whose plugin is older than 2.10.0 → quiet "Requires plugin 2.10.0 or newer" line, no error toast; landeseiten.de (first real-site target of the spec) → three rows. As a non-admin: Switches of rules that are on are disabled with a tooltip. Do the first real enable / pause / re-enable there, following the spec's real-site verification list.
- Rollback if needed: restore `index.html.bak-<date>` over `index.html`. The previous bundle files are still in `assets/` (deploys never delete old assets), so the old `index.html` works immediately.
- After the merge: `git -C /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web worktree remove /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web-hardening` and delete the branch.

---

## Contract assumptions

Where the spec is silent on something the web shares with the API, this plan takes the most literal reading:

1. **`GET /hardening` has no `data` wrapper.** The spec lists the keys at the top level, so the hook reads `r.data` directly (not the `r.data?.data || r.data` unwrap other LSM calls use).
2. **The shape of `open_pause` is not specified.** The web types it as `Record<string, unknown> | null` and uses only null vs. non-null (the "a download pause is still open" line when the site is unreachable). No field of it is read.
3. **No ability is named for `POST /hardening/resume`.** "Re-enable now" is gated on `can.pause` — whoever may pause may end the pause.
4. **The top-level `pause_overdue` is authoritative.** The spec defines it as "open pause row past `paused_until`, or the plugin's own `pause_overdue`", so the card never reads `status.pause_overdue` separately.
5. **`last_result` may be `null`** (a site that never ran an operation) and **`last_result.rule` may be `null`** (crash recovery, deactivation). Typed that way; only `last_result.reason === 'crash_recovered'` or `'rollback_failed'` (both from crash recovery) is displayed.
6. **Request bodies:** `{ rule: string, enabled: boolean }` and `{ minutes: number }` as JSON with real booleans/numbers; resume sends `{}`.
7. **Failure bodies carry `status`**, but the web does not consume it — it always re-reads `GET /hardening` on settle, which is what the spec asks for.
8. **Stricter than the spec on 2xx:** the spec says to treat a 2xx with `success === false` as failure; the hook treats any 2xx body that is not `success === true` as failure, so an HTML page from a proxy can never produce an "applied" toast.
9. **Errors without a `reason`** (Laravel 403 from the Gate, 429 from `throttle:12,1`, a validation 422) show the API's `message` as is — in English. The UI prevents the 403 through `can`; no web-only reason codes were invented for them.
10. **Web-internal, not shared:** `Statistic.Timer type="countdown"` instead of the spec's `Statistic.Countdown` (same component in antd 5.29.3, without the deprecation warning), and the mount in `SecuritySection.tsx` is folded into Task 5 so the card is checkable in the browser from its first task.
11. **At zero the API answers "overdue", not "on".** By the spec, `pause_overdue` is "an open pause row with `paused_until < now()`", and the plugin's auto-resume never runs on a request to `lsm/v1/hardening/*`. So the refetch at zero always comes back paused + overdue, and the rule returns later (the site's next ordinary page load, or the 10-minute `hardening:resume-expired` backstop). The spec only asks for the refetch at zero; this plan adds a 30-second poll that runs only from that moment until the pause is gone, so the overdue alert does not outlive the problem. Cost: one status read per 30 s against the customer's site, only while the window is focused, only for an expired pause. GETs are not behind `throttle:12,1` (the spec puts only the POSTs there).
12. **`state` may carry a value this build does not know.** The spec fixes six states; the type stays the closed union, but at runtime an unknown value renders its raw name in a grey tag and no control.

## Self-Review

Run while writing the plan, against the spec text (Part 3 and the web bullet of Part 4).

| Spec requirement | Task |
|---|---|
| New `src/features/projects/components/HardeningCard.tsx` | 5 (read-only), 6 (controls) |
| New `src/features/projects/hooks/useHardening.ts` | 3 |
| Client functions in `src/lib/lsm-api.ts` | 2 |
| Key `queryKeys.projects.securityHardening(id)` | 2 |
| One import and one line in `SecuritySection.tsx` | 5, Step 2 |
| i18n under `projects.hardening.*`, both languages, DE informal | 4 (parity script: 101 keys each, all spec codes present) |
| Card "Server hardening (.htaccess)", three rows | 5 (`title`, `RULES`) |
| State tag per rule: On / Off / Paused (countdown) / Manual / Drift / Not supported (reason tooltip) | 5 (`renderStateTag`, `STATE_COLOR`), countdown in 6 |
| Sticky line under a rule when `last_failure` is set | 5 (`lastFailure`) |
| Quiet state `plugin_outdated` → "Requires plugin 2.10.0 or newer — update the plugin" | 5 (`quiet.pluginOutdated`, version from `min_version`) |
| Quiet state `reachable:false` → "Site not reachable" | 5 (`quiet.unreachable`) |
| Switch `checked` driven by server state only; `onChange` opens the confirm via `App.useApp().modal` | 6 (`renderControl`, `confirmEnable` / `confirmDisable`) |
| Enable text: writes to `wp-content/.htaccess` (or uploads), backup, site tested, auto-undo | 4 (`confirm.enable.content`), 6 (`RULES[].file`) |
| `block_archives` with `archive_attachments > 0` → extra warning line | 4 (`confirm.archiveAttachments_one/_other`), 6 (`confirmEnable`) |
| `manual` → "Adopt"; `drift` → "Re-apply" | 4 (`actions.*`, `confirm.adopt/reapply`), 6 |
| "Pause for download": Select 15/30/60, 60 preselected, beside the button | 6 (`PAUSE_OPTIONS`, `pauseMinutes` state) |
| Pause confirm: publicly downloadable for N minutes; interrupted + resumed download fails | 4 (`confirm.pause.content`), 6 (`confirmPause`) |
| While paused: countdown on `pause_until * 1000`, refetch at zero, "Re-enable now" | 6 (`Statistic.Timer`, `onFinish={() => refetch()}`, `resume()`); beyond the spec: 3 (`refetchInterval`, 30 s once the pause has run out); the zero → overdue transition is exercised in 6 Step 3 check 7 |
| `<Alert type="warning">` when `pause_overdue` | 5 (`overdueAlert`) |
| Turning a rule off: only with `can.disable`, danger confirm | 6 (`confirmDisable`, `okButtonProps: { danger: true }`) |
| Abilities only from `can` (missing → all false) | 3 (`NO_ABILITIES`), verified with `role=nocan` in 6/7 |
| Disabled buttons get a tooltip | 6 (`Explained`), 4 (`noPermission.*`) |
| The three POSTs pass `{ timeout: 130000 }` | 2 |
| Errors in `onError`; `reason` → `projects.hardening.reasons.<reason>`, `message` as fallback | 3 (`describeError`, `failureBody` — a non-JSON body reads as `unreachable` on both failure paths) |
| 2xx with `success === false` treated as failure | 3 (`unwrap`, `HardeningFailure`) |
| Always invalidate the hardening query on settle | 3 (`onSettled: refresh` ×3) |
| Query `enabled: hasLsmConnection`, `staleTime: 30000` | 3 (query), 5 (`hasLsmConnection` from `project.has_health_check_secret`) |
| Existing security score left untouched | 5 (Step 2 changes two places only; checked in 5 Step 6 and 7 Step 5) |
| Part 4 web: `npm run typecheck && npm run lint && npm run build` | every task |
| Part 4 web: manual browser verification of every state against a fake/real API | 5 Step 6, 6 Step 3, 7 (matrix, DE, dark) |
| Rollout order: plugin, API, web; `plugin_outdated` handles laggards | Appendix (human) |

No gaps found. One deviation, listed above: `Statistic.Timer type="countdown"` instead of `Statistic.Countdown`. Two additions beyond the spec, both listed under Contract assumptions: the 30-second poll for an expired pause (11) and the handling of an unknown `state` (12).

**Plan-level rules that are not spec requirements, and where each is enforced**

| Rule | Where |
|---|---|
| No browser check may reach a real client site | Global Constraints; mock in 5 Step 4 (every un-faked `/lsm/` call → 503, other methods on `hardening*` → 405, `lsm/status` → `connected: false`); `curl` proof in 5 Step 5; start block in 6 Step 3 and the Task 7 intro (mock config only, never `npm run dev`) |
| Click only inside the "Server hardening (.htaccess)" card | Global Constraints; 5 Step 6; 6 Step 3 (card-scoped selector, check 1 names the card's first row); Task 7 intro |
| Never kill a process you did not start (ports 3000 / 8000, Chrome DevTools profile) | Global Constraints ("Shared machine"); 5 Step 5; 6 Step 3; Task 7 intro and Step 6 |
| The plan ends at Task 7 — no push, merge, upload, worktree removal | header note; Global Constraints; Task 7 intro and Step 7; Appendix heading and first paragraph (plain bullets, no checkboxes) |
| Each browser task is self-contained (start commands, steering URL, missing-mock rule) | 5 Step 5–6; 6 Step 3; Task 7 intro |
| Nothing is written outside the worktree | 4 Step 4 (parity script runs from stdin); 7 Step 6 (nothing to clean up outside the worktree) |
| Unknown `reason` / warning / `unsupported_reason` / `state` must not break the UI | Global Constraints; 3 (`describeError`, `showWarnings`); 5 and 6 (`renderStateTag` with `defaultValue`); 6 (`renderControl` returns `null`); mock scenarios `unknown_server`, `last_failure`, `unknown_state`, outcome `something_new` |

**Shared names and where they are defined**

| Name | Kind | Defined in |
|---|---|---|
| `GET /projects/{id}/lsm/hardening`, `POST …/hardening/rule`, `POST …/hardening/pause`, `POST …/hardening/resume` | routes (spec Part 2) | consumed in Task 2 |
| `rule`, `enabled`, `minutes` | request JSON keys (spec) | Task 2 |
| `reachable`, `plugin_outdated`, `min_version`, `status`, `open_pause`, `pause_overdue`, `can`, `pause`, `enable`, `disable`, `success`, `reason`, `message`, `warnings`, `plugin_version`, `server`, `rules`, `state`, `desired`, `unsupported_reason`, `last_failure`, `at`, `pause_until`, `archive_attachments`, `last_result`, `action`, `rule`, `ok` | response JSON keys (spec) | typed in Task 2 |
| `block_archives`, `block_debug_log`, `block_uploads_php` | rule keys (spec) | `HardeningRuleKey`, Task 2 |
| `on`, `off`, `paused`, `manual`, `drift`, `unsupported` | state names (spec) | `HardeningRuleState`, Task 2; labels Task 4 |
| `multisite`, `openlitespeed`, `unknown_server`, `not_writable` | unsupported reasons (spec) | `HardeningUnsupportedReason`, Task 2; texts Task 4 |
| `busy`, `invalid_rule`, `invalid_minutes`, `not_enabled`, `unsupported`, `loopback_blocked`, `markers_corrupt`, `snapshot_failed`, `write_failed`, `asset_broken`, `rule_ineffective`, `pause_ineffective_foreign_rule`, `rollback_failed`, `crash_recovered`, `plugin_outdated`, `unauthorized`, `unreachable` | reason codes (spec) | texts Task 4 (`reasons.*`); mapped in Task 3 |
| `already_blocked_elsewhere`, `unverified` | warnings (spec) | texts Task 4 (`warnings.*`); shown in Task 3 |
| `HardeningRuleKey`, `HardeningRuleState`, `HardeningUnsupportedReason`, `HardeningPauseMinutes`, `HardeningRuleStatus`, `HardeningLastResult`, `HardeningStatus`, `HardeningAbilities`, `HardeningOverview`, `HardeningActionResponse`, `HardeningErrorBody` | TypeScript types (web-only) | Task 2 |
| `api.lsm.getHardening`, `api.lsm.setHardeningRule`, `api.lsm.pauseHardening`, `api.lsm.resumeHardening` | client functions (web-only) | Task 2 |
| `queryKeys.projects.securityHardening` | query key (spec) | Task 2 |
| `useHardening` and its members `query`, `can`, `setRule`, `pause`, `resume`, `pendingRule`, `isPausing`, `isResuming`, `isBusy` | hook (spec names the file; members web-only) | Task 3 |
| `projects.hardening.*` | i18n namespace (spec) | Task 4 |
| `HardeningCard` | component (spec) | Task 5, finished in Task 6 |
| `vite.mock.config.ts`, `/__hardening` with `scenario`, `role`, `outcome`, `delay`, `seconds` | throwaway mock (never committed) | created Task 5, used in Tasks 5–7, deleted Task 7 |
| Mock scenarios `all_off`, `attachments`, `one_attachment`, `all_on`, `paused`, `overdue`, `manual`, `drift`, `unknown_state`, `unsupported`, `unknown_server`, `last_failure`, `outdated`, `unreachable`, `unreachable_pause`, `unreachable_overdue`, `http500`; roles `admin`, `manager`, `developer`, `unassigned`, `nocan` | mock steering values | Task 5 Step 4 |
