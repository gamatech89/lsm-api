# Ticket UI Redesign — Design Spec

**Date:** 2026-07-22
**Scope:** lsm-wp (primary), lsm-api (small), lsm-web (none required). Branch `feature/ticket-ui-redesign` in each touched repo.
**Goal:** Replace the amateurish emoji-in-`<select>` ticket UI with a professional, cohesive design: an icon-card type picker, a redesigned floating button, a full visual polish of the widget, and the same visual language on the wp-admin form. Additionally, split ticket **type** from a new client-facing **priority** (Normal/High/Urgent), reusing the existing severity field on the platform.

## Background (current state)

Ticket creation in the plugin lives on two live surfaces (a third is dead code):

- **Flying widget** — [`assets/js/ticket-widget.js`](../../../../lsm-wp/landeseiten-maintenance/assets/js/ticket-widget.js). Type is a native `<select>` whose options carry emoji baked into the text (🐛 📝 🎨 ✨ ❓ 🚨), localized in `landeseiten-maintenance.php:500-507`. The floating button (FAB) uses the brand "lightbulb" SVG, but its fills are near-white pastels on a purple circle → very low contrast → reads as *no icon*, like a default themed button.
- **Admin dashboard form** — `admin/class-lsm-admin.php:264-272`. Another emoji `<select>`; submitted via `admin/js/admin.js`.
- **Dead:** `render_support_modal()` + `assets/css/support.css` + `assets/js/support.js` are defined but **not hooked** anywhere (`enqueue_frontend_assets`, `add_support_button` are never `add_action`-ed). Not enqueued, not rendered. Ignore / remove.

Root cause of the "amateurish" feel: **native `<select>` cannot be styled**, and **emoji render inconsistently across OSes** and look pasted-on. Types are also defined in **three inconsistent places** (widget i18n, admin `<select>`, email labels), so nothing is a single source of truth.

Platform side (discovered, important):

- `support_tickets` already has a **`priority`** column — enum `low/medium/high/critical`, default `medium`, **auto-derived from type** in the controllers (`urgent→critical`, `bug→high`, else `medium`). It is staff-internal severity, never chosen by the client. (`PluginTicketController.php:99-103`, `SupportTicketController.php:338-343`.)
- lsm-web **already displays and edits** this priority: a "Priority" column in both list views and an editable `Select` in the detail modal (`support-tickets-api.ts:189-194`, `TicketDetailModal.tsx:292-303`).
- Ticket `type` enum in the DB is `bug,content,design,feature,question,urgent`. The plugin submit path sends `type` but **no allowlist** and **no priority** today (`class-lsm-support.php:47,102-109`).

## Design decisions (locked)

1. **Type picker:** clickable **icon cards** (radio group) with clean SVG line icons — replaces the emoji `<select>` on both live surfaces.
2. **Icon treatment:** **monochrome** icons at rest; the selected card gets the brand accent (border + soft fill + accent icon + check). Calm, professional, not "colorful."
3. **FAB:** keep the brand lightbulb but render it in **solid white** (high contrast) + hardened CSS so the theme can't override it.
4. **Depth:** full widget polish (header, inputs, buttons, tabs, thread bubbles, states), driven by a shared stylesheet.
5. **Type vs priority:** **split**. Picker offers **5 types** (`bug, content, design, feature, question`); a separate **Priority** control offers **Normalno / Visoko / Hitno**.
6. **Priority reconciliation:** the client's choice **seeds the existing `priority`** — `Normalno→medium`, `Visoko→high`, `Hitno→critical`. Staff re-triage in lsm-web as today. **No new column, no migration.**
7. **`urgent` type:** kept in the DB enum and email-label fallback for **historical** tickets, but **removed from all pickers**. New tickets never use it.

## 1. Shared visual system (design tokens)

A new stylesheet **`assets/css/ticket-ui.css`** holds tokens + the shared `.lsm-tc-*` component classes, enqueued by both the widget and the admin page (enqueue points in §7). CSS custom properties scoped under a wrapper (`#lsm-ticket-widget-root`, `.lsm-ticket-ui`):

- `--lsm-accent: #7C3AED` (existing brand purple), `--lsm-accent-soft` (~8% tint), `--lsm-accent-ring`.
- Neutrals: surface `#fff`, border `#e2e8f0` / `#dcdcde`, text `#1d2327`, muted `#646970`.
- Radius scale (`--r-sm: 8px`, `--r-md: 10px`, `--r-lg: 12px`), focus ring (`0 0 0 4px var(--lsm-accent-ring)`), one shadow scale.

