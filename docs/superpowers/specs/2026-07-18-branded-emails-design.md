# Branded Transactional Emails — Design Spec

**Date:** 2026-07-18
**Status:** Approved (design), pending implementation
**Repo:** `lsm-api` (Laravel platform) — single-repo change

## Problem

The platform sends 20 transactional emails (Laravel notifications). Only the
2FA email has a branded template; the other 19 render with Laravel's **default**
mail theme — generic, off-brand, technical header ("LSM - Landeseiten
Maintenance"). Two emails also link to **non-existent SPA routes** (dead links),
and the sender address contains a typo (`noreplay@`).

Audit findings:
- **Design:** 19/20 notifications use the stock Laravel markdown-mail theme. Only
  `resources/views/emails/two-factor-code.blade.php` is branded (violet `#7C3AED`,
  white card, `#3b1f6e` header, monospace code box).
- **Broken links:** `MalwareDetectedNotification` → `/projects/{id}/security` and
  `BackupCompletedNotification` → `/projects/{id}/backups`. Neither route exists in
  the SPA; the `*` catch-all redirects them to `/dashboard`.
- **Sender:** `MAIL_FROM_ADDRESS=noreplay@wartung-ls.com` (typo), `MAIL_FROM_NAME`
  = `${APP_NAME}` = "LSM - Landeseiten Maintenance" (technical).
- **No inbound email processing** exists — "incoming" emails are the notifications
  recipients receive; nothing to audit on an inbound side.

## Goal

A consistent, on-brand, theme-matched email system with correct deep-links, built
in one place so all notifications stay consistent.

## Architecture / Approach

**Laravel `vendor/mail` theme override.** Laravel notifications render `MailMessage`
content through markdown-mail Blade components (`header`, `button`, `panel`,
`subcopy`, `footer`, `message`) plus a theme CSS file. Publishing and restyling
those components brands **all 19 MailMessage notifications at once**, with no
per-notification template duplication.

*Rejected alternative:* a custom HTML template per notification — 20× duplication,
high maintenance cost, drift risk.

### Components & files

- **Create** `resources/views/vendor/mail/html/themes/landeseiten.css` — brand theme:
  page background `#f4f4f7`, white card (`border-radius:12px`, soft shadow), CTA
  button `#7C3AED` (white text, rounded), heading `#1a1a2e`, body `#6b7280`, system
  font stack. Registered via `config/mail.php` `markdown.theme => 'landeseiten'`.
- **Create/override** `resources/views/vendor/mail/html/header.blade.php` — render the
  Landeseiten lightbulb **logo image** (`<img>` with absolute URL
  `config('app.url') . '/images/email-logo.png'`, width ~40px, centered), with the
  brand wordmark beneath.
- **Override** `resources/views/vendor/mail/html/footer.blade.php` — "Landeseiten
  Maintenance", dashboard link (`config('app.frontend_url')`), current year, and a
  discreet "This is an automated message — please do not reply." line.
- **Override** `button.blade.php` / `panel.blade.php` only if the theme CSS cannot
  achieve the look via classes (prefer CSS-only changes).
- **Add** `public/images/email-logo.png` — a PNG rendered from the existing
  `logo-landeseiten.svg` (SVG is unreliable in email clients). ~80×80px @2x, served
  by the API at an absolute URL so all clients load it.
- **Align** `resources/views/emails/two-factor-code.blade.php` visually with the shared
  theme (it is already ~90% there — reconcile colors/logo so it matches; it stays a
  standalone HTML blade since it is not a MailMessage).

### Link fixes (all in `app/Notifications/*`)

Update each `->action(label, url)` to the correct SPA deep-link. The SPA project
page (`ProjectDetailPageV2`) reads a `?section=` query param; confirmed section keys
include `security`, `backups`, `support`, `todos`, `credentials`, `overview`.

| Notification | New link |
|---|---|
| MalwareDetectedNotification | `{frontend}/projects/{id}?section=security` |
| BackupCompletedNotification | `{frontend}/projects/{id}?section=backups` |
| BackupFailedNotification | `{frontend}/projects/{id}?section=backups` |
| SupportTicketReceived / ClientReply / StaffReply / Resolved | `{frontend}/projects/{id}?section=support` |
| TodoAdded / TodoAssigned / TodoDueDateReminder | `{frontend}/projects/{id}?section=todos` |
| CredentialAccessGranted | `{frontend}/projects/{id}?section=credentials` |
| SslExpiring / DomainExpiring / SiteDown / SiteRecovered / ProjectStatusChanged / ProjectAssigned | `{frontend}/projects/{id}` (overview — unchanged, valid) |
| ResetPassword / Welcome | `{frontend}/reset-password?token=…&email=…` (unchanged, valid) |
| TwoFactorCode | code only, no link (unchanged) |

All links continue to use `config('app.frontend_url')` (already correctly set to
`https://wartung-ls.com` in production).

### Sender fix

After the user creates the `noreply@wartung-ls.com` mailbox on Hostinger:
- Production `.env`: `MAIL_USERNAME=noreply@wartung-ls.com`,
  `MAIL_FROM_ADDRESS=noreply@wartung-ls.com`, `MAIL_FROM_NAME="Landeseiten Maintenance"`.
- Local `.env.example`: update `MAIL_FROM_NAME` default to `"Landeseiten Maintenance"`
  (leave address/username as env-provided).
- No code change — these are config/env only. The `MAIL_FROM_NAME` change stops
  emails from showing the technical "LSM - …" name.

## Error handling / edge cases

- **Logo fails to load** (client blocks images): the header includes the brand
  wordmark text beneath the logo and `alt="Landeseiten Maintenance"`, so the email
  still reads correctly image-off.
- **Sender mailbox not yet created:** the sender fix is applied only after the
  mailbox exists; until then the config stays as-is so mail keeps sending.
- **Welcome email plaintext password** (pre-existing): out of scope for this design
  (noted as a separate security follow-up, not changed here).

## Testing / verification

- **Render locally, do not send:** set `MAIL_MAILER=log` (or Mailpit) locally and
  trigger each notification (`Notification::route('mail', ...)->notify(...)` via a
  small tinker/artisan snippet, or a temporary preview route) to produce the HTML.
- **Visual check:** render every notification's HTML and confirm the branded header,
  card, button color, and footer appear consistently.
- **Link check:** extract every `href` from the rendered HTML and confirm each
  resolves to a real SPA route (`/projects/:id`, `/projects/:id?section=…` where the
  section key is one of the confirmed set, `/reset-password`).
- **Logo check:** confirm `public/images/email-logo.png` loads at its absolute URL.
- No production email is sent during verification.

## Out of scope

- Inbound email processing (none exists).
- Welcome-email plaintext-password hardening (separate security follow-up).
- Marketing/broadcast emails (none exist).
- Changing notification *content/wording* beyond links (keep existing copy).
