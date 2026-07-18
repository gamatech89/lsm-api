# Branded Transactional Emails Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give all 20 Laravel notification emails a single on-brand look (matching the existing 2FA email), fix two dead links, deep-link the rest to the right SPA section, and correct the sender name/address.

**Architecture:** Publish Laravel's markdown-mail Blade components to `resources/views/vendor/mail`, add a branded theme CSS + logo header + footer, and register the theme in `config/mail.php`. Every `MailMessage` notification then renders branded with zero per-notification template code. Link fixes are one-line edits to each notification's `->action()` URL.

**Tech Stack:** Laravel 12, PHP 8.4 (prod) / 8.3 (local), Pest 4, Blade markdown mail, Chrome headless (SVG→PNG).

## Global Constraints

- Brand palette (from `resources/views/emails/two-factor-code.blade.php`): primary/CTA `#7C3AED`; header text `#3b1f6e`; strong text `#1a1a2e`; body text `#6b7280`; page background `#f4f4f7`; card `#ffffff` `border-radius:12px` with soft shadow; light-violet accent `#f5f0ff`.
- Sender name everywhere: **"Landeseiten Maintenance"** (not "LSM - …").
- All email links use `config('app.frontend_url')` (prod = `https://wartung-ls.com`). Never hardcode a host.
- SPA project deep-link is `/projects/{id}?section=<key>`; valid keys include `security`, `backups`, `support`, `todos`, `credentials`, `overview`.
- Logo is served as a PNG at an absolute URL: `config('app.url') . '/images/email-logo.png'` (SVG is unreliable in email clients).
- Verification renders HTML locally only — **never send real email** during implementation.
- Do not change notification copy/wording beyond the `->action()` URLs.

---

## File Structure

- Create `public/images/email-logo.png` — brand logo raster for email headers.
- Create `resources/views/vendor/mail/html/themes/landeseiten.css` — branded theme (copied from published default, then restyled).
- Modify `resources/views/vendor/mail/html/header.blade.php` — logo image header.
- Modify `resources/views/vendor/mail/html/footer.blade.php` — branded footer.
- Modify `config/mail.php` — register the `landeseiten` markdown theme.
- Modify `app/Notifications/*.php` — fix/deep-link `->action()` URLs (7 files).
- Modify `resources/views/emails/two-factor-code.blade.php` — logo image + palette reconcile.
- Modify `.env.example` — `MAIL_FROM_NAME` default.
- Test `tests/Feature/Emails/EmailBrandingTest.php`, `tests/Feature/Emails/EmailLinksTest.php`.

---

### Task 1: Email logo PNG asset

**Files:**
- Create: `public/images/email-logo.png` (from existing `public/images/landeseiten-logo.svg`)

**Interfaces:**
- Produces: a PNG at `public/images/email-logo.png`, ~160×160px, transparent background, usable at `config('app.url').'/images/email-logo.png'`.

- [ ] **Step 1: Render the SVG to PNG with headless Chrome**

```bash
cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api
SVG="$(pwd)/public/images/landeseiten-logo.svg"
OUT="$(pwd)/public/images/email-logo.png"
# Wrap the SVG in a fixed-size transparent HTML page and screenshot it
cat > /tmp/logo-shot.html <<HTML
<!doctype html><html><head><style>
html,body{margin:0;background:transparent}
img{width:160px;height:160px;display:block}
</style></head><body><img src="file://$SVG"></body></html>
HTML
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" \
  --headless=new --disable-gpu --hide-scrollbars --force-color-profile=srgb \
  --default-background-color=00000000 --window-size=160,160 \
  --screenshot="$OUT" "file:///tmp/logo-shot.html"
```

- [ ] **Step 2: Verify the PNG exists and is valid**

Run: `file public/images/email-logo.png && ls -la public/images/email-logo.png`
Expected: `PNG image data, 160 x 160` (or similar), non-zero size. If the logo renders off-center or clipped, adjust the `img` width/height and `--window-size` and re-run.

- [ ] **Step 3: Commit**

```bash
git add public/images/email-logo.png
git commit -m "feat(emails): add branded email logo PNG"
```

---

### Task 2: Publish + register the branded mail theme

**Files:**
- Create: `resources/views/vendor/mail/html/themes/landeseiten.css` (copy of published `default.css`, restyled)
- Modify: `config/mail.php` (add `markdown` block)
- Test: `tests/Feature/Emails/EmailBrandingTest.php`

