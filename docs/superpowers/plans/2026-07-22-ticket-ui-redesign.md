# Ticket UI Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the plugin's amateurish emoji-`<select>` ticket UI with professional icon-card type pickers, a fixed floating button, a polished widget, and a client-facing priority that seeds the platform's existing severity field.

**Architecture:** One PHP source of truth for ticket types + client priorities + inline SVG icons (`LSM_Ticket_Types`), consumed by the JS flying widget (via `wp_localize_script`) and the server-rendered wp-admin form; a shared `ticket-ui.css` gives both surfaces identical card/segment components. The client's priority choice travels as `reported_priority` to the platform, which maps it onto the existing `support_tickets.priority` severity (no new column, no migration).

**Tech Stack:** WordPress plugin (plain PHP + vanilla JS + jQuery for admin), Laravel 11 + Pest (lsm-api). No build step, no CDN.

## Global Constraints

- **No external assets** — all icons are inline SVG; all CSS/JS local (keeps CSP/Wordfence clean; see the remote-malware-scanner work).
- **No build step** — plain PHP/JS/CSS only.
- **Bump `LSM_VERSION`** (currently `2.8.0`, `landeseiten-maintenance.php:22`) whenever an enqueued asset changes — it is the cache-buster.
- **No emoji** in any ticket-type label (UI or email).
- **`urgent` stays** in the platform `support_tickets.type` enum and in the API's accepted `type` allowlist for backward compatibility with older plugin builds; the new plugin never sends `urgent` as a type.
- **Client priority mapping:** `normal → medium`, `high → high`, `urgent → critical`. Values `normal|high|urgent`.
- **Branches:** `feature/ticket-ui-redesign` in both `lsm-api` (already created) and `lsm-wp` (created in Task 2).
- **Release caveat:** `lsm-wp/build-release.sh` can choke on divergent local tags — build the zip manually / prune tags (see project memory).

---

## Local verification harness (lsm-wp)

The plugin has **no PHPUnit/JS test harness**, so lsm-wp tasks are verified manually in a local WordPress via `@wordpress/env` (Docker).

One-time setup:
1. `cd lsm-wp/landeseiten-maintenance`
2. Create `.wp-env.json`: `{ "plugins": ["."], "config": { "WP_DEBUG": true } }`
3. `npx @wordpress/env start` → WordPress at `http://localhost:8888` (admin / `password`)
4. Activate **Landeseiten Maintenance**. The flying widget shows for `manage_options` users on the front-end and on the plugin admin page (footer).

To exercise the plugin↔platform path, run lsm-api locally (`php artisan serve`) and configure the plugin's API base/key so a `Project.health_check_secret` matches the plugin key; otherwise verify the outgoing request payload in the browser **Network** tab (the `admin-ajax.php` POST for `action=lsm_submit_support`). Stop env with `npx @wordpress/env stop`.

---

## Task 1: lsm-api — client `reported_priority` seeds ticket severity

**Files:**
- Modify: `lsm-api/app/Http/Controllers/Api/V1/PluginTicketController.php:90-116`
- Test: `lsm-api/tests/Feature/PluginTicketEndpointsTest.php` (append cases)

**Interfaces:**
- Produces: the plugin create endpoint `POST /api/v1/plugin/support-tickets` now accepts optional `reported_priority` ∈ `{normal,high,urgent}` and sets `support_tickets.priority` to `{medium,high,critical}`; when absent it falls back to the existing type-derivation. No response shape change (`priority` already serialized).

- [ ] **Step 1: Write the failing tests** — append to `tests/Feature/PluginTicketEndpointsTest.php`:

```php
test('reported_priority seeds the ticket severity', function () {
    pluginProject('KEY_RP');

    foreach (['normal' => 'medium', 'high' => 'high', 'urgent' => 'critical'] as $reported => $expected) {
        $this->post('/api/v1/plugin/support-tickets', [
            'type' => 'bug',
            'subject' => "RP {$reported}",
            'message' => 'x',
            'client_email' => 'c@e.com',
            'reported_priority' => $reported,
        ], ['X-LSM-Key' => 'KEY_RP', 'Accept' => 'application/json'])->assertCreated();

        expect(SupportTicket::where('subject', "RP {$reported}")->firstOrFail()->priority)->toBe($expected);
    }
});

test('without reported_priority, severity still derives from type', function () {
    pluginProject('KEY_FALLBACK');

    $this->post('/api/v1/plugin/support-tickets', [
        'type' => 'bug', 'subject' => 'Fallback bug', 'message' => 'x', 'client_email' => 'c@e.com',
    ], ['X-LSM-Key' => 'KEY_FALLBACK', 'Accept' => 'application/json'])->assertCreated();

    expect(SupportTicket::where('subject', 'Fallback bug')->firstOrFail()->priority)->toBe('high');
});

test('rejects an invalid reported_priority', function () {
    pluginProject('KEY_BADRP');

    $this->post('/api/v1/plugin/support-tickets', [
        'type' => 'bug', 'subject' => 'bad', 'message' => 'x', 'client_email' => 'c@e.com',
        'reported_priority' => 'sofort',
    ], ['X-LSM-Key' => 'KEY_BADRP', 'Accept' => 'application/json'])->assertStatus(422);
});
```

- [ ] **Step 2: Run the tests, verify they fail**

