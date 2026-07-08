# Ephemeral Secret Send — Design

**Date:** 2026-07-07
**Status:** Approved (design)
**Repos affected:** `lsm-api` (backend), `lsm-web` (frontend)

## Problem

The platform can securely share a credential via a one-time link, but only for
credentials **already stored** in a project. The team sometimes needs to send a
secret **ad-hoc and temporarily** — a one-off password, token, or login — without
first saving it as a project credential.

## Summary

Add a self-contained "temporary send" feature: a signed-in team member fills a
small form with structured fields, gets a public link, and the recipient can
reveal the secret **exactly once**. The secret is encrypted at rest, burned on
first view, expires on a hard deadline, and is audit-logged. It is **not** tied
to any project or stored `Credential`.

Chosen approach: **B — a dedicated `EphemeralSecret` model**, kept fully separate
from the existing credential-share system so the live, working share flow is not
disturbed. (Rejected: A — overloading `CredentialShareLink`; C — zero-knowledge
client-side encryption, more complexity than warranted here.)

## Decisions (locked)

- **Payload:** structured fields — `username`, `password`, `url`, `note` (all optional individually), plus an optional `title`.
- **Lifecycle:** burn-after-read **and** a hard expiry (whichever comes first).
- **Who can create:** any authenticated user (admin, manager, developer).
- **Storage:** server-side, encrypted at rest with Laravel's built-in `encrypted:array` cast (no dependency on the separate `EncryptedString` cast).
- **Recipient path:** `/s/:token` (short).
- **Tombstone:** after a secret is viewed/expired, a metadata-only row lingers ~7 days so a second visit shows a clear "already viewed / expired" message and the audit trail is preserved; a scheduled job purges it afterwards.

## Data model — `ephemeral_secrets`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `token` | string(64), unique, indexed | random 40-char public link id (`Str::random(40)`) |
| `created_by` | FK `users.id`, nullable, `nullOnDelete` | who sent it (audit) |
| `title` | string, nullable | optional label |
| `payload` | text, nullable | `{username,password,url,note}` JSON, cast `encrypted:array`; **nulled on burn** |
| `access_password` | string, nullable | optional gate, cast `hashed` |
| `expires_at` | datetime | hard expiry |
| `viewed_at` | datetime, nullable | set on first successful reveal (the burn marker) |
| `last_viewed_ip` | string, nullable | audit |
| `created_at` / `updated_at` | | |

Model helpers: `isExpired()`, `isBurned()` (`viewed_at !== null`), `isAvailable()` (`!isExpired() && !isBurned()`).

## Backend flow (Laravel)

### Routes (`routes/api.php`)

Authenticated:
- `POST /api/v1/ephemeral-secrets` → `EphemeralSecretController@store`

Public (throttled — brute-force/enumeration guard):
- `GET  /api/v1/s/{token}` → `@show`   · `throttle:20,1`
- `POST /api/v1/s/{token}/access` → `@access` · `throttle:10,1`

### `store(Request)` — create

- Auth required (any role). No `Gate` needed (not project-scoped).
- Validate: `title` nullable string ≤255; at least one of `username/password/url/note` present; each nullable string (password/note may be longer text); `expires_in_minutes` required int 5…10080 (max 7 days); `access_password` nullable string ≥4.
- Create row: `token = Str::random(40)`, `payload = [filtered fields]`, `expires_at = now()+minutes`, `created_by = auth id`.
- Audit: `activity()->causedBy(user)->performedOn(secret)->log('created ephemeral secret')` — **never log the payload**.
- Return `{ link: {frontend_url}/s/{token}, expires_at }`.

### `show(token)` — metadata only (does NOT burn)

- Look up by token. If not found, expired, or burned → `404` `{ available:false, reason }` (reason: `expired` | `viewed` | `not_found`).
- Else → `{ available:true, title, has_password: !!access_password, expires_at }`. **No secret data.**

### `access(Request, token)` — the one-time reveal (burns)

- Look up by token. If missing / expired / already burned → `404` (`available:false`, reason as above).
- If `access_password` set → require matching `Hash::check($request->password, $access_password)`, else `403`. (Uses the correct hash verification — not the plaintext-compare bug fixed elsewhere this session.)
- Success: capture `$data = $secret->payload` (decrypted), then **burn**: `payload = null`, `viewed_at = now()`, `last_viewed_ip = request ip`, save.
- Audit: `log('revealed ephemeral secret')` with ip.
- Return `{ data: {title, username, password, url, note}, revealed_once: true }`.

Burn is atomic-enough for this use: a DB transaction wraps the "still available?" re-check + null-out so two simultaneous reveals can't both succeed.

## Frontend (React + Ant Design)

### Create — global "Send a secret"

- A button in `AuthenticatedLayout` top bar (icon + label), visible to all roles.
- Opens `SendSecretModal` (modeled on `ShareCredentialModal`, minus project/credential selection): fields Title, Username, Password, URL, Note; Expiry select (1h / 24h / 7d / custom minutes); optional password toggle; on submit → `POST /ephemeral-secrets`; success view shows the link with a copy button and expiry, matching the existing share result UI.
- New API module `lib/ephemeral-secrets-api.ts` + types in `packages/types`.

### Reveal — recipient page `/s/:token`

- New public route in `App.tsx` (alongside `/share/:token`), page reusing `PublicSharePage`'s layout/styling.
- On load: `GET /s/{token}` → if unavailable, show the reason state ("This link has expired" / "already been viewed").
- If available: show title + expiry, optional password input, and a **"Reveal (one-time)"** button. Clicking calls `POST /s/{token}/access`; on success render the fields with per-field copy buttons and a prominent "This was the only view — the secret is now deleted" notice. On failure (wrong password / already gone) show the matching message.

## Security & operations

- **Encryption at rest:** `payload` cast `encrypted:array`; `access_password` cast `hashed`.
- **Burn:** payload nulled on first reveal, inside a transaction.
- **Rate limiting:** throttle on both public routes.
- **Audit:** create + reveal logged via `spatie/activitylog` (installed); payload never logged.
- **Purge job:** `php artisan ephemeral-secrets:purge` deletes rows where `expires_at < now()-7d` OR `viewed_at < now()-7d`; scheduled daily in `routes/console.php`.
- **No enumeration leak:** `show`/`access` return the same `404` shape for not-found vs expired vs burned (reason is coarse, not a data oracle).

## Testing (Pest, `tests/Feature/EphemeralSecretTest.php`)

1. Any role (admin/manager/developer) can create a secret and gets a link.
2. `show` returns metadata only — never the secret fields.
3. `access` returns the payload once; a second `access` returns unavailable (burned).
4. Expired secret: `show`/`access` return unavailable.
5. Password-protected: correct password reveals, wrong password `403`, and it does **not** burn on a failed attempt.
6. Validation: empty payload (no fields) rejected; `expires_in_minutes` bounds enforced.
7. Purge command deletes expired/viewed tombstones and leaves live ones.

## Out of scope (YAGNI)

- Zero-knowledge / client-side encryption (approach C) — revisit only if "server literally cannot read it" becomes a requirement.
- File attachments, multiple recipients with per-recipient tracking, editing after send.