Adopt the cleaner input language the (dead) modal already used — 2px border, 10px radius, accent focus ring (`support.css:162-181`) — as the canonical form styling.

## 2. Ticket type model — single source of truth (lsm-wp)

Define types **once** in PHP and derive everything else from it. New static provider, e.g. `LSM_Support::issue_types()`:

```
'bug'      => [ 'label' => 'Bug / Error',      'icon' => 'bug' ],
'content'  => [ 'label' => 'Content Change',   'icon' => 'file-text' ],
'design'   => [ 'label' => 'Design Change',    'icon' => 'palette' ],
'feature'  => [ 'label' => 'New Feature',      'icon' => 'sparkles' ],
'question' => [ 'label' => 'Question',         'icon' => 'help-circle' ],
```

Consumers:
- **Widget:** passed to JS via `wp_localize_script` (replaces `i18n.types` at `landeseiten-maintenance.php:500-507`), including the icon SVG strings (see §3).
- **Admin form:** rendered directly in PHP (`class-lsm-admin.php:264-272`).
- **Email labels:** replace the emoji map at `class-lsm-support.php:56-63` with labels from this provider (emoji removed). Keep `?? $issue_type` fallback so a historical `urgent` still renders.

Icons are **inline SVG line icons** (Lucide-style, 24×24, `stroke-width:2`, `stroke:currentColor`) defined **once** in PHP as an `icon-key → svg-string` map (same static-trusted-markup pattern as the existing `LSM_LOGO_SVG`), exposed to JS via localize. No icon fonts, no external SVGs, no CDN (keeps the plugin CSP/Wordfence-clean — see [[project-remote-malware-scanner]]).

## 3. Type-picker component — icon cards

Shared markup/behavior across widget (JS-built) and admin form (PHP-built), same `.lsm-tc-*` classes:

- A `role="radiogroup"` grid (2–3 columns, wraps) of cards. Each card = `role="radio"`, `aria-checked`, `tabindex` roving, keyboard arrows + Space/Enter, backed by a visually-hidden native `<input type="radio">` for form semantics and no-JS fallback on the admin form.
- Card content: monochrome SVG icon + type label (short description optional, omit for compactness).
- **States:** rest = 1px neutral border; **hover/focus** = accent border + ring; **selected** = accent border + `--lsm-accent-soft` fill + accent-colored icon + small check in the corner.
- Selected value drives the hidden `type` field. Draft persistence in the widget (`state.draft.type`) keeps working across re-renders (screenshot capture).

Same pattern renders the **Priority** control as a compact 3-option segmented/radio row (Normalno / Visoko / Hitno), default **Normalno**.

## 4. FAB redesign (fix "no icon / default button")

In `assets/css/ticket-widget.css` (`.lsm-tw-fab`) and the SVG:

- Render the brand lightbulb in **solid white** (`fill: #fff`, drop the pastel gradients for the FAB variant) at a size that fills the circle cleanly; keep the badge.
- **Harden against theme overrides:** explicit resets on the button (`appearance: none; font: inherit; border: 0; box-sizing: border-box; line-height: 1;`), keep styles scoped under `#lsm-ticket-widget-root`, and use `!important` narrowly on `background`, `color`, `border-radius` where front-end themes are most likely to interfere.
- Depth: layered shadow + subtle ring + small hover-lift. Optional discreet "Support" label/tooltip on hover.

**Verification requirement:** because the trigger for this whole redesign was the FAB looking unstyled on a live site, the plan must include actually rendering the widget (local wp-env and/or a staging site) and confirming the FAB shows the white mark with the intended styling, not a themed default.

## 5. Full widget polish (lsm-wp, `ticket-widget.js` + `ticket-ui.css`)

- **Header:** brand mark + title, subtle accent gradient, tighter spacing, cleaner close button.
- **Inputs/textarea:** canonical form styling from §1 (10px radius, 2px border, accent focus ring).
- **Buttons:** primary (accent), secondary (neutral), explicit hover/disabled/loading states.
- **Tabs (New ticket / My tickets):** clearer active state (underline or pill).
- **Thread bubbles:** refined client/staff bubbles, author initial avatar, readable meta row + status chips.
- **Empty / error / success states:** polished, with an icon for success (mirror `support.css:264-282`).
- Replace `alert()`/`prompt()` UX where it cheapens the feel — inline confirmations/toasts within the panel (keep scope tight; annotation text-prompt may remain for now).

## 6. Admin dashboard form alignment (lsm-wp)

