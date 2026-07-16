# Advanced Ticketing — Design Spec

**Date:** 2026-07-16
**Scope:** lsm-api, lsm-wp, lsm-web (each repo gets branch `feature/advanced-ticketing`)
**Goal:** Turn the one-way WP→platform ticket intake into a two-way conversation with attachments, email notifications, and a front-end "flying" widget with page-screenshot capture.

## Background (current state)

- WP plugin (`lsm-wp`, class `LSM_Support`) posts tickets to the public webhook `POST /api/v1/webhooks/support-ticket`, authenticated by the site `api_key` in the JSON body (matched against `projects.health_check_secret_hash`).
- `SupportTicket` model: types (bug, content, design, feature, question, urgent), statuses (open, in_progress, resolved, closed), priorities, auto ticket numbers (`ST-00001`), Todo conversion.
- Staff triage in `lsm-web` (`SupportTicketsTab`, `/support` page) via Sanctum-protected routes.
- Gaps: no reply thread, no attachments, no API-side emails, support form only in wp-admin, webhook has no rate limit.

## Architecture decision

**Pull model (chosen):** the WP plugin fetches ticket lists/threads from the platform API on demand and posts replies to it. No ticket data duplicated in the WP database (except the existing local log of last 50 submissions). Email to the client acts as the "push" signal that a reply arrived.

Rejected: push model (platform calls WP REST — dual state, sync drift, fails behind firewalls); embedded platform UI via iframe/SSO (poor UX, client is not a platform user).

## 1. Data model (lsm-api)

New table `support_ticket_messages`:
- `id`, `support_ticket_id` (FK, cascade delete), `author_type` enum(`client`,`staff`), `user_id` nullable FK users (staff author), `author_name` string, `message` text, timestamps.
- The ticket's existing `message` column remains the problem description; the thread lives in this table.

New table `support_ticket_attachments`:
- `id`, `support_ticket_id` (FK, cascade delete), `support_ticket_message_id` nullable FK (attachment belongs to the ticket itself or to one message), `filename` (original name), `path`, `mime`, `size`, timestamps.
- Storage: local disk under `storage/app/support-attachments/{ticket_id}/` — **not** web-accessible; served only through authorized routes.
- Validation: images (png, jpg, webp, gif) + PDF; max 5 MB each; max 5 per message/ticket.

Behavior rules:
- Client reply to a `resolved`/`closed` ticket reopens it to `in_progress` and clears `read_at` (unread for staff again).
- Any client reply clears `read_at`.

## 2. API endpoints

### Plugin-facing (new controller `PluginTicketController`)
Auth: site `api_key` in `X-LSM-Key` header, resolved via existing `health_check_secret_hash` (SHA-256) lookup — same mechanism as the webhook, but header-based. Rate limit: 30/min per key.

- `GET  /api/v1/plugin/support-tickets` — list tickets for the authenticated project (id, number, subject, status, priority, last activity, unread-for-client hint via last staff message timestamp).
- `GET  /api/v1/plugin/support-tickets/{id}` — ticket + full message thread + attachment metadata.
- `POST /api/v1/plugin/support-tickets` — create ticket (multipart: fields + attachments, incl. widget screenshot).
- `POST /api/v1/plugin/support-tickets/{id}/messages` — client reply (multipart, optional attachments).
- `GET  /api/v1/plugin/support-tickets/attachments/{id}` — download attachment (must belong to the authenticated project).

All `{id}` access is scoped to the authenticated project (404 otherwise).

Existing webhook `POST /webhooks/support-ticket` stays functional (backwards compat for older plugin versions) and gains a throttle.

### Staff-facing (existing Sanctum + 2FA group)
- `show` response includes `messages` (with attachments).
- `POST /api/v1/support-tickets/{id}/messages` — staff reply (multipart, optional attachments). Authorization mirrors existing update Gate on the parent project.
- `GET /api/v1/support-tickets/attachments/{id}` — authorized download.

## 3. Email notifications (Laravel Notifications, queued)

| Event | Recipients |
|---|---|
| New ticket (webhook or plugin route) | Project-assigned staff + admins |
| Client reply | Project-assigned staff + admins |
| Staff reply | `client_email` — includes reply text and "respond from your site" pointer |
| Ticket resolved | `client_email` |

No client-facing links to the platform (clients are not platform users). Guard against notification loops: staff-reply notification goes only to the client; client-reply only to staff.

## 4. WP plugin — flying widget (lsm-wp)

- Floating button (bottom-right) on the **front-end for logged-in administrators only** (`manage_options`); toggle in plugin settings.
- Panel with two tabs:
  - **New ticket:** type, subject, message + "Capture page" button — bundled **html2canvas** (no CDN) snapshots the current page, user draws a highlight rectangle over the problem area, result attached as image. Additional file attachments allowed.
  - **My tickets:** list from platform, thread view, reply box with attachments.
- The browser never sees the `api_key`: widget JS calls `admin-ajax.php` (nonce-protected, capability-checked), and the plugin proxies server-side to the platform with the key in `X-LSM-Key`.
- "New reply" badge: server-side unread check cached in a transient (~5 min TTL).
- The same UI is reused on the plugin's wp-admin page (shared JS/CSS); the existing modal form is replaced by the new components.
- Existing local logging of submissions (`lsm_support_requests` option) and `wp_mail` fallback stay.

## 5. lsm-web — staff UI

Extend `SupportTicketsTab` (and detail rendering used by `/support`):
- Conversation thread under the ticket description (client vs staff bubbles).
- Reply box with attachment upload.
- Attachment display/download (authorized endpoint).
- Unread indicator already exists (`read_at`); client replies re-flag it via the API rule above.
- `support-tickets-api.ts`: add `getMessages`-inclusive show, `postMessage`, attachment URL helper.

## 6. Security

- Throttle plugin routes (30/min per key) and the legacy webhook.
- Attachments stored outside public root; MIME + extension + size validation server-side; served with `Content-Disposition: attachment` and correct MIME.
- Plugin routes are project-scoped by the API key; cross-project access returns 404.
- `hash_equals`-style comparison already in place via SHA-256 hash lookup.

## 7. Testing & verification

1. **Baseline check first:** run lsm-api locally, execute existing test suite, simulate the current plugin webhook with curl — confirm today's flow works before building.
2. Feature tests for every new endpoint: auth failures (missing/wrong key), project scoping, thread CRUD, reopen-on-client-reply, attachment validation limits, notifications via `Notification::fake()`.
3. lsm-wp: manual verification checklist (widget render, capability gating, proxy auth, screenshot capture) — user performs final end-to-end test on production.
4. SQLite in dev/tests (note: enum columns — follow existing migration patterns that already work on SQLite).

## Out of scope (YAGNI)

- Client accounts on the platform, ticket CC/watchers, canned responses, SLA timers, ticket merging, non-admin WP roles (settings toggle exists but role stays admin-only this round), push notifications to WP.
