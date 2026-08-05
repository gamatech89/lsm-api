# MCP Integration Tokens — Design

**Date:** 2026-08-04
**Repos touched:** `lsm-api` (primary), `lsm-web` (UI section)
**Status:** Approved, ready for implementation planning

---

## Problem

The MCP server (`App\Mcp\Servers\LsmServer`, 44 tools) is production-ready and live at
`https://api.wartung-ls.com/mcp`. The **token layer underneath it is not.** Connecting an
MCP client today means logging in through `POST /api/v1/login` and pasting the resulting
session token into the client. That has three defects:

1. **Tokens die after 8 hours.** `config/sanctum.php` sets
   `'expiration' => env('SANCTUM_EXPIRATION', 480)`. An MCP client stores a static
   `Authorization` header and cannot call `/api/v1/refresh-token`, so the integration
   silently breaks every working day.
2. **No scopes.** Every `createToken()` call in the codebase
   (`AuthController@login`, `@refresh`, `TwoFactorController`) passes no abilities, so
   Sanctum grants `*`. It is currently impossible to issue a read-only token. A token
   meant for reading todos also unlocks `wp-emergency` and `bulk-wp-action` across the
   entire client portfolio.
3. **No management surface.** There is no token controller, no route, and no UI. Tokens
   cannot be listed, audited, or revoked individually — the only lever is logging out.

## Goal

A first-class **integration token**: separately scoped, independently expiring, created and
revoked from the UI, and distinct from login session tokens.

## Non-goals

- OAuth / dynamic client registration. Static bearer tokens are sufficient.
- Per-tool abilities (44 checkboxes). Rejected as unmaintainable.
- Changing how the web app authenticates. Session token behaviour must stay **byte-for-byte
  identical** to today.

---

## 1. Scopes

Four abilities, grouped by blast radius rather than by feature area:

| Scope | Tools | Meaning |
|---|---|---|
| `mcp:read` | 13 | Read platform state |
| `mcp:write` | 14 | Mutate platform data (todos, time, projects, assignments) |
| `mcp:wp` | 12 | Act on managed WordPress sites — reversible |
| `mcp:wp-destructive` | 5 | Irreversible, fleet-wide, or session-minting |

A task-tracking token — the immediate use case — is `mcp:read` + `mcp:write` and touches
no client site.

### Tool → scope map (all 44)

**`mcp:read` (13):** `GetDashboardTool`, `GetProjectTool`, `ListProjectsTool`,
`ListTodosTool`, `ListTodoTemplatesTool`, `ListTimeEntriesTool`, `ListTeamTool`,
`GetTeamWorkloadTool`, `GetTeamAvailabilityTool`, `ListInvoicesTool`,
`ListSupportTicketsTool`, `ListTagsTool`, `ListResourcesTool`

**`mcp:write` (14):** `CreateTodoTool`, `UpdateTodoTool`, `CompleteTodoTool`,
`DeleteTodoTool`, `ApplyTodoTemplateTool`, `CreateTimeEntryTool`, `StartTimerTool`,
`StopTimerTool`, `CreateProjectTool`, `UpdateProjectTool`, `BulkAssignDevelopersTool`,
`BulkAssignManagersTool`, `CreateSupportTicketTool`, `GeneratePdfTool`

**`mcp:wp` (12):** `WpCheckConnectionsTool`, `WpClearCacheTool`,
`WpEnableMaintenanceTool`, `WpDisableMaintenanceTool`, `WpGetUpdatesTool`,
`WpUpdatePluginsTool`, `WpUpdateCoreTool`, `WpOptimizeDatabaseTool`, `WpCreateBackupTool`,
`WpListBackupsTool`, `WpGetPhpErrorsTool`, `WpClearPhpErrorsTool`

**`mcp:wp-destructive` (5):** `WpEmergencyTool`, `BulkWpActionTool`, `WpRestoreBackupTool`,
`WpDownloadBackupTool`, `WpLoginTool`

`WpLoginTool` was moved here from `mcp:wp` on 2026-08-04, during implementation. It returns
a URL that logs the holder into wp-admin as an administrator; leaving it in `mcp:wp` meant
an `mcp:wp` token could reach every capability this bucket exists to fence off, in one
call. `WpDownloadBackupTool` mutates nothing but discloses a full site backup — database,
password hashes, PII — and is here on confidentiality grounds.

**Resources and prompts.** All 6 resources (`lsm://dashboard`, `lsm://todos/mine`,
`lsm://projects`, `lsm://sites/at-risk`, `lsm://time/today`, `lsm://vault`) and both prompts
(Morning Briefing, Weekly Status) require `mcp:read`.

### Scopes do not widen a role