Run: `cd lsm-api && ./vendor/bin/pest --filter="reported_priority|derives from type"`
Expected: FAIL — `reported_priority` currently ignored (severity comes only from type; the `normal→medium` case will still pass by luck, but `high`/`urgent`-with-type-`bug` will assert `high`/`critical` vs actual `high`, and the invalid-value test gets 201 instead of 422).

- [ ] **Step 3: Update the controller** — in `PluginTicketController::store`, replace the validation array (lines 90-97) and the priority derivation (lines 99-103):

```php
        $validated = $request->validate(array_merge([
            'type' => 'required|in:bug,content,design,feature,question,urgent',
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
            'client_email' => 'required|email',
            'client_name' => 'nullable|string|max:255',
            'problem_page' => 'nullable|string|max:500',
            'reported_priority' => 'nullable|in:normal,high,urgent',
        ], SupportTicketAttachmentService::rules()));

        // The client can report urgency directly; it seeds the staff-owned
        // severity (staff can re-triage later). Older plugins omit it — fall
        // back to deriving severity from the ticket type.
        $priority = match ($validated['reported_priority'] ?? null) {
            'urgent' => 'critical',
            'high' => 'high',
            'normal' => 'medium',
            default => match ($validated['type']) {
                'urgent' => 'critical',
                'bug' => 'high',
                default => 'medium',
            },
        };
```

(The `SupportTicket::create([... 'priority' => $priority ...])` call at lines 105-116 is unchanged.)

- [ ] **Step 4: Run the tests, verify they pass**

Run: `./vendor/bin/pest --filter="reported_priority|derives from type"`
Expected: PASS. Then run the whole ticket suite to confirm no regression (the existing `urgent → critical` create test at line 64 still passes via the fallback):
Run: `./vendor/bin/pest tests/Feature/PluginTicketEndpointsTest.php`
Expected: PASS (all).

- [ ] **Step 5: Commit**

```bash
cd lsm-api
git add app/Http/Controllers/Api/V1/PluginTicketController.php tests/Feature/PluginTicketEndpointsTest.php
git commit -m "feat(tickets): accept client reported_priority, seed severity"
```

---

## Task 2: lsm-wp — branch + `LSM_Ticket_Types` source of truth

**Files:**
- Create: `lsm-wp/landeseiten-maintenance/includes/class-lsm-ticket-types.php`
- Modify: `lsm-wp/landeseiten-maintenance/landeseiten-maintenance.php:76` (add `require_once`)

**Interfaces:**
- Produces: `LSM_Ticket_Types::types()` → `['bug'=>['label'=>..,'icon'=>'bug'], ...]` (5 types, no `urgent`); `::priorities()` → `['normal'=>['label'=>..,'severity'=>'medium'], 'high'=>[..'high'], 'urgent'=>[..'critical']]`; `::default_priority()` → `'normal'`; `::type_label($code)` → string; `::icon($key)` → inline SVG string; `::icons()` → `key=>svg` map.

- [ ] **Step 1: Create the lsm-wp branch**

```bash
cd lsm-wp/landeseiten-maintenance
git checkout -b feature/ticket-ui-redesign
```

- [ ] **Step 2: Create `includes/class-lsm-ticket-types.php`**

```php
<?php
/**
 * Single source of truth for support-ticket types, client priorities, and
 * their inline SVG icons. Consumed by the flying widget (via
 * wp_localize_script), the wp-admin support form, and the email builder.
 *
 * @package Landeseiten_Maintenance
 */

if (!defined('ABSPATH')) {
    exit;
}

class LSM_Ticket_Types {

    /**
     * Ticket types offered in the pickers: code => [label, icon].
     * 'urgent' is intentionally absent — urgency is now a priority, not a
     * type. (The platform keeps 'urgent' in its enum for historical tickets.)
     */
    public static function types() {
        return [
            'bug'      => ['label' => __('Bug / Error', 'landeseiten-maintenance'),    'icon' => 'bug'],
            'content'  => ['label' => __('Content Change', 'landeseiten-maintenance'), 'icon' => 'file-text'],
            'design'   => ['label' => __('Design Change', 'landeseiten-maintenance'),  'icon' => 'palette'],
            'feature'  => ['label' => __('New Feature', 'landeseiten-maintenance'),    'icon' => 'sparkles'],
            'question' => ['label' => __('Question', 'landeseiten-maintenance'),       'icon' => 'help-circle'],
        ];
    }

    /**
     * Client-facing priority levels: code => [label, severity]. `severity` is
     * the platform's staff-owned scale that the client choice seeds.
     */
    public static function priorities() {
        return [
            'normal' => ['label' => __('Normal', 'landeseiten-maintenance'), 'severity' => 'medium'],
            'high'   => ['label' => __('High', 'landeseiten-maintenance'),   'severity' => 'high'],
            'urgent' => ['label' => __('Urgent', 'landeseiten-maintenance'), 'severity' => 'critical'],
        ];
    }

    /** Default priority code. */
    public static function default_priority() {
        return 'normal';
    }

    /** Human label for a type code (email/back-compat), falls back to the code. */
    public static function type_label($code) {
        $types = self::types();
        return isset($types[$code]) ? $types[$code]['label'] : $code;
    }

    /** Inline SVG icon markup for a key ('' for unknown keys). */
    public static function icon($key) {
        $icons = self::icons();
        return isset($icons[$key]) ? $icons[$key] : '';
    }

    /**
     * icon-key => SVG string. Static trusted markup — Lucide-style line icons,
     * 24x24, stroke:currentColor. Safe to echo without escaping.
     */
    public static function icons() {
        return [
            'bug' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m8 2 1.88 1.88"/><path d="M14.12 3.88 16 2"/><path d="M9 7.13v-1a3.003 3.003 0 1 1 6 0v1"/><path d="M12 20c-3.3 0-6-2.7-6-6v-3a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v3c0 3.3-2.7 6-6 6"/><path d="M12 20v-9"/><path d="M6.53 9C4.6 8.8 3 7.1 3 5"/><path d="M6 13H2"/><path d="M3 21c0-2.1 1.7-3.9 3.8-4"/><path d="M20.97 5c0 2.1-1.6 3.8-3.5 4"/><path d="M22 13h-4"/><path d="M17.2 17c2.1.1 3.8 1.9 3.8 4"/></svg>',
            'file-text' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>',
            'palette' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg>',
            'sparkles' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .962 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.962 0z"/><path d="M20 3v4"/><path d="M22 5h-4"/><path d="M4 17v2"/><path d="M5 18H3"/></svg>',
            'help-circle' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg>',
        ];
    }
}
```