**Interfaces:**
- Produces: `config('mail.markdown.theme') === 'landeseiten'`; all `MailMessage` emails render with the branded CTA color `#7C3AED`.

- [ ] **Step 1: Publish the Laravel mail components**

```bash
php artisan vendor:publish --tag=laravel-mail
ls resources/views/vendor/mail/html
```
Expected: `button.blade.php header.blade.php footer.blade.php message.blade.php panel.blade.php subcopy.blade.php table.blade.php themes/`

- [ ] **Step 2: Write the failing branding test**

```php
<?php
// tests/Feature/Emails/EmailBrandingTest.php
use App\Models\User;
use App\Models\Project;
use App\Models\SecurityScan;
use App\Notifications\MalwareDetectedNotification;

function renderMail($notification): string
{
    $user = User::factory()->make(['name' => 'Test User', 'email' => 'u@example.com']);
    return (string) $notification->toMail($user)->render();
}

it('renders the brand CTA color in notification emails', function () {
    $project = Project::factory()->make(['id' => 7, 'name' => 'Acme']);
    $scan = SecurityScan::factory()->make(['project_id' => 7, 'risk_level' => 'critical', 'threats_found' => 3]);
    $html = renderMail(new MalwareDetectedNotification($project, $scan));
    expect(strtolower($html))->toContain('#7c3aed');
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Emails/EmailBrandingTest.php`
Expected: FAIL — the default theme uses its own blue button color, not `#7c3aed`. (If `MalwareDetectedNotification` or the factories need specific args, adjust the make() calls to match the notification's constructor — read `app/Notifications/MalwareDetectedNotification.php` for the exact signature.)

- [ ] **Step 4: Create the branded theme CSS**

```bash
cp resources/views/vendor/mail/html/themes/default.css \
   resources/views/vendor/mail/html/themes/landeseiten.css
```
Then edit `resources/views/vendor/mail/html/themes/landeseiten.css` — apply these targeted replacements (the default.css uses these selectors; change the values):

- `body`, `.wrapper`: `background-color` → `#f4f4f7`
- `.content`, `.inner-body`: `background-color` → `#ffffff`
- `.inner-body`: add `border-radius: 12px;` and `box-shadow: 0 2px 8px rgba(0,0,0,0.06);` and set `border-color: transparent;`
- `.button-primary`: `background-color` → `#7C3AED`; `border-bottom-color`/`border-top-color`/`border-left-color`/`border-right-color` → `#7C3AED` (replace the default green/blue). Also `border-radius: 8px;`
- `.header a` (the brand link/text): `color` → `#3b1f6e`; `font-weight: 700;`
- `h1, h2, h3`: `color` → `#1a1a2e`
- `p`, `.body`: `color` → `#6b7280`
- `.footer`, `.footer p`, `.footer a`: `color` → `#9ca3af`

Search default.css for each selector and replace only the color/background/border-radius values. Do not remove selectors.

- [ ] **Step 5: Register the theme in config/mail.php**

Add this block inside the `return [ ... ]` array in `config/mail.php` (e.g. right before the `'from' =>` block):

```php
    'markdown' => [
        'theme' => 'landeseiten',
        'paths' => [
            resource_path('views/vendor/mail'),
        ],
    ],
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan config:clear && ./vendor/bin/pest tests/Feature/Emails/EmailBrandingTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add resources/views/vendor/mail config/mail.php tests/Feature/Emails/EmailBrandingTest.php
git commit -m "feat(emails): branded landeseiten mail theme (violet CTA, card)"
```

---

### Task 3: Branded header (logo) + footer

**Files:**
- Modify: `resources/views/vendor/mail/html/header.blade.php`
- Modify: `resources/views/vendor/mail/html/footer.blade.php`
- Test: `tests/Feature/Emails/EmailBrandingTest.php` (append)

**Interfaces:**
- Consumes: the logo PNG from Task 1, the theme from Task 2.
- Produces: every email HTML contains the logo `<img>` URL and the footer text "Landeseiten Maintenance".

- [ ] **Step 1: Append failing header/footer tests**

```php
// append to tests/Feature/Emails/EmailBrandingTest.php
it('renders the logo image and branded footer', function () {
    $project = Project::factory()->make(['id' => 7, 'name' => 'Acme']);
    $scan = SecurityScan::factory()->make(['project_id' => 7, 'risk_level' => 'critical', 'threats_found' => 3]);
    $html = renderMail(new MalwareDetectedNotification($project, $scan));
    expect($html)->toContain('/images/email-logo.png')
        ->and($html)->toContain('Landeseiten Maintenance')
        ->and($html)->toContain('please do not reply');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Emails/EmailBrandingTest.php --filter="logo image"`
Expected: FAIL — default header shows the app name text, no logo image; default footer has no "please do not reply".

- [ ] **Step 3: Replace the header component**

Overwrite `resources/views/vendor/mail/html/header.blade.php` with:

```blade
@props(['url'])
<tr>
<td class="header">
<a href="{{ config('app.frontend_url') }}" style="display:inline-block;">
<img src="{{ rtrim(config('app.url'), '/') }}/images/email-logo.png" width="48" height="48" alt="Landeseiten Maintenance" style="display:block;margin:0 auto 8px;border:0;">
<span style="font-size:16px;font-weight:700;color:#3b1f6e;letter-spacing:-0.3px;">Landeseiten Maintenance</span>
</a>
</td>
</tr>
```

- [ ] **Step 4: Replace the footer component**

Overwrite `resources/views/vendor/mail/html/footer.blade.php` with:

```blade
<tr>
<td>
<table class="footer" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="content-cell" align="center">
<p style="margin:0 0 4px;color:#9ca3af;font-size:13px;">
&copy; {{ date('Y') }} Landeseiten Maintenance ·
<a href="{{ config('app.frontend_url') }}" style="color:#7C3AED;text-decoration:none;">Dashboard</a>
</p>
<p style="margin:0;color:#9ca3af;font-size:12px;">This is an automated message — please do not reply.</p>
</td>
</tr>
</table>
</td>
</tr>
```

- [ ] **Step 5: Run to verify it passes**

Run: `./vendor/bin/pest tests/Feature/Emails/EmailBrandingTest.php`
Expected: PASS (all branding tests).

- [ ] **Step 6: Commit**

```bash
git add resources/views/vendor/mail/html/header.blade.php resources/views/vendor/mail/html/footer.blade.php tests/Feature/Emails/EmailBrandingTest.php
git commit -m "feat(emails): logo-image header and branded footer"
```

---

### Task 4: Fix + deep-link all notification links

**Files:**
- Modify: `app/Notifications/MalwareDetectedNotification.php:56`
- Modify: `app/Notifications/BackupCompletedNotification.php:62` (and its `->title(...)` at line 79)
- Modify: `app/Notifications/BackupFailedNotification.php:66` (and `->title(...)` at 82)
- Modify: `app/Notifications/SupportTicketReceivedNotification.php`, `SupportTicketClientReplyNotification.php`, `SupportTicketStaffReplyNotification.php`, `SupportTicketResolvedNotification.php`
- Modify: `app/Notifications/TodoAddedNotification.php`, `TodoAssignedNotification.php`, `TodoDueDateReminderNotification.php`
- Modify: `app/Notifications/CredentialAccessGrantedNotification.php`
- Test: `tests/Feature/Emails/EmailLinksTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: each notification's `toMail($user)->actionUrl` points to the correct `?section=` deep-link.

- [ ] **Step 1: Write the failing link tests**

```php
<?php
// tests/Feature/Emails/EmailLinksTest.php
use App\Models\User;
use App\Models\Project;
use App\Models\SecurityScan;
use App\Notifications\MalwareDetectedNotification;
use App\Notifications\BackupCompletedNotification;

function actionUrl($notification): ?string
{
    $user = User::factory()->make(['name' => 'T', 'email' => 't@example.com']);
    return $notification->toMail($user)->actionUrl;
}

it('links malware email to the security section', function () {
    $project = Project::factory()->make(['id' => 7, 'name' => 'Acme']);
    $scan = SecurityScan::factory()->make(['project_id' => 7, 'risk_level' => 'high', 'threats_found' => 2]);
    expect(actionUrl(new MalwareDetectedNotification($project, $scan)))
        ->toContain('/projects/7?section=security')
        ->and(actionUrl(new MalwareDetectedNotification($project, $scan)))
        ->not->toContain('/security"')  // no bare /security path
        ->and(actionUrl(new MalwareDetectedNotification($project, $scan)))
        ->not->toContain('localhost');
});

it('links backup-completed email to the backups section', function () {
    $project = Project::factory()->create(['id' => 8, 'name' => 'Beta']);
    // Build the notification the way the app does — read BackupCompletedNotification's
    // constructor for the exact args (project + backup meta).
    // Assert the URL ends with /projects/8?section=backups
    // (fill in constructor per the real signature)
})->skip('fill in once BackupCompletedNotification constructor is read');
```

Note: read each notification's constructor signature before writing its assertion; the malware/backup ones are the load-bearing fixes — cover those two concretely and add the others as you edit them.

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Emails/EmailLinksTest.php --filter=malware`
Expected: FAIL — current URL is `/projects/7/security`.

- [ ] **Step 3: Apply the URL edits**

`MalwareDetectedNotification.php` line 56:
```php
// was: ->action('View Scan Results', config('app.frontend_url') . "/projects/{$this->project->id}/security")
->action('View Scan Results', config('app.frontend_url') . "/projects/{$this->project->id}?section=security")
```

`BackupCompletedNotification.php` line 62 and the `->title(...)` at 79:
```php
// was: .../projects/{$project->id}/backups
config('app.frontend_url') . "/projects/{$project->id}?section=backups"
```

`BackupFailedNotification.php` line 66 and `->title(...)` at 82:
```php
config('app.frontend_url') . "/projects/{$project->id}?section=backups"
```

Support ticket notifications (all four) — change `"/projects/{$this->ticket->project_id}"` to:
```php
config('app.frontend_url') . "/projects/{$this->ticket->project_id}?section=support"
```

Todo notifications (all three) — change `"/projects/{$this->todo->project_id}"` to:
```php
config('app.frontend_url') . "/projects/{$this->todo->project_id}?section=todos"
```

`CredentialAccessGrantedNotification.php` — change the `$projectUrl` to:
```php
$projectUrl = config('app.frontend_url', 'http://localhost:3000') . "/projects/{$project->id}?section=credentials";
```

Leave unchanged (already valid): SslExpiring, DomainExpiring, SiteDown, SiteRecovered, ProjectStatusChanged, ProjectAssigned (`/projects/{id}` overview), ResetPassword, Welcome (`/reset-password?...`), TwoFactorCode (no link).

- [ ] **Step 4: Run to verify the malware/backup tests pass**

Run: `./vendor/bin/pest tests/Feature/Emails/EmailLinksTest.php`
Expected: PASS for the concrete malware (and backup, once filled in) assertions.

- [ ] **Step 5: Commit**

```bash
git add app/Notifications tests/Feature/Emails/EmailLinksTest.php
git commit -m "fix(emails): correct dead links and deep-link notifications to SPA sections"
```

---

### Task 5: 2FA blade reconcile + sender name default

**Files:**
- Modify: `resources/views/emails/two-factor-code.blade.php`
- Modify: `.env.example`

**Interfaces:**
- Produces: the 2FA email uses the same logo image + "Landeseiten Maintenance" wordmark as the shared header; `.env.example` documents `MAIL_FROM_NAME="Landeseiten Maintenance"`.

- [ ] **Step 1: Swap the 2FA header text for the logo image**

In `resources/views/emails/two-factor-code.blade.php`, replace the header `<span>{{ config('app.name') }}</span>` block with the logo image + wordmark (matching Task 3's header):

```blade
<img src="{{ rtrim(config('app.url'), '/') }}/images/email-logo.png" width="48" height="48" alt="Landeseiten Maintenance" style="display:block;margin:0 auto 8px;border:0;">
<span style="font-size:16px;font-weight:700;color:#3b1f6e;letter-spacing:-0.3px;">Landeseiten Maintenance</span>
```

Keep the rest of the 2FA blade (code box, colors) — it already matches the palette.

- [ ] **Step 2: Update .env.example sender name**

In `.env.example`, set:
```
MAIL_FROM_NAME="Landeseiten Maintenance"
```
(Leave `MAIL_FROM_ADDRESS` / `MAIL_USERNAME` as env-provided placeholders — the production address change to `noreply@` is a deploy step done separately after the mailbox is created.)

- [ ] **Step 3: Lint the blade + verify 2FA renders**

Run: `php artisan view:clear && php -l resources/views/emails/two-factor-code.blade.php`
Expected: `No syntax errors detected`. (Blade `php -l` only checks PHP tags; a full render check happens in Task 6.)

- [ ] **Step 4: Commit**

```bash
git add resources/views/emails/two-factor-code.blade.php .env.example
git commit -m "feat(emails): 2FA logo header + sender name default"
```

---

### Task 6: Full render verification (all 20 notifications)

**Files:**
- Create: `tests/Feature/Emails/AllNotificationsRenderTest.php`

**Interfaces:**
- Consumes: all notifications + theme.
- Produces: proof that every notification renders branded HTML with no localhost/dead links.

- [ ] **Step 1: Write a test that renders every mail-channel notification and asserts no bad links**

```php
<?php
// tests/Feature/Emails/AllNotificationsRenderTest.php
use App\Models\User;

it('every rendered notification is branded and has no localhost or bare section-less broken links', function () {
    // Build one representative instance per mail notification (read each constructor
    // for required args; use Model::factory()->make() for related models).
    // For each: $html = (string) $n->toMail($user)->render();
    // Assert: contains '/images/email-logo.png', contains 'Landeseiten Maintenance',
    //         does NOT contain 'localhost', does NOT contain '/security"' or '/backups"'
    //         (the two old dead paths).
    // Keep this list in sync with app/Notifications/*.
})->skip('populate with each notification instance');
```

Populate the test with each mail notification (the ones that return a `MailMessage`). Skip database-only notifications. For each, render and assert the brand markers + absence of `localhost`, `/projects/{id}/security` (bare), `/projects/{id}/backups` (bare).

- [ ] **Step 2: Run the full email suite**

Run: `./vendor/bin/pest tests/Feature/Emails`
Expected: PASS.

- [ ] **Step 3: Eyeball one rendered email**

Render one notification to an HTML file and open it to confirm the visual look:
```bash
php artisan tinker --execute='
$u = \App\Models\User::factory()->make(["name"=>"Test","email"=>"t@example.com"]);
$p = \App\Models\Project::factory()->make(["id"=>7,"name"=>"Acme"]);
$s = \App\Models\SecurityScan::factory()->make(["project_id"=>7,"risk_level"=>"critical","threats_found"=>3]);
file_put_contents("/tmp/email-preview.html", (string)(new \App\Notifications\MalwareDetectedNotification($p,$s))->toMail($u)->render());
echo "wrote /tmp/email-preview.html";
'
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless=new --disable-gpu --window-size=680,900 --screenshot=/tmp/email-preview.png "file:///tmp/email-preview.html"
```
Then Read `/tmp/email-preview.png` to visually confirm: logo header, white card, violet button, branded footer.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Emails/AllNotificationsRenderTest.php
git commit -m "test(emails): render-verify all notifications are branded with valid links"
```

---

## Deployment notes (not code — run after merge, by the user/controller)

1. User creates the `noreply@wartung-ls.com` mailbox on Hostinger.
2. On `wartung-api`, update production `.env`: `MAIL_USERNAME=noreply@wartung-ls.com`, `MAIL_FROM_ADDRESS=noreply@wartung-ls.com`, `MAIL_FROM_NAME="Landeseiten Maintenance"`, then `php artisan config:clear`.
3. Send one real test email (e.g. a password-reset to an internal address) to confirm SMTP still authenticates with the new mailbox and the branding renders in a real client.

---

## Self-Review

**Spec coverage:**
- Branded theme via vendor/mail override → Task 2. ✅
- Logo image (PNG from SVG, absolute URL) → Task 1 + Task 3 header. ✅
- Card/button/footer/colors → Task 2 (CSS) + Task 3 (header/footer). ✅
- Fix 2 broken links + deep-link the rest → Task 4 (table matches spec). ✅
- 2FA alignment → Task 5. ✅
- Sender name/address → Task 5 (.env.example) + Deployment notes (prod env, after mailbox). ✅
- Verification renders locally, never sends → Task 6 (render()/screenshot, no Mail::send). ✅
- Out-of-scope items (inbound, plaintext password) → not in any task. ✅

**Placeholder scan:** Task 4 Step 1 and Task 6 Step 1 contain `skip()` stubs deliberately — they instruct the implementer to read each notification's constructor and populate assertions, because the exact constructor args differ per notification and must be read from source (not guessable). The concrete malware/backup link fixes ARE fully specified. This is intentional guidance, not a placeholder gap.

**Type consistency:** `renderMail()`/`actionUrl()` helpers, `toMail($user)->render()`, `toMail($user)->actionUrl`, theme name `landeseiten`, logo path `/images/email-logo.png`, section keys (`security`/`backups`/`support`/`todos`/`credentials`) are used consistently across Tasks 2–6. ✅

**Note for implementer:** Some notifications' `toMail()` may require a persisted model (DB) rather than `make()`. If `render()`/`toMail()` throws on a `make()`d model, switch that case to `factory()->create()` within a `RefreshDatabase` test. The `EmailLinksTest`/`AllNotificationsRenderTest` should use `RefreshDatabase` if any notification touches the DB during `toMail()`.