Abilities **intersect** with the user's role policy; they never override it. A developer's
token carrying `mcp:write` still only reaches projects that developer is assigned to,
because every tool already resolves `Auth::user()` and applies role scoping (see
`ListTodosTool::handle()`). Scopes narrow, roles bound.

Consequently the create endpoint **rejects scopes the creator's role could never exercise**:
a developer cannot mint a `mcp:wp-destructive` token, since `WpEmergencyTool` enforces
Admin/Manager at the tool level and such a token would be a lie.

---

## 2. Enforcement

`Laravel\Mcp\Server\Primitive::eligibleForRegistration()` calls `shouldRegister()` through
the container when the method exists. That is the interception point.

A single trait, applied to every tool, resource, and prompt:

```php
trait HasRequiredScope
{
    abstract protected function requiredScope(): string;

    public function shouldRegister(): bool
    {
        return Auth::user()?->tokenCan($this->requiredScope()) ?? false;
    }
}
```

**Corrected during implementation:** this was originally specified as a
`protected string $requiredScope = 'mcp:read'` property. A class that redeclares a trait
property with a different default is a hard PHP fatal at class composition (verified on
PHP 8.3.22), so the first non-read primitive would have prevented the app from booting.
The abstract method also makes "every primitive states its own scope" a compile-time
guarantee instead of a convention.

Each primitive then declares one line:

```php
class WpRestoreBackupTool extends Tool
{
    use HasRequiredScope;

    protected function requiredScope(): string
    {
        return 'mcp:wp-destructive';
    }
}
```

**Out-of-scope tools are hidden from `tools/list`, not merely rejected on call.** An AI
client that cannot see a tool will not attempt it, reason about it, or explain its absence
to the user. Returning an error instead would leave every tool visible and invite retries.

**Corrected during implementation — this had the enforcement model backwards.** The
original text read: "the listing check is for ergonomics; the call check is the security
boundary." The opposite is true. `ServerContext::resolvePrimitives()` filters every
primitive collection through `eligibleForRegistration()`, and `CallTool`,
`ResolvesResources::resolveResource()` and `ResolvesPrompts::resolvePrompt()` all resolve
from those filtered collections. So `shouldRegister()` is the boundary on listing *and*
invocation: an out-of-scope tool answers `Tool [x] not found`, it is not merely hidden.

`assertScope()` at the top of `handle()` remains as a backstop. It is unreachable through
JSON-RPC in this vendor version and fires only for code that instantiates a primitive
directly — but a vendor change to primitive resolution would make it load-bearing again.
Neither mechanism may be removed without re-reading `ServerContext::resolvePrimitives()`.

### Backwards compatibility

Session tokens hold `*`. `tokenCan('mcp:read')` returns `true` for `*`, so the web app and
any existing token keep full access with no change.

---

## 3. Data model

Migration on `personal_access_tokens` (Sanctum already provides `abilities`, `expires_at`,
`last_used_at`):

| Column | Type | Purpose |
|---|---|---|
| `type` | `string`, default `session` | `session` \| `integration` |
| `created_from_ip` | `string`, nullable | Audit: where it was minted |
| `last_used_ip` | `string`, nullable | Audit: where it is being used |

`last_used_ip` is written by a listener on Sanctum's `Laravel\Sanctum\Events\TokenAuthenticated`
event, which fires once per authenticated request and carries the token model. The listener
updates the column only when the IP differs from the stored value, to avoid a write on every
request.

### The expiration fix — the subtle part

`vendor/laravel/sanctum/src/Guard.php:148`:

```php
$isValid =
    (! $this->expiration || $accessToken->created_at->gt(now()->subMinutes($this->expiration)))
    && (! $accessToken->expires_at || ! $accessToken->expires_at->isPast())
    && $this->hasValidProvider($accessToken->tokenable);
```

The two conditions are **AND**-ed. The global `expiration` therefore caps *every* token; a
per-token `expires_at` can only shorten a token's life, never extend it. Setting a
one-year `expires_at` while `expiration => 480` remains would produce a token that still
dies in 8 hours.

Required change, in one commit:

1. `config/sanctum.php` → `'expiration' => null`
2. Every existing `createToken()` call site passes an explicit expiry so session behaviour
   is preserved exactly:
   - `AuthController@login` → `now()->addMinutes(480)`
   - `AuthController@refresh` → `now()->addMinutes(480)`
   - `TwoFactorController` (line ~158) → `now()->addMinutes(480)`
   The 480 comes from a new `config('sanctum.session_expiration', 480)` so the value stays
   configurable via `SANCTUM_EXPIRATION`.
3. A backfill migration sets `expires_at = created_at + 480 minutes` on all existing rows
   where `expires_at IS NULL`. **Without this, flipping `expiration` to `null` makes every
   token ever issued immortal** — including the admin token currently sitting in
   `~/.claude.json`.

This step carries the entire regression risk of the feature and is covered by tests below.