- [ ] **Step 3: Require the class** — in `landeseiten-maintenance.php`, immediately after line 76 (`require_once LSM_PLUGIN_DIR . 'includes/class-lsm-support.php';`) add:

```php
        require_once LSM_PLUGIN_DIR . 'includes/class-lsm-ticket-types.php';
```

- [ ] **Step 4: Verify it loads (no fatal)**

Run: `cd lsm-wp/landeseiten-maintenance && php -l includes/class-lsm-ticket-types.php`
Expected: `No syntax errors detected`.
Then in wp-env: `npx @wordpress/env run cli wp eval 'var_export(array_keys(LSM_Ticket_Types::types()));'`
Expected: prints `array ( 0 => 'bug', 1 => 'content', 2 => 'design', 3 => 'feature', 4 => 'question', )`.

- [ ] **Step 5: Commit**

```bash
git add includes/class-lsm-ticket-types.php landeseiten-maintenance.php
git commit -m "feat(tickets): single source of truth for types, priorities, icons"
```

---

## Task 3: lsm-wp — shared `ticket-ui.css` + enqueue

**Files:**
- Create: `lsm-wp/landeseiten-maintenance/assets/css/ticket-ui.css`
- Modify: `landeseiten-maintenance.php` — enqueue at `enqueue_ticket_widget()` (~446-453), `enqueue_admin_assets()` (~412-430), and the `wp_print_styles([...])` in `render_ticket_widget_root_admin()` (~535); bump `LSM_VERSION` (line 22)

**Interfaces:**
- Produces: CSS classes `.lsm-tc-grid`, `.lsm-tc-card`, `.lsm-tc-ic`, `.lsm-tc-label`, `.lsm-tc-seg`, `.lsm-tc-seg-opt`, plus native-radio variants `.lsm-tc-radios`/`.lsm-tc-radio`, `.lsm-tc-seg-radio`, under the wrapper `.lsm-ticket-ui` / `#lsm-ticket-widget-root`. Style handle `lsm-ticket-ui`.

- [ ] **Step 1: Create `assets/css/ticket-ui.css`**

```css
/* LSM shared ticket UI — design tokens + type-card / priority-segment components.
   Loaded by both the flying widget and the wp-admin support form. */
#lsm-ticket-widget-root, .lsm-ticket-ui {
  --lsm-accent: #7C3AED;
  --lsm-accent-soft: rgba(124, 58, 237, .08);
  --lsm-accent-ring: rgba(124, 58, 237, .20);
  --lsm-border: #dcdcde;
  --lsm-text: #1d2327;
  --lsm-muted: #646970;
}

/* ---- type cards (JS widget builds <button>; admin uses native radios) ---- */
.lsm-tc-grid,
.lsm-tc-radios { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin: 4px 0; }

.lsm-tc-card {
  display: flex; flex-direction: column; align-items: center; gap: 6px;
  padding: 12px 8px; background: #fff; color: var(--lsm-text);
  border: 1px solid var(--lsm-border); border-radius: 10px; cursor: pointer;
  font: inherit; text-align: center;
  transition: border-color .12s, background .12s, box-shadow .12s;
}
.lsm-tc-card:hover { border-color: var(--lsm-accent); }
.lsm-tc-card:focus-visible { outline: none; border-color: var(--lsm-accent); box-shadow: 0 0 0 4px var(--lsm-accent-ring); }
.lsm-tc-card.selected { border-color: var(--lsm-accent); background: var(--lsm-accent-soft); box-shadow: inset 0 0 0 1px var(--lsm-accent); }

.lsm-tc-ic { color: var(--lsm-muted); line-height: 0; }
.lsm-tc-ic svg { width: 22px; height: 22px; display: block; }
.lsm-tc-card.selected .lsm-tc-ic { color: var(--lsm-accent); }
.lsm-tc-label { font-size: 12px; font-weight: 600; line-height: 1.2; }
@media (max-width: 360px) { .lsm-tc-grid, .lsm-tc-radios { grid-template-columns: repeat(2, 1fr); } }

/* native-radio card variant (admin form, no JS) */
.lsm-tc-radio { position: relative; }
.lsm-tc-radio input { position: absolute; inset: 0; margin: 0; opacity: 0; cursor: pointer; }
.lsm-tc-radio > span {
  display: flex; flex-direction: column; align-items: center; gap: 6px;
  padding: 12px 8px; background: #fff; border: 1px solid var(--lsm-border);
  border-radius: 10px; text-align: center;
}
.lsm-tc-radio input:hover + span { border-color: var(--lsm-accent); }
.lsm-tc-radio input:focus-visible + span { border-color: var(--lsm-accent); box-shadow: 0 0 0 4px var(--lsm-accent-ring); }
.lsm-tc-radio input:checked + span { border-color: var(--lsm-accent); background: var(--lsm-accent-soft); box-shadow: inset 0 0 0 1px var(--lsm-accent); }
.lsm-tc-radio input:checked + span .lsm-tc-ic { color: var(--lsm-accent); }

/* ---- priority segmented control ---- */
.lsm-tc-seg { display: inline-flex; gap: 4px; padding: 3px; background: #f0f0f1; border-radius: 10px; }
.lsm-tc-seg-opt, .lsm-tc-seg-radio > span {
  border: 0; background: transparent; color: var(--lsm-muted);
  font: inherit; font-weight: 600; font-size: 13px;
  padding: 6px 14px; border-radius: 8px; cursor: pointer; display: block;
}
.lsm-tc-seg-opt:focus-visible { outline: none; box-shadow: 0 0 0 3px var(--lsm-accent-ring); }
.lsm-tc-seg-opt.selected { background: #fff; color: var(--lsm-accent); box-shadow: 0 1px 2px rgba(0, 0, 0, .12); }
.lsm-tc-seg-radio { position: relative; }
.lsm-tc-seg-radio input { position: absolute; inset: 0; margin: 0; opacity: 0; cursor: pointer; }
.lsm-tc-seg-radio input:checked + span { background: #fff; color: var(--lsm-accent); box-shadow: 0 1px 2px rgba(0, 0, 0, .12); }
```