- `class-lsm-admin.php:264-272`: replace the emoji `<select>` with the PHP-rendered icon-card group + the priority row, styled by `ticket-ui.css`.
- `admin/js/admin.js:79-127`: read the selected `type` from the card group and send `type` + `priority` (currently sends neither `problem_page` nor attachments — unchanged; out of scope to add here).

## 7. Shared CSS/JS architecture & enqueue (lsm-wp)

- Register a new style handle `lsm-ticket-ui` → `assets/css/ticket-ui.css`, enqueued in **both**:
  - `enqueue_ticket_widget()` (`landeseiten-maintenance.php:446-453`, front-end widget), and
  - `enqueue_admin_assets()` (`landeseiten-maintenance.php:412-430`, admin pages).
  - Add the handle to the `wp_print_styles([...])` call in `render_ticket_widget_root_admin()` (`landeseiten-maintenance.php:535`) so it loads on the admin footer render too.
- Icon SVG map + type/priority data delivered to the widget via the existing `wp_localize_script('lsm-ticket-widget', 'lsmTicketWidget', ...)` block.
- No build step, no CDN, no external fonts — all assets local (consistent with the plugin's malware-scanner/Wordfence posture).
- Bump `LSM_VERSION`; respect the `build-release.sh` release caveat noted in [[project-advanced-ticketing]] (build zip manually / prune divergent local tags).

## 8. lsm-api changes (small)

Accept a client priority on the plugin create endpoint and use it to seed the existing severity; keep backward compatibility.

- **`PluginTicketController::store`** (`app/Http/Controllers/Api/V1/PluginTicketController.php:86-128`):
  - Add validation: `'reported_priority' => 'nullable|in:normal,high,urgent'` (distinct field name to avoid clashing with the staff `priority` vocabulary).
  - Map to severity: `normal→medium, high→high, urgent→critical`. If `reported_priority` is present, set `priority` from the map; **else** keep the current type-derivation (backward compat for old plugin builds). Replace the reliance on `type==='urgent'` accordingly.
  - `priority` is already serialized in the hand-built index/show arrays — no response change needed.
- **Type allowlist** (`PluginTicketController.php:91`): keep accepting all six values (incl. `urgent`) for backward compatibility with older plugin versions; the new plugin simply never sends `urgent`. No enum/migration change to `support_tickets.type`.
- **Legacy webhook** (`SupportTicketController::receiveFromPlugin:287-382`): apply the same optional `reported_priority` handling **only if** we still support very old plugins posting there; otherwise leave as-is. Decide during planning.
- **Tests:** feature test that `reported_priority` seeds `priority` correctly for each level and that omission falls back to type-derivation.

No new column, no migration, no change to lsm-web's data contract.

## 9. lsm-web (no required changes)

- Priority already displayed/editable; new tickets arrive with a client-seeded `priority` and render as today.
- `type` labels still cover all six values; new tickets use the five, historical `urgent` still renders.
- **Optional future polish (out of scope):** de-emoji the staff-side `TICKET_TYPE_LABELS` (`support-tickets-api.ts:171-178`) for consistency. Not required for this round.

## 10. Cleanup (recommended, low risk)

- Delete the dead `render_support_modal()`, the modal markup, `assets/css/support.css`, `assets/js/support.js`, and the unhooked `enqueue_frontend_assets` / `add_support_button` — nothing references them. Reduces surface and confusion. (Confirm no hidden references before deleting.)

## 11. Testing & verification

1. **Baseline first:** load the current widget locally (wp-env per [[project-advanced-ticketing]]) and confirm today's submit flow works before changing anything.
2. **Visual/interaction:** icon cards render with correct states; keyboard + screen-reader radiogroup semantics; priority row works; draft persists across screenshot capture; admin form no-JS fallback still submits a valid `type`.
3. **FAB:** render on a live/staging theme and confirm the white brand mark + hardened styling (the original complaint) — not a themed default button.
4. **lsm-api:** PHPUnit for `reported_priority` → `priority` mapping and the fallback.
5. **End-to-end:** submit from the widget → ticket appears in lsm-web with the right type + seeded priority; email labels have no emoji. User performs the final production e2e.

## Out of scope (YAGNI)

- New `client_priority` column / preserving client intent separately from staff severity (explicitly rejected in favor of seeding).
- Changing the staff severity scale (stays `low/medium/high/critical`).
- Redesigning the lsm-web staff UI (only the plugin surfaces are in scope); de-emoji there is an optional follow-up.
- Adding attachments/problem-page to the admin dashboard form; ticket merging, SLA, canned responses, non-admin roles.
- Reviving the dead support modal.