---

## 4. API

New `App\Http\Controllers\Api\V1\IntegrationTokenController`, all routes under
`auth:sanctum` and the existing 2FA-enrolment middleware group.

| Method | Route | Behaviour |
|---|---|---|
| `GET` | `/api/v1/integration-tokens` | List caller's own integration tokens: name, scopes, `expires_at`, `last_used_at`, `last_used_ip`. **Never returns token values.** |
| `POST` | `/api/v1/integration-tokens` | Create. Returns the plaintext token **exactly once**. |
| `DELETE` | `/api/v1/integration-tokens/{id}` | Revoke. |

**Create request:**

```json
{
  "name": "Claude Code — MacBook",
  "scopes": ["mcp:read", "mcp:write"],
  "expires_in": "90d",
  "password": "<current password>"
}
```

`expires_in` accepts `30d`, `90d`, `1y`, `never`. Validation rejects unknown scopes and
scopes above the caller's role.

**Step-up authentication.** `password` is verified with `Hash::check` against the current
user before any token is minted. A stolen session cookie or a borrowed logged-in laptop
cannot silently mint a long-lived portfolio-wide credential. Failures are rate-limited
(`throttle:5,1`) to prevent password probing through this endpoint.

**Isolation.** The controller filters `where('type', 'integration')` on every query,
scoped to `Auth::id()`. Session tokens are neither listed nor deletable here, so revoking
an integration never logs anybody out, and one user cannot enumerate or revoke another's
tokens.

---

## 5. UI (`lsm-web`)

A new section in `src/features/profile/pages/ProfilePage.tsx` — **"API & Integrationen"**. (Moved from `SettingsPage.tsx` during implementation: `/settings` is wrapped in `AdminRoute`, which would make tokens admin-only and contradict this spec's own role-aware scopes and per-user isolation; `/profile` is ungated and already hosts the other personal-credential cards. See the plan's "Deviations" section.)

**Token table:** name, scope badges, expiry (relative — "läuft in 87 Tagen ab"), last used
with IP. Tokens never used show a neutral "Nie verwendet" badge — the signal for
cleaning up forgotten credentials. Expired rows are visually muted.

**Create modal:** name field, four scope checkboxes with one-line risk descriptions
(`mcp:wp-destructive` visually flagged and disabled for roles that may not select it),
expiry dropdown defaulting to 90 days, password field.

**Reveal step:** after creation the token is shown once in a copy box, with an explicit
warning that it will not be shown again — plus a prefilled, copyable command:

```
claude mcp add --transport http lsm https://api.wartung-ls.com/mcp \
  --header "Authorization: Bearer <token>" --scope user
```

This turns the feature into a complete path from "I want AI access" to a working client,
which is the actual user need.

**Revoke:** confirmation dialog naming the token, warning that any client using it stops
working immediately.

---

## 6. Testing

Building on `tests/Feature/TokenExpirationTest.php` and `tests/Feature/Mcp/`:

**Expiration regression (highest value — guards the risky change):**
- A login token is still rejected 8 hours + 1 minute after creation
- A login token is still accepted at 7 hours 59 minutes
- An integration token with a 1-year expiry is accepted 24 hours after creation
- The backfill migration gives pre-existing `expires_at IS NULL` tokens a bounded life

**Scope enforcement:**
- `tools/list` with a `mcp:read` token omits every write, wp, and wp-destructive tool
- Calling `wp-restore-backup` directly with a `mcp:read` token is refused
- A legacy `*` token sees all 44 tools (backwards compatibility)
- Resources and prompts are hidden without `mcp:read`

**Controller:**
- Create without the correct password → 422, no token row written
- Create with a scope above the caller's role → 422
- A developer cannot list or delete another user's tokens → 404
- The plaintext token appears in the create response and never in the index response
- Revoking an integration token leaves the caller's session token working

---

## Risks

| Risk | Mitigation |
|---|---|
| `expiration => null` makes every legacy token immortal | Backfill migration in the same commit; covered by test |
| Session auth breaks for the whole team | Explicit `expires_at` at all three call sites; regression tests at both sides of the 8h boundary |
| A hidden tool is silently unavailable and looks like a bug | `assertScope()` returns an explicit "token lacks scope X" message on direct call |
| Long-lived token leaks | Step-up auth on creation, `last_used_ip` for detection, one-click revoke |

## Implementation order

1. Expiration fix + backfill migration + regression tests (riskiest, do it first and alone)
2. `type` / IP columns
3. `HasRequiredScope` trait + 44 one-line declarations + resources and prompts
4. `IntegrationTokenController`, routes, validation, step-up
5. `lsm-web` settings section
6. Re-issue the current `~/.claude.json` admin token as a scoped integration token

Estimated: roughly half a day across the two repos.