- [ ] **Step 2: Register + enqueue the style.** In `enqueue_ticket_widget()` (before the `lsm-ticket-widget` style enqueue at ~line 451) add:

```php
        wp_enqueue_style('lsm-ticket-ui', LSM_PLUGIN_URL . 'assets/css/ticket-ui.css', [], LSM_VERSION);
```

In `enqueue_admin_assets()` (near the `lsm-admin` style enqueue ~line 415-420) add the same line so the admin form gets it:

```php
        wp_enqueue_style('lsm-ticket-ui', LSM_PLUGIN_URL . 'assets/css/ticket-ui.css', [], LSM_VERSION);
```

In `render_ticket_widget_root_admin()`, extend the existing `wp_print_styles(['lsm-ticket-widget'])` (~line 535) to:

```php
        wp_print_styles(['lsm-ticket-ui', 'lsm-ticket-widget']);
```

- [ ] **Step 3: Bump the version.** In `landeseiten-maintenance.php:22`:

```php
define('LSM_VERSION', '2.9.0');
```

- [ ] **Step 4: Verify the stylesheet loads.**

Run: `cd lsm-wp/landeseiten-maintenance && php -l landeseiten-maintenance.php` → `No syntax errors detected`.
In wp-env: open the plugin admin page and a front-end page as admin, View Source, confirm `assets/css/ticket-ui.css?ver=2.9.0` is present in both.

- [ ] **Step 5: Commit**

```bash
git add assets/css/ticket-ui.css landeseiten-maintenance.php
git commit -m "feat(tickets): shared ticket-ui.css component styles + enqueue"
```

---

## Task 4: lsm-wp — widget type picker → icon cards

**Files:**
- Modify: `landeseiten-maintenance.php` — the `wp_localize_script('lsm-ticket-widget', ...)` block (~456-509): replace `i18n.types`, add top-level `types`, add `i18n.type` already exists
- Modify: `assets/js/ticket-widget.js` — `viewNewTicket` (330-427), draft init (20), submit (359-396)

**Interfaces:**
- Consumes: `LSM_Ticket_Types::types()`/`icon()` (Task 2), `.lsm-tc-*` styles (Task 3).
- Produces: `cfg.types` = ordered array `[{code,label,icon}]` delivered to the widget; the widget writes the chosen code to `state.draft.type` and submits it as `issue_type` (unchanged field name).

- [ ] **Step 1: Localize the type data.** In `landeseiten-maintenance.php`, replace the `'types' => [ ... ]` block (lines 500-507, inside `'i18n'`) — remove it from `i18n` and instead add a top-level `types` key to the localized array (sibling of `i18n`, e.g. right after `'pageUrl' => ...`):

```php
            'types' => array_map(
                function ($code, $t) {
                    return ['code' => $code, 'label' => $t['label'], 'icon' => LSM_Ticket_Types::icon($t['icon'])];
                },
                array_keys(LSM_Ticket_Types::types()),
                array_values(LSM_Ticket_Types::types())
            ),
```

Keep the existing `'type' => __('Type', ...)` entry inside `i18n` (it labels the picker).

- [ ] **Step 2: Update the widget draft default.** In `assets/js/ticket-widget.js:20` change:

```js
    draft: { type: 'bug', subject: '', message: '', priority: 'normal' }, // survives re-renders (e.g. screenshot capture)
```

- [ ] **Step 3: Replace the type `<select>` with an icon-card group.** In `viewNewTicket`, delete the `typeSel` block (lines 348-352) and insert this builder in its place:

```js
    // Type picker — icon cards (accessible radiogroup)
    var typeGrid = el('div', { class: 'lsm-tc-grid lsm-ticket-ui', role: 'radiogroup', 'aria-label': cfg.i18n.type });
    (cfg.types || []).forEach(function (t) {
      var card = el('button', {
        type: 'button',
        class: 'lsm-tc-card' + (state.draft.type === t.code ? ' selected' : ''),
        role: 'radio',
        'aria-checked': state.draft.type === t.code ? 'true' : 'false',
        onclick: function () {
          state.draft.type = t.code;
          Array.prototype.forEach.call(typeGrid.querySelectorAll('.lsm-tc-card'), function (c) {
            var on = c === card;
            c.classList.toggle('selected', on);
            c.setAttribute('aria-checked', on ? 'true' : 'false');
          });
        },
      });
      var ic = el('span', { class: 'lsm-tc-ic' });
      ic.innerHTML = t.icon; // static trusted SVG shipped by the plugin
      card.appendChild(ic);
      card.appendChild(el('span', { class: 'lsm-tc-label', text: t.label }));
      typeGrid.appendChild(card);
    });
```

- [ ] **Step 4: Submit the chosen type from the draft.** In the submit handler, change line 370 from `fd.append('issue_type', typeSel.value);` to:

```js
      fd.append('issue_type', state.draft.type);
```

And in the success reset (line 389) change to:

```js
          state.draft = { type: 'bug', subject: '', message: '', priority: 'normal' };
```

- [ ] **Step 5: Mount the grid.** In the body layout (lines 414-415), replace:

```js
    body.appendChild(el('label', { text: cfg.i18n.type }));
    body.appendChild(typeSel);
```

with:

```js
    body.appendChild(el('label', { text: cfg.i18n.type }));
    body.appendChild(typeGrid);
```

- [ ] **Step 6: Verify in wp-env.** Open a front-end page as admin, click the FAB → **New Ticket**. Expected: a 3-column grid of 5 cards (Bug / Content / Design / Feature / Question) with crisp monochrome line icons; clicking a card selects it (accent border + soft fill + accent icon), only one selected; no emoji anywhere. Fill subject/message, submit; in the browser Network tab the `lsm_submit_support` POST carries `issue_type=<selected>`.

- [ ] **Step 7: Commit**

```bash
git add landeseiten-maintenance.php assets/js/ticket-widget.js
git commit -m "feat(widget): icon-card type picker, drop emoji dropdown"
```

---

## Task 5: lsm-wp — widget priority control + server forwards `reported_priority` + de-emoji emails

**Files:**
- Modify: `landeseiten-maintenance.php` — localize block: add `priorities`, `defaultPriority`, and `i18n.priority`
- Modify: `assets/js/ticket-widget.js` — `viewNewTicket` (add priority segment), submit (append `priority`)
- Modify: `includes/class-lsm-support.php` — `handle_submit` (read/validate priority, forward as `reported_priority`, de-emoji labels, store priority)
- Modify: `includes/class-lsm-ticket-client.php:173` (docblock only)

**Interfaces:**
- Consumes: Task 1 API (`reported_priority`), Task 2 `LSM_Ticket_Types::priorities()/default_priority()/type_label()`.
- Produces: widget submits `priority` ∈ `{normal,high,urgent}`; `handle_submit` forwards it to the API as `reported_priority` and stores it locally.

- [ ] **Step 1: Localize priority data.** In `landeseiten-maintenance.php`, next to the `types` key added in Task 4, add:

```php
            'priorities' => array_map(
                function ($code, $p) { return ['code' => $code, 'label' => $p['label']]; },
                array_keys(LSM_Ticket_Types::priorities()),
                array_values(LSM_Ticket_Types::priorities())
            ),
            'defaultPriority' => LSM_Ticket_Types::default_priority(),
```

And inside `i18n` add:

```php
                'priority'        => __('Priority', 'landeseiten-maintenance'),
```

- [ ] **Step 2: Build the priority segment.** In `viewNewTicket` (after the `typeGrid` builder from Task 4) add:

```js
    // Priority — segmented control (accessible radiogroup)
    var priSeg = el('div', { class: 'lsm-tc-seg lsm-ticket-ui', role: 'radiogroup', 'aria-label': cfg.i18n.priority });
    (cfg.priorities || []).forEach(function (p) {
      var opt = el('button', {
        type: 'button',
        class: 'lsm-tc-seg-opt' + (state.draft.priority === p.code ? ' selected' : ''),
        role: 'radio',
        'aria-checked': state.draft.priority === p.code ? 'true' : 'false',
        text: p.label,
        onclick: function () {
          state.draft.priority = p.code;
          Array.prototype.forEach.call(priSeg.querySelectorAll('.lsm-tc-seg-opt'), function (o) {
            var on = o === opt;
            o.classList.toggle('selected', on);
            o.setAttribute('aria-checked', on ? 'true' : 'false');
          });
        },
      });
      priSeg.appendChild(opt);
    });
```

- [ ] **Step 3: Mount the priority segment.** In the body layout, right after the two lines that append the type label + `typeGrid` (from Task 4 Step 5), add:

```js
    body.appendChild(el('label', { text: cfg.i18n.priority }));
    body.appendChild(priSeg);
```

- [ ] **Step 4: Send priority in the submit FormData.** In the submit handler, right after the `fd.append('issue_type', state.draft.type);` line, add:

```js
      fd.append('priority', state.draft.priority);
```

- [ ] **Step 5: Handle priority server-side.** In `includes/class-lsm-support.php::handle_submit`, after line 45 (`$site_url = ...`) add:

```php
        $priority = sanitize_text_field($_POST['priority'] ?? LSM_Ticket_Types::default_priority());
        if (!array_key_exists($priority, LSM_Ticket_Types::priorities())) {
            $priority = LSM_Ticket_Types::default_priority();
        }
```

- [ ] **Step 6: De-emoji the email labels.** In the same method, replace the `$issue_labels` array (lines 56-63) and its two usages (lines 68 and 88). Delete the array, then change line 68 from `$issue_labels[$issue_type] ?? $issue_type,` to:

```php
            LSM_Ticket_Types::type_label($issue_type),
```

and line 88 identically:

```php
            LSM_Ticket_Types::type_label($issue_type),
```

- [ ] **Step 7: Forward + store priority.** Add `'reported_priority' => $priority,` to the `$ticket_fields` array (after line 108 `'problem_page' => $problem_page,`):

```php
            'reported_priority' => $priority,
```

And add `'priority' => $priority,` to the `store_request` array (after line 122 `'type' => $issue_type,`):

```php
            'priority'   => $priority,
```

- [ ] **Step 8: Update the ticket-client docblock.** In `includes/class-lsm-ticket-client.php`, update the `create_ticket` docblock (~line 173) to note the accepted fields now include `reported_priority`. No logic change — `post_multipart` already iterates all `$fields`.

```php
     * @param array $fields Ticket fields (type, subject, message, client_email,
     *                       client_name, problem_page, reported_priority).
```

- [ ] **Step 9: Verify end-to-end in wp-env.** Reopen the widget → New Ticket. Expected: below the type cards, a "Priority" segment with Normal / High / Urgent, **Normal** preselected; selecting toggles the pill. Submit; the `lsm_submit_support` POST carries `priority=<code>`. With lsm-api running locally, confirm the created ticket's severity: `Normal→medium`, `High→high`, `Urgent→critical`; and the notification email subject has **no emoji** (e.g. `[host] Bug / Error: <subject>`).

- [ ] **Step 10: Commit**

```bash
git add landeseiten-maintenance.php assets/js/ticket-widget.js includes/class-lsm-support.php includes/class-lsm-ticket-client.php
git commit -m "feat(widget): client priority control; forward reported_priority; de-emoji emails"
```

---

## Task 6: lsm-wp — FAB redesign (white brand mark + theme-hardened CSS)

**Files:**
- Modify: `assets/js/ticket-widget.js` — add `LSM_FAB_ICON`, use it in `render()` (line 544)
- Modify: `assets/css/ticket-widget.css:3-10` — replace `.lsm-tw-fab` rules

**Interfaces:**
- Consumes: nothing new.
- Produces: a high-contrast white lightbulb FAB whose styling resists theme overrides.

- [ ] **Step 1: Add a white FAB glyph.** In `assets/js/ticket-widget.js`, just after the `LSM_LOGO_SVG` definition (line 25), add:

```js
  // High-contrast white lightbulb for the floating button (the detailed brand
  // mark washes out on the purple circle). Static trusted markup.
  var LSM_FAB_ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/></svg>';
```

- [ ] **Step 2: Use it in the FAB.** In `render()` change line 544 from `fab.innerHTML = LSM_LOGO_SVG;` to:

```js
    fab.innerHTML = LSM_FAB_ICON;
```

- [ ] **Step 3: Harden the FAB CSS.** In `assets/css/ticket-widget.css`, replace the `.lsm-tw-fab` and `.lsm-tw-fab:hover` and `.lsm-tw-fab svg` rules (lines 3-10) with:

```css
#lsm-ticket-widget-root .lsm-tw-fab {
  -webkit-appearance: none; appearance: none; font: inherit; margin: 0;
  box-sizing: border-box; line-height: 1;
  position: fixed; right: 24px; bottom: 24px; z-index: 99998;
  width: 56px; height: 56px; border-radius: 50% !important; border: 0 !important;
  cursor: pointer; background: var(--lsm-accent) !important; color: #fff !important;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 6px 18px rgba(124, 58, 237, .35), 0 2px 6px rgba(0, 0, 0, .2);
  transition: transform .15s ease, box-shadow .15s ease;
}
#lsm-ticket-widget-root .lsm-tw-fab:hover {
  transform: translateY(-2px);
  box-shadow: 0 10px 24px rgba(124, 58, 237, .45), 0 3px 8px rgba(0, 0, 0, .25);
}
#lsm-ticket-widget-root .lsm-tw-fab svg { width: 26px; height: 26px; display: block; }
```

- [ ] **Step 4: Bump the version** (asset change) — `landeseiten-maintenance.php:22` → `define('LSM_VERSION', '2.9.1');`

- [ ] **Step 5: Verify on a real theme.** In wp-env, view the front-end as admin with the default theme active, then switch to another theme (e.g. a heavy commercial-style theme if available). Expected in both: a solid purple circle, bottom-right, with a clearly visible **white** lightbulb, soft layered shadow, and a slight lift on hover — never a square/washed-out/site-colored default button. Confirm the unread badge still overlays correctly.

- [ ] **Step 6: Commit**

```bash
git add assets/js/ticket-widget.js assets/css/ticket-widget.css landeseiten-maintenance.php
git commit -m "fix(widget): high-contrast white FAB icon + theme-hardened styles"
```

---

## Task 7: lsm-wp — widget visual polish

**Files:**
- Modify: `assets/css/ticket-widget.css` — header, tabs, inputs, buttons, thread bubbles, states
- Modify: `landeseiten-maintenance.php:22` — bump version

**Interfaces:**
- Consumes: tokens from `ticket-ui.css` (Task 3).
- Produces: no markup/JS contract changes — purely visual refinement of existing `.lsm-tw-*` classes.

- [ ] **Step 1: Refine the panel/header/tabs/inputs/buttons/thread.** In `assets/css/ticket-widget.css`, apply these replacements (keep everything else):

Header (replace line 22):

```css
.lsm-tw-header { background: linear-gradient(135deg, #7C3AED 0%, #6D28D9 100%); color: #fff; padding: 14px 16px; display: flex; justify-content: space-between; align-items: center; }
```

Tabs active state (replace line 26):

```css
.lsm-tw-tabs button.active { background: #fff; border-bottom: 2px solid var(--lsm-accent); color: var(--lsm-accent); }
.lsm-tw-tabs button:focus-visible { outline: none; box-shadow: inset 0 0 0 2px var(--lsm-accent-ring); }
```

Inputs — canonical 2px/10px/accent-ring (replace lines 29-31):

```css
.lsm-tw-body input[type=text], .lsm-tw-body select, .lsm-tw-body textarea {
  width: 100%; padding: 9px 10px; border: 1px solid #c3c4c7; border-radius: 10px;
  box-sizing: border-box; background: #fff; color: inherit; font: inherit;
  transition: border-color .12s, box-shadow .12s;
}
.lsm-tw-body input[type=text]:focus, .lsm-tw-body select:focus, .lsm-tw-body textarea:focus {
  outline: none; border-color: var(--lsm-accent); box-shadow: 0 0 0 4px var(--lsm-accent-ring);
}
```

Buttons (replace lines 32-34):

```css
.lsm-tw-btn { background: var(--lsm-accent); color: #fff; border: 0; border-radius: 10px; padding: 10px 16px; cursor: pointer; font-weight: 600; margin-top: 12px; transition: filter .12s, transform .05s; }
.lsm-tw-btn:hover { filter: brightness(1.06); }
.lsm-tw-btn:active { transform: translateY(1px); }
.lsm-tw-btn[disabled] { opacity: .6; cursor: wait; }
.lsm-tw-btn-secondary { background: #f0f0f1; color: #1d2327; }
.lsm-tw-btn-secondary:hover { background: #e5e5e7; filter: none; }
```

Thread bubbles — add an author-initial affordance and tighten (append at end of file):

```css
.lsm-tw-msg { border: 1px solid transparent; }
.lsm-tw-msg-staff { background: #ede9fe; }
.lsm-tw-msg .lsm-tw-msg-author { font-weight: 600; }
.lsm-tw-empty svg { width: 40px; height: 40px; color: var(--lsm-muted); }
```

- [ ] **Step 2: Bump the version** — `landeseiten-maintenance.php:22` → `define('LSM_VERSION', '2.9.2');`

- [ ] **Step 3: Verify.** In wp-env, open the widget: gradient header, clearly-active tab, inputs with accent focus ring, primary/secondary buttons with hover/active feedback, staff replies in a violet bubble. Nothing overlaps; panel still scrolls; mobile (`max-width:480px`) still docks full-width.

- [ ] **Step 4: Commit**

```bash
git add assets/css/ticket-widget.css landeseiten-maintenance.php
git commit -m "style(widget): polished header, tabs, inputs, buttons, thread"
```

---

## Task 8: lsm-wp — admin dashboard form parity

**Files:**
- Modify: `admin/class-lsm-admin.php:262-273` — replace type `<select>` with radio cards; add a priority segment
- Modify: `admin/js/admin.js:94-103` — read checked type radio; send `priority`

**Interfaces:**
- Consumes: `LSM_Ticket_Types` (Task 2), `.lsm-tc-*` native-radio styles (Task 3), server `handle_submit` priority handling (Task 5).
- Produces: the admin form submits `issue_type` (from a checked radio) + `priority`.

- [ ] **Step 1: Replace the type select + add priority.** In `admin/class-lsm-admin.php`, replace the whole Issue-Type `lsm-form-group` (lines 262-273) with:

```php
                                <div class="lsm-form-group">
                                    <label><?php _e('Issue Type', 'landeseiten-maintenance'); ?></label>
                                    <div class="lsm-tc-radios lsm-ticket-ui">
                                        <?php foreach (LSM_Ticket_Types::types() as $code => $t) : ?>
                                            <label class="lsm-tc-radio">
                                                <input type="radio" name="issue_type" value="<?php echo esc_attr($code); ?>" required>
                                                <span>
                                                    <span class="lsm-tc-ic"><?php echo LSM_Ticket_Types::icon($t['icon']); // static trusted SVG ?></span>
                                                    <span class="lsm-tc-label"><?php echo esc_html($t['label']); ?></span>
                                                </span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="lsm-form-group">
                                    <label><?php _e('Priority', 'landeseiten-maintenance'); ?></label>
                                    <div class="lsm-tc-seg lsm-ticket-ui" role="radiogroup">
                                        <?php foreach (LSM_Ticket_Types::priorities() as $code => $p) : ?>
                                            <label class="lsm-tc-seg-radio">
                                                <input type="radio" name="priority" value="<?php echo esc_attr($code); ?>" <?php checked($code, LSM_Ticket_Types::default_priority()); ?>>
                                                <span><?php echo esc_html($p['label']); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
```

- [ ] **Step 2: Send the new fields.** In `admin/js/admin.js`, change the `issue_type` line (96) and add a `priority` line inside the `formData` object (94-103):

```js
                issue_type: $form.find('[name="issue_type"]:checked').val(),
                priority: $form.find('[name="priority"]:checked').val(),
```

- [ ] **Step 3: Verify.** In wp-admin → the plugin page → **Contact Support** card. Expected: the icon-card grid (same look as the widget) + a Normal/High/Urgent segment (Normal checked). Selecting a type is required to submit. Submit a test ticket; the `admin-ajax` POST carries `issue_type` + `priority`; with local API the ticket severity matches the mapping.

- [ ] **Step 4: Commit**

```bash
git add admin/class-lsm-admin.php admin/js/admin.js
git commit -m "feat(admin): icon-card type picker + priority on the support form"
```

---

## Task 9: lsm-wp — remove dead support-modal code

**Files:**
- Modify: `landeseiten-maintenance.php` — delete `render_support_modal()`, `enqueue_frontend_assets()`, `add_support_button()` (unhooked)
- Delete: `assets/css/support.css`, `assets/js/support.js`

**Interfaces:**
- Consumes: nothing.
- Produces: smaller surface; no behavior change (these are not hooked/enqueued).

- [ ] **Step 1: Confirm they are truly unreferenced.**

Run:
```bash
cd lsm-wp/landeseiten-maintenance
grep -rn "render_support_modal\|enqueue_frontend_assets\|add_support_button\|support\.css\|support\.js" --include=*.php --include=*.js .
```
Expected: matches only inside the definitions themselves (no `add_action`/`add_filter`/`wp_enqueue_*` wiring). If any real hook shows up, STOP and leave the file in place.

- [ ] **Step 2: Delete the methods and files.** Remove the three method definitions from `landeseiten-maintenance.php` (including the `render_support_modal()` markup block that contains `#lsm-support-modal`, lines ~287-360), then:

```bash
git rm assets/css/support.css assets/js/support.js
```

- [ ] **Step 3: Verify no fatals + no lost UI.**

Run: `php -l landeseiten-maintenance.php` → `No syntax errors detected`.
In wp-env: plugin page + front-end still load; the flying widget and admin form are unaffected (they never used the modal).

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "chore(tickets): remove dead support-modal code and assets"
```

---

## Task 10 (optional): lsm-api — legacy webhook priority parity

Only do this if older plugin builds still POST to `/api/v1/webhooks/support-ticket` **and** you want their (future) `reported_priority` honored. The current legacy path derives severity from type and is unchanged by Tasks 1-9; new plugin builds use `/plugin/support-tickets`.

**Files:**
- Modify: `lsm-api/app/Http/Controllers/Api/V1/SupportTicketController.php:291-300,338-356`
- Test: `lsm-api/tests/Feature/PluginTicketEndpointsTest.php`

- [ ] **Step 1:** Mirror Task 1's validation + `match` in `receiveFromPlugin` (add `'reported_priority' => 'nullable|in:normal,high,urgent'`, seed `priority` with the same fallback).
- [ ] **Step 2:** Add a Pest test posting `reported_priority` to the webhook and asserting the seeded severity.
- [ ] **Step 3:** `./vendor/bin/pest tests/Feature/PluginTicketEndpointsTest.php` → PASS.
- [ ] **Step 4:** Commit `feat(tickets): legacy webhook honors reported_priority`.

---

## lsm-web — no required changes

Priority is already displayed (list columns) and editable (detail modal) using the staff scale; new tickets arrive with a client-seeded severity and render as today. `type` labels still cover all six values (historical `urgent` renders). **Optional future polish (out of scope):** de-emoji `TICKET_TYPE_LABELS` in `lsm-web/src/lib/support-tickets-api.ts:171-178`. Do **not** narrow the TS `type`/`priority` unions — historical values must keep rendering.

---

## Self-review (completed against the spec)

- **§Shared visual system** → Task 3 (`ticket-ui.css` tokens + components). ✓
- **§Type model single source of truth** → Task 2 (`LSM_Ticket_Types`); consumed in Tasks 4/5/8. ✓
- **§Type-picker icon cards** → Task 4 (widget), Task 8 (admin, native-radio no-JS variant). ✓
- **§FAB redesign** → Task 6 (white glyph + hardened CSS + theme verification). ✓
- **§Full widget polish** → Task 7. ✓
- **§Admin form alignment** → Task 8. ✓
- **§Type vs priority split / seed existing severity** → Task 1 (API map + fallback), Task 5 (widget + server forward), Task 8 (admin). No new column/migration. ✓
- **§`urgent` kept in DB/allowlist, dropped from pickers** → Task 1 keeps the `type` allowlist incl. `urgent`; Task 2 omits it from `types()`. ✓
- **§lsm-web no required changes** → documented. ✓
- **§Dead-code cleanup** → Task 9. ✓
- **Type/name consistency:** `reported_priority` (API + plugin forward), `priority` (admin-ajax field + local store), `LSM_Ticket_Types::{types,priorities,default_priority,type_label,icon,icons}`, `.lsm-tc-*` classes, `LSM_FAB_ICON` — all used consistently across tasks. ✓
- **Placeholder scan:** none — every step has concrete code/commands. ✓
