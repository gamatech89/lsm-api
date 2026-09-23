# lsm-wp implementation map: Part 1 of the htaccess-hardening spec (LSM_Hardening, plugin 2.10.0)

- Repo root: `/Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp`
- Plugin dir, written `P/` below: `/Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp/landeseiten-maintenance`
- Spec: `/Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api/docs/superpowers/specs/2026-09-21-htaccess-hardening-design.md`

Line numbers refer to the current working tree. For every file except `includes/class-lsm-actions.php` the working tree is identical to master (see §8).

---

## 1. Class loading

There is no autoloader and no composer. Files are loaded by an explicit `require_once` list in `Landeseiten_Maintenance::includes()`, `P/landeseiten-maintenance.php:68-92`:

```php
    private function includes() {
        // Core classes
        require_once LSM_PLUGIN_DIR . 'includes/class-lsm-logger.php';
        require_once LSM_PLUGIN_DIR . 'includes/class-lsm-health.php';
        ...
        require_once LSM_PLUGIN_DIR . 'includes/class-lsm-media.php';
        require_once LSM_PLUGIN_DIR . 'includes/class-lsm-updater.php';

        // Admin
        if (is_admin()) {
            require_once LSM_PLUGIN_DIR . 'admin/class-lsm-admin.php';
        }
    }
```

Register the new file by adding one line in the "Core classes" block. Put it after `class-lsm-logger.php` (line 70) and before the `// Admin` block, for example right after line 85 or 86:

```php
        require_once LSM_PLUGIN_DIR . 'includes/class-lsm-hardening.php';
```

`includes()` is called from the private constructor (`:57-63`). The constructor runs when `lsm();` executes at the bottom of the main file (`:471`), which is during the plugin include.

Every include file starts with this guard (e.g. `P/includes/class-lsm-logger.php:8-10`). The new file must have it too:

```php
if (!defined('ABSPATH')) {
    exit;
}
```

The unit-test bootstrap must `define('ABSPATH', ...)` before requiring the class. If it does not, the test process exits silently at this guard.

---

## 2. REST

**Namespace constant**, `P/includes/class-lsm-api.php:20`:
```php
    const NAMESPACE = 'lsm/v1';
```

**Registration.** All routes are registered in `LSM_API::register_routes()` (`:25-364`). That method is called from `Landeseiten_Maintenance::init_rest_api()` (`landeseiten-maintenance.php:247-250`), hooked at `:104` with `add_action('rest_api_init', [$this, 'init_rest_api']);`.

**No route declares `args`.** `grep "'args'"` on the file returns zero matches. Validation is done by hand inside each callback. A GET and a POST route on the same path are two separate `register_rest_route` calls. The `/security/settings` pair, `class-lsm-api.php:299-310`:

```php
        // Security Settings
        register_rest_route(self::NAMESPACE, '/security/settings', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_security_settings'],
            'permission_callback' => [$this, 'authenticate'],
        ]);

        register_rest_route(self::NAMESPACE, '/security/settings', [
            'methods'             => 'POST',
            'callback'            => [$this, 'update_security_settings'],
            'permission_callback' => [$this, 'authenticate'],
        ]);
```

Put the four new `/hardening/*` routes next to these. After `/security/headers/snippets` (ends `:324`) or after the scan routes (`:345`) are both reasonable spots.

**`authenticate()` in full**, `class-lsm-api.php:367-401`:

```php
    /**
     * Authenticate API request.
     *
     * @param WP_REST_Request $request Request object.
     * @return bool
     */
    public function authenticate($request) {
        $api_key = Landeseiten_Maintenance::get_setting('api_key');
        if (empty($api_key)) {
            return false;
        }

        // Preferred: X-LSM-Key header (keeps the secret out of URLs/access logs).
        $header_key = $request->get_header('X-LSM-Key');
        if (is_string($header_key) && hash_equals($api_key, $header_key)) {
            return true;
        }

        // Authorization: Bearer <key>
        $auth = $request->get_header('Authorization');
        if ($auth && preg_match('/^Bearer\s+(.+)$/i', $auth, $matches)) {
            if (hash_equals($api_key, $matches[1])) {
                return true;
            }
        }

        // Backwards-compatible: ?key= query param. Deprecated — exposes the key in
        // server logs. Kept so existing platform releases keep working during rollout.
        $key = $request->get_param('key');
        if (is_string($key) && hash_equals($api_key, $key)) {
            return true;
        }

        return false;
    }
```

**`update_security_settings` signature and param handling**, `class-lsm-api.php:1908-1922`:

```php
    /**
     * Update security settings.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function update_security_settings($request) {
        $changes = [];

        // Comments
        if ($request->has_param('comments_enabled')) {
            $enabled = $request->get_param('comments_enabled');
```

It ends at `:1975-1979` with `return rest_ensure_response(['success' => true, 'message' => 'Security settings updated', 'changes' => $changes]);`. `$request` is untyped here. Only `clear_transients(WP_REST_Request $request)` at `:784` uses a type hint.

**Response shapes** (three patterns are in use):

- **Success:** `rest_ensure_response(['success' => true, 'data' => ...])`. Example at `:1178-1183`:
  ```php
      public function enable_maintenance() {
          return rest_ensure_response([
              'success' => true,
              'data'    => LSM_Maintenance_Mode::enable(),
          ]);
      }
  ```
- **Operational failure:** HTTP 200 with `success:false`. Example in `get_security_headers`, `:1998-2004`:
  ```php
          if (is_wp_error($response)) {
              return rest_ensure_response([
                  'success' => false,
                  'message' => 'Could not fetch headers: ' . $response->get_error_message(),
                  'data' => null,
              ]);
          }
  ```
  The backup endpoint passes the inner flag straight through, `:1243-1248`: `'success' => $result['success'], 'data' => $result,`.
- **Bad or missing input and hard failures:** `WP_Error` with a status. Examples: `:669` `return new WP_Error('missing_slug', 'Theme slug is required', ['status' => 400]);` and `:1068-1072` `new WP_Error('protected_plugin', '...', ['status' => 403])`.

**How the platform consumes these responses.** This matters for the response shape. The platform sends JSON with the `X-LSM-Key` header, in `lsm-api/app/Services/LsmService.php:655-672`: `->withHeaders(['X-LSM-Key' => $this->apiKey]) ... ->asJson()`. Its `handleResponse` does three things:

- A non-2xx response returns `null`.
- `{success:true, data:X}` is unwrapped to `X`.
- Anything else, including `success:false`, is returned as the raw JSON.

Consequences for the hardening endpoints:

- On success the platform sees only `data`, so `last_result` and `pause_until` must live inside `data`.
- A `WP_Error` 400 reaches the platform as `null` and the reason is lost. For invalid `rule` or `minutes`, consider HTTP 200 with `success:false` and a `reason`.
- JSON bodies deliver real booleans, but the existing code casts with `(bool) $request->get_param(...)`. For `enabled`, use `rest_sanitize_boolean()`, because `(bool) "false"` is `true`.

---

## 3. LSM_Logger::log

There is one definition, and it is static. `P/includes/class-lsm-logger.php:263-292`:

```php
    /**
     * Log an event.
     *
     * @param string $action Event action.
     * @param string $status Event status (success, failure, warning, info).
     * @param array  $context Additional context.
     */
    public static function log($action, $status = 'info', $context = []) {
        $logs = get_option('lsm_activity_log', []);

        // Get current user if available
        $current_user = wp_get_current_user();
        ...
        update_option('lsm_activity_log', $logs, false);
    }
```

**Correct call shape:** `(snake_case_action, status, array_context)`.

- `class-lsm-api.php:1970-1972`:
  ```php
            LSM_Logger::log('security_settings_changed', 'info', [
                'changes' => $changes,
            ]);
  ```
- `class-lsm-maintenance-mode.php:67`: `LSM_Logger::log('maintenance_enabled', 'warning', []);`

**Deviant shape — do not copy.** `class-lsm-backup.php` puts a free-form sentence in `$action`, which breaks `get_stats()['by_action']` and action filtering:

- `:120`: `LSM_Logger::log('Backup created: ' . $backup_name . ' (' . size_format($backup_size) . ')');`
- `:143`: `LSM_Logger::log('Backup failed: ' . $e->getMessage(), 'error');`

**Status values.** The docblock lists `success, failure, warning, info`. `get_stats()` (`:324-329`) only counts `success|info|warning|error`. The codebase mostly uses `'error'`; `'failure'` appears once, at `class-lsm-auth.php:110`. Use `'error'` for rolled-back changes.

Suggested calls for the new class: `LSM_Logger::log('hardening_applied', 'success', [...])`, `LSM_Logger::log('hardening_rolled_back', 'error', ['reason' => ...])`, and `'hardening_crash_recovered'` with status `'warning'`.

**Constraint:** `log()` calls `wp_get_current_user()`, which is a pluggable function. It is not defined while plugin files are being included. See §4 and §10.1.

---

## 4. Main plugin file

**Constants and header**, `P/landeseiten-maintenance.php:5` and `:21-26`:

```php
 * Version: 2.9.4
...
define('LSM_VERSION', '2.9.4');
define('LSM_PLUGIN_FILE', __FILE__);
define('LSM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LSM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('LSM_PLUGIN_BASENAME', plugin_basename(__FILE__));
```

The header has `Text Domain: landeseiten-maintenance` (`:10`). It has no `Requires PHP` or `Requires at least` lines.

**Hook wiring**, `init_hooks()` at `:97-116`:

```php
        register_activation_hook(LSM_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(LSM_PLUGIN_FILE, [$this, 'deactivate']);

        // Initialize components
        add_action('init', [$this, 'init'], 0);
        add_action('rest_api_init', [$this, 'init_rest_api']);
        ...
        // Security filters
        $this->init_security_filters();
```

**`init_security_filters()`** (`:121-203`) holds the existing toggles. It runs synchronously from the constructor, which means during the plugin include, before `pluggable.php` loads. At that point it only calls `get_option`, `add_filter` and `add_action`:

```php
        if (!get_option('lsm_xmlrpc_enabled', true)) {                 // :123
        add_filter('rest_authentication_errors', function($result) {   // :129
        if (!get_option('lsm_rest_api_public', true)) {                // :157
        if (get_option('lsm_file_editing_disabled', false)) {          // :166
        if (get_option('lsm_security_headers_enabled', false)) {       // :185
```

**`init()`** (`:208-227`) is hooked to `init` at priority 0. Components boot here:

```php
    public function init() {
        // Load textdomain
        load_plugin_textdomain('landeseiten-maintenance', false, dirname(LSM_PLUGIN_BASENAME) . '/languages');

        // Initialize components
        LSM_Logger::init();
        new LSM_Health();
        new LSM_Auth();
        new LSM_Recovery();
        new LSM_Actions();
        new LSM_Support();
        new LSM_Maintenance_Mode();

        // Initialize PHP error handling
        LSM_Php_Errors::init();
```

**Where the hardening load-time work goes.** Put crash recovery and pause-expiry in `init()`, after `LSM_Logger::init();`, for example `LSM_Hardening::on_load();`. Do not put them in `init_security_filters()`. See §10.1 for the reason.

**`activate()` in full**, `:392-418`:

```php
    /**
     * Plugin activation.
     */
    public function activate() {
        // Create options
        $default_settings = [
            'api_key'           => wp_generate_password(32, false),
            'token_lifetime'    => 300,
            'enable_support'    => true,
            'support_email'     => get_option('admin_email'),
            'maintenance_mode'  => false,
            'maintenance_title' => __('Site Under Maintenance', 'landeseiten-maintenance'),
            'maintenance_message' => __('We are performing scheduled maintenance. Please check back soon.', 'landeseiten-maintenance'),
        ];

        if (!get_option('lsm_settings')) {
            add_option('lsm_settings', $default_settings);
        }

        // Store disabled plugins state
        if (!get_option('lsm_disabled_plugins')) {
            add_option('lsm_disabled_plugins', []);
        }

        // Flush rewrite rules
        flush_rewrite_rules();
    }
```

**`deactivate()` in full**, `:420-430`:

```php
    /**
     * Plugin deactivation.
     */
    public function deactivate() {
        // Disable maintenance mode on deactivation
        $settings = get_option('lsm_settings', []);
        $settings['maintenance_mode'] = false;
        update_option('lsm_settings', $settings);

        flush_rewrite_rules();
    }
```

Add the hardening cleanup call inside `deactivate()`. There is no `uninstall.php` and no `register_uninstall_hook` anywhere in the plugin (grep found none).

**Version string locations** (complete list):

| Location | Content |
|---|---|
| `P/landeseiten-maintenance.php:5` | ` * Version: 2.9.4` |
| `P/landeseiten-maintenance.php:22` | `define('LSM_VERSION', '2.9.4');` |

- Everything else reads the constant: `class-lsm-api.php:411`, `class-lsm-updater.php:73,105,295,324`, `admin/class-lsm-admin.php:134`, and the enqueue versions in the main file at `:262,265,271,296,297`.
- There is no `readme.txt`, no `composer.json` and no `package.json`. `README.md` carries no version.
- The updater hardcodes `'requires' => '5.8'`, `'tested' => '6.7.999'`, `'requires_php' => '7.4'` (`class-lsm-updater.php:85-87, 117-119`). These are not the plugin version, so leave them.
- `build-release.sh:51-52` rewrites both strings with `sed`.

**How past bumps were done:**

| Commit | Bump | Files touched |
|---|---|---|
| `c632c98` `chore(release): bump plugin header to 2.9.2` | header line 5, `2.8.0` → `2.9.2` | main file only. At `c632c98^` the header said `2.8.0` while the constant already said `2.9.2`, so this was a drift fix. |
| `f55f4f6` / merge `850eaf3` | 2.9.4 | `includes/class-lsm-health.php` and the main file (header and constant, 2 lines) |
| `42cac20` | 2.9.3 | `assets/css/ticket-widget.css` and the main file (2 lines) |
| `b34b778` `Release v2.7.1` | 2.7.1 | main file only (2 lines) |
| `63385d5` | 2.8.0 | `class-lsm-api.php` and the main file (2 lines) |
| `13468f0` | 2.7.0 | `admin/class-lsm-admin.php` and the main file (2 lines) |

The pattern is to bump both strings in the same commit as the feature, or in a release commit. Tags are annotated, `vX.Y.Z` (`v2.9.4` points to `850eaf3`). For 2.10.0, change exactly lines 5 and 22.

---

## 5. Testing

**No test harness exists.** A search of the repo (excluding `.git`) for `phpunit*`, `*phpcs*`, `composer.*`, `tests/`, `vendor/`, `bin/`, `.editorconfig`, `package.json`, `*.yml` and `*.dist` found only `README.md`. A grep for `Brain\Monkey`, `WP_Mock`, `PHPUnit` and `TestCase` found zero files. There is no `bin/install-wp-tests.sh`.

**Local tooling:**

- `php -v` reports `PHP 8.3.22 (cli)`, at `/opt/homebrew/bin/php`.
- `composer --version` reports `Composer version 2.8.9`, at `/opt/homebrew/bin/composer`.
- `which phpunit` finds nothing.
- `lsm-wp` has no `vendor/`.
- PHPUnit `12.5.3` exists at `/Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api/vendor/bin/phpunit`.
- Docker is installed but was not checked.
- There is no `wp` CLI.
- I did not test whether composer can reach packagist.

**Recommendation: plain PHPUnit with a small hand-written stub bootstrap, not Brain Monkey.**

- The class is stateful: options, transients and real file I/O. An in-memory fake for `get_option/update_option/delete_option/get_transient/set_transient/delete_transient` backed by static arrays is simpler and more honest than Brain Monkey's per-call expectations.
- Filesystem tests can use a real temp directory under `sys_get_temp_dir()`.
- Pin `phpunit/phpunit:^9.6`. It runs on PHP 7.3–8.3, so tests stay runnable on the 7.4 minimum the plugin declares.

**Layout.** Tests must sit outside `landeseiten-maintenance/`, because `build-release.sh` zips that whole directory. That means `lsm-wp/composer.json`, `lsm-wp/phpunit.xml.dist`, `lsm-wp/tests/bootstrap.php`, `lsm-wp/tests/stubs/wp-functions.php` and `lsm-wp/tests/HardeningTest.php`.

`lsm-wp/.gitignore` currently contains:
```
.DS_Store
*.zip
node_modules/
.env

# Local docs
*.md
```
Add `vendor/` and `.phpunit.result.cache`. `build-release.sh:83` runs `git add -A`, so without this `vendor/` would be committed. `*.md` is ignored in this repo, so no markdown added here gets committed.

**The bootstrap must provide:**

- **Constants:** `ABSPATH` (the class file `exit`s without it), `WP_CONTENT_DIR`, `LSM_PLUGIN_DIR`, `LSM_PLUGIN_URL`, `MINUTE_IN_SECONDS`.
- **Function stubs:** option and transient fakes, `wp_upload_dir`, `content_url`/`home_url`, `add_query_arg`, `wp_generate_password`, `is_wp_error`, `wp_remote_get`, `wp_remote_retrieve_response_code`, `__`, `current_time`.
- **`insert_with_markers`:** a faithful stub, or a copy of the core algorithm (see §10.2 for what core actually does).
- **A stub `LSM_Logger` class** that records calls.

**Class design for testability.** Per spec Part 4, all loopback HTTP goes through one overridable method, e.g. `protected function http_status($url)`. Add overridable path resolvers `content_dir()` and `uploads_dir()`, and `protected function now()`. Tests then subclass and point them at temp dirs and canned status codes. An instance class with thin static facades fits the plugin, which already mixes both styles; `LSM_Maintenance_Mode` has a constructor and static `enable()`/`disable()`.

**Exact commands** (run from `/Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp`):

```
composer require --dev phpunit/phpunit:^9.6        # one-time; creates composer.json/lock + vendor/
vendor/bin/phpunit -c phpunit.xml.dist             # full run
vendor/bin/phpunit -c phpunit.xml.dist --filter test_rollback_on_asset_broken
php -l landeseiten-maintenance/includes/class-lsm-hardening.php   # syntax lint
```

Zero-install fallback if packagist is unreachable. PHPUnit 12 requires attributes or `test*` method names, not `@test` annotations:

```
/Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests
```

---

## 6. Release and build

There is no `.github/` directory and no CI. Releases are made by `/Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-wp/build-release.sh <version>`, which needs `gh` authenticated. Key parts:

```bash
PLUGIN_SLUG="landeseiten-maintenance"
PLUGIN_DIR="$PLUGIN_SLUG"
MAIN_FILE="$PLUGIN_DIR/landeseiten-maintenance.php"
ZIP_FILE="${PLUGIN_SLUG}.zip"
...
sed -i '' "s/^ \* Version: .*/ * Version: ${VERSION}/" "$MAIN_FILE"
sed -i '' "s/define('LSM_VERSION', '.*');/define('LSM_VERSION', '${VERSION}');/" "$MAIN_FILE"
...
zip -r "$ZIP_FILE" "$PLUGIN_DIR/" \
    -x "*.DS_Store" -x "*/.DS_Store" -x "*/.git/*" -x "*/node_modules/*" -x "*/.env"
...
git add -A
git commit -m "Release v${VERSION}" || echo "  (nothing to commit)"
git tag -a "v${VERSION}" -m "Release v${VERSION}" ...
BRANCH=$(git rev-parse --abbrev-ref HEAD)
git push origin "$BRANCH" --tags
...
gh release create "v${VERSION}" "$ZIP_FILE" --title "v${VERSION}" --notes "Landeseiten Maintenance Plugin v${VERSION}" $PRERELEASE_FLAG
```

Three things to know about this script:

- It pushes whatever branch is currently checked out.
- `git add -A` stages every untracked, non-ignored file in the repo.
- The zip's top-level folder is `landeseiten-maintenance/`.

In practice 2.9.3 and 2.9.4 were released as hand-made commits with annotated tags, not with the script's "Release vX" commit. Version-named zip copies sit at `/Users/bmarkovic/Documents/Projects/LSMPlatform/landeseiten-maintenance-2.9.{2,3,4}.zip`.

**What the updater expects**, `P/includes/class-lsm-updater.php`:

- Repo and slug, `:22-24`:
  ```php
  private const GITHUB_REPO  = 'gamatech89/lsm-wp';
  private const PLUGIN_SLUG  = 'landeseiten-maintenance';
  private const LSM_API_URL  = 'https://api.wartung-ls.com/api';
  ```
- The version comes from the tag, `:71-73`: `$remote_version = ltrim($release['tag_name'], 'v');` then `if (version_compare($remote_version, LSM_VERSION, '>')) {`. The tag must be `v2.10.0`. `version_compare('2.10.0','2.9.4','>')` is true.
- The release source is the platform proxy `self::LSM_API_URL . '/v1/plugin/latest-release'` first (`:288-311`), then the GitHub `releases/latest` fallback (`:317-340`). The result is cached for 12 hours under the transient `lsm_github_update_check`.
- The asset name is not checked. The first asset ending in `.zip` is used, `:345-355`:
  ```php
            foreach ($release['assets'] as $asset) {
                if (substr($asset['name'], -4) === '.zip') {
                    return $asset['browser_download_url'];
  ...
        return $release['zipball_url'] ?? null;
  ```
- `post_install` (`:225-243`) renames the extracted folder to `WP_PLUGIN_DIR/landeseiten-maintenance`. It then calls `activate_plugin(self::PLUGIN_SLUG . '/landeseiten-maintenance.php');` non-silently, so **`activate()` runs after every self-update**.

Update paths and the activation/deactivation hooks:

- LSM-driven updates re-activate silently: `activate_plugin($plugin, '', $was_network_active, true)` at `class-lsm-api.php:947` and `class-lsm-actions.php:616`.
- Core's `Plugin_Upgrader` deactivates silently before an upgrade, so the deactivation hook does not fire on update.
- The spec's "Plugin updates never write" therefore holds only if nothing hardening-related is added to `activate()`.

---

## 7. Helpers to reuse

**Loopback HTTP.** There is no shared helper, only two inline usages. Note that `class-lsm-api.php:1989` uses `wp_remote_head`, not `wp_remote_get`.

- `class-lsm-api.php:1989-1996`, on master:
  ```php
      public function get_security_headers() {
          $site_url = get_site_url();
          
          // Make a HEAD request to the homepage to get headers
          $response = wp_remote_head($site_url, [
              'timeout' => 10,
              'sslverify' => false,
          ]);
  ```
- `class-lsm-actions.php:676-681`. This exists only in the uncommitted working tree, not on master, so do not depend on it:
  ```php
          $health_probe = wp_remote_get(
              add_query_arg('lsm_health', time(), home_url('/')),
              ['timeout' => 15, 'sslverify' => false, 'redirection' => 2]
          );
          if (!is_wp_error($health_probe)) {
              $health_after = (int) wp_remote_retrieve_response_code($health_probe);
  ```

The convention to copy is `'sslverify' => false`, an explicit `timeout`, and a cache-buster via `add_query_arg`. For probes also set `'redirection' => 0` and `'headers' => ['Cache-Control' => 'no-cache']`, and use a short timeout of about 5 s. Up to nine requests run per apply, and the total must stay well under the 120 s lock and the platform's 120 s `UPDATE_TIMEOUT`.

**Baseline asset candidates.** These real static files ship with the plugin:

- `LSM_PLUGIN_URL . 'assets/css/ticket-ui.css'`
- `admin/css/admin.css`
- `assets/images/logo-dark.png`

**Path resolution.** There is no helper. The code uses the constant and `wp_upload_dir()` directly:

- `class-lsm-backup.php:30-31`: `$upload_dir = wp_upload_dir(); $backup_dir = $upload_dir['basedir'] . '/' . self::BACKUP_DIR;`
- `class-lsm-security-scanner.php:134`: `$uploads_dir = wp_upload_dir()['basedir'];`
- `class-lsm-health.php:270-271`
- `WP_CONTENT_DIR` at `class-lsm-scan-collector.php:100,155` and `class-lsm-backup.php:226-232`

**Lazy-require pattern for wp-admin includes**, `class-lsm-api.php:855-857`:
```php
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
```
Use the same shape for `insert_with_markers` and `wp-admin/includes/misc.php`. Nothing in the plugin requires `misc.php` today.

**Locks.** There is no transient-mutex pattern in the plugin. Transients are used only as caches or tokens:

- `lsm_tickets_unread` (`class-lsm-support.php:455-459`)
- `lsm_plugin_info_cache` (`class-lsm-api.php:543,604`)
- `lsm_backup_token_*` (`class-lsm-backup.php:124`)
- `lsm_scan_progress`, `set_transient('lsm_scan_progress', $progress, 600);` (`class-lsm-security-scanner.php:401`)
- `lsm_github_update_check`

The only real lock is in the uncommitted working tree at `class-lsm-actions.php:560`, `WP_Upgrader::create_lock('lsm_bulk_plugin_update', 15 * MINUTE_IN_SECONDS)`, released with `release_lock` at `:691`. The same change adds long-operation protection at `:575-576`. The pattern `ignore_user_abort(true); @set_time_limit(0);` is worth copying into the apply procedure, so a platform disconnect cannot strand `pending`.

**Server software.** The only read is `class-lsm-health.php:126`: `'software' => isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : 'Unknown',`. There is no Apache or LiteSpeed detection helper.

**Existing `.htaccess` writer.** The only one is `class-lsm-backup.php:36`: `file_put_contents($backup_dir . '/.htaccess', "Order deny,allow\nDeny from all");`. It uses 2.2-only syntax with no `IfModule`, and the spec leaves it untouched.

**Settings accessors.** `Landeseiten_Maintenance::get_setting($key)` and `update_setting()` (`landeseiten-maintenance.php:438-458`) wrap the `lsm_settings` array. The existing security toggles use standalone options (`lsm_xmlrpc_enabled` and so on), so a standalone `lsm_hardening` option fits.

---

## 8. Branch state

- **Default branch:** `master`. `origin/master` is the only remote branch. There is no `main`, and `refs/remotes/origin/HEAD` is not set.
- **Current branch:** `fix/concurrent-plugin-update-guard`. `HEAD`, `master` and `origin/master` all point to `850eaf3` (tag `v2.9.4`). The branch has no commits of its own; its work exists only as uncommitted changes.
- **`git status`:** `modified: landeseiten-maintenance/includes/class-lsm-actions.php`. Nothing is staged, and the stash is empty.
- **`git diff master --stat`:** `.../includes/class-lsm-actions.php | 64 ++++++++++++++++++++--` — 1 file, 58 insertions, 6 deletions.
- **Content of that uncommitted change:** it removes a duplicated `FS_METHOD` block, adds the `WP_Upgrader` lock, `ignore_user_abort`, a skip of the plugin's own entry in the bulk loop, a post-update loopback health probe, and `skipped`/`health_after` result keys.
- **The files this feature touches** — `landeseiten-maintenance.php`, `includes/class-lsm-api.php` and the new `includes/class-lsm-hardening.php` — have **no differences from master**.
- **Overlap:** none at file level with the uncommitted work, unless `deactivate()` or a helper is placed in `class-lsm-actions.php`. Don't put anything there.
- **Feature branch:** `feature/htaccess-hardening` does not exist yet. Create it from `master` in a separate worktree (`git worktree add`) so the dirty tree is left alone.
- **Other local branches:** `feature/ticket-ui-redesign`, `fix/disk-free-space-guard`, `fix/widget-font`. All are already merged or behind master.

---

## 9. Conventions

- **Indentation:** 4 spaces, no tabs (grep for a leading tab found zero files). Opening brace on the same line: `class X {`, `function f() {`.
- **Arrays:** short `[]` only. There are 0 occurrences of `array(` and 130+ of `= [`. Multi-line arrays have trailing commas and `=>` alignment.
- **Docblocks:**
  - File header: `/** ... @package Landeseiten_Maintenance */`.
  - Class docblock: `/** LSM API class. */`.
  - Methods: a one-line summary plus `@param WP_REST_Request $request Request.` and `@return WP_REST_Response`.
  - Section banners: `// ====...` / `// SECURITY SETTINGS` (`class-lsm-api.php:1875-1877`).
- **Naming:** classes `LSM_Foo`, files `class-lsm-foo.php`, options, transients and log actions prefixed `lsm_`, methods in snake_case.
- **Style:** this is not WPCS (no spaces inside parentheses, no Yoda conditions), despite what README.md claims.
- **Type hints:** mixed. Most methods are untyped. Newer code uses scalar and return types, e.g. `private function is_lsm_rest_request(): bool` (main file `:229`) and `public static function delete_media(array $ids): array` (`class-lsm-media.php:135`).
- **Text domain:** `landeseiten-maintenance`. API `message` strings are mostly plain untranslated English. `__()` is used for user-facing and admin strings.
- **PHP minimum:** 7.4, declared only in the updater (`'requires_php' => '7.4'`, `class-lsm-updater.php:87,119`), along with WP `5.8`. There is no header line for either. The codebase has no PHP 8-only syntax (grep for `str_contains`, `match(`, `?->` found zero). Keep the new class 7.4-safe. `try/finally`, typed properties and `??=` are fine. `str_starts_with` on WP < 5.9, `match`, union types and constructor promotion are not.
- **Lint config:** there is no phpcs, phpstan, editorconfig or lint config of any kind.
- **Commits:** conventional style — `feat(scope):`, `fix(scope):`, `chore(release):`.

---

## 10. Where Part 1 of the spec does not fit

1. **"On plugin load" cannot mean `init_security_filters()` or the constructor.**
   - The order is confirmed in a local WP 6.1.1 `wp-settings.php`: the plugin include loop is at `:443`, `require ABSPATH . WPINC . '/pluggable.php';` at `:462`, and `plugins_loaded` at `:480`.
   - Crash recovery and pause-expiry both log, and `LSM_Logger::log()` calls `wp_get_current_user()` (`class-lsm-logger.php:274`). Calling it from `init_security_filters()` would therefore be a fatal "undefined function".
   - Hook the load-time work into `Landeseiten_Maintenance::init()` (`:208`, `init` priority 0), after `LSM_Logger::init()`.

2. **`insert_with_markers` does not remove a block on empty input.**
   - In core `wp-admin/includes/misc.php` (6.1.1 copy read locally), the function always prepends three translated `# The directives (lines) between "BEGIN …"` comment lines: `$insertion = array_merge( $instructions, $insertion );`.
   - It then always writes `# BEGIN {$marker}` … `# END {$marker}`. If the file is missing it `touch()`es it first.
   - So the spec's "An empty rule set removes the block" is false. An empty set leaves an empty marked block, or creates a new `.htaccess` that holds only markers.
   - Removal needs its own routine: strip the marker range, restore "file did not exist" by unlinking, and never call `insert_with_markers` with `[]`. This applies to deactivation cleanup and to turning the last rule off.
   - The instruction lines also vary with locale and with the `insert_with_markers_inline_instructions` filter. Tests of generated content should therefore assert on the body lines, not the whole block.

3. **Pause-expiry re-apply during an ordinary visitor request.**
   - The visitor request would block for several sequential loopback requests.
   - Those loopbacks can re-enter WordPress. For example, a missing baseline image falls through the root rewrite to `index.php`, which loads the plugin, sees the expired pause and re-enters apply.
   - To keep nested loads from doing damage:
     - Acquire the lock before any HTTP request.
     - Make "lock busy" on the load path a silent no-op, with no `last_result` write and no rollback.
     - Skip load-time work on the hardening REST routes themselves.
   - Consider running expiry only in non-front-end contexts (REST, cron, admin), plus a `wp_schedule_single_event` set at pause time. The platform's `hardening:resume-expired` command is the backstop in any case.

4. **A transient is not an atomic lock.**
   - `get_transient` then `set_transient` races, and with a persistent object cache it is not even in the database.
   - The plugin has no transient-mutex precedent. The only lock in the codebase is the uncommitted `WP_Upgrader::create_lock()`, an atomic options-table insert, but it requires `wp-admin/includes/class-wp-upgrader.php`.
   - A light atomic alternative is `add_option('lsm_hardening_lock', time(), '', 'no')`, which fails if the option exists, plus a stale check above 120 s. If the transient is kept as the spec says, the race window must be accepted knowingly.

5. **Timing budget versus the 120 s constants.**
   - The lock TTL, the crash-recovery threshold and the platform's `UPDATE_TIMEOUT` are all 120 s.
   - The existing loopback timeouts are 10–15 s. Nine requests at 10 s exceed 90 s.
   - A legitimately slow apply could then be "crash-recovered" by a concurrent request while it is still running.
   - Use probe timeouts of about 5 s, `'redirection' => 0`, and `ignore_user_abort(true); @set_time_limit(0);` as the working-tree code does at `class-lsm-actions.php:575-576`.

6. **The response envelope versus the platform's unwrap.**
   - `LsmService::handleResponse` strips `{success:true,data}` down to `data` and returns `null` on any non-2xx.
   - Put `rules`, `pause_until`, `server` and `last_result` inside `data`.
   - Rollbacks must be HTTP 200 with `success:false` and `reason` at top level, as the spec says.
   - Avoid `WP_Error` 4xx for validation failures the UI should explain. The existing callbacks do use `WP_Error` 400 for missing params, so either choice is locally consistent, but only a 200 carries the reason to the platform.

7. **No route uses `args`.** The spec does not demand them. Follow the local pattern of manual validation in the callback. For example, `in_array($rule, ['block_archives','block_debug_log','block_uploads_php'], true)`, `in_array((int) $minutes, [15, 30, 60], true)`, and `rest_sanitize_boolean($enabled)`.

8. **"Deactivation / uninstall".**
   - The plugin has no uninstall handler, so `lsm_hardening` and any `.htaccess.lsm-bak` files persist after deletion. Either add `uninstall.php` or treat this as deactivation-only.
   - The plugin blocks its own remote deactivation (`class-lsm-api.php:1066-1073`, `protected_plugin` 403), and emergency recovery skips it (`class-lsm-recovery.php:24-34`). Deactivation cleanup therefore only ever runs from wp-admin or WP-CLI, where `misc.php` is usually already loaded. Keep the `function_exists` guard anyway.
   - `LSM_Updater::post_install` re-runs `activate()` after every self-update (`class-lsm-updater.php:240`). Keep `activate()` free of hardening writes so that "Plugin updates never write" stays true.

9. **Interaction with the plugin's own scanner.**
   - `collect_htaccess()` (`class-lsm-scan-collector.php:152-167`) ships every `.htaccess` under `ABSPATH`/`WP_CONTENT_DIR` to the platform "for tampering checks". The new `# BEGIN LSM-HARDENING` blocks will show up there. The platform analyzer should whitelist that marker, which is a Part 2 concern.
   - The suspicious-files pass flags PHP in uploads (`class-lsm-security-scanner.php:133-136`). A `lsm-probe-*.php` file left behind by a crash would be reported. Crash recovery should also glob-delete `lsm-probe-*` in both directories.
   - `.htaccess.lsm-bak` is not flagged by `find_hidden_files` (`:529-547`), which only flags dot-files with a php extension.
   - The option name `lsm_hardening` is already excluded from option scanning by `option_name NOT LIKE 'lsm\_%'` (`class-lsm-scan-data-collector.php:90`).

10. **`get_security_headers` is a HEAD request to the homepage** (`wp_remote_head`), not a GET helper. Nothing reusable exists for status-code probes, so the single injectable HTTP method has to be written fresh. The spec's baseline "static asset of our plugin under wp-content" is satisfiable with `assets/css/ticket-ui.css`. If `WP_PLUGIN_DIR` has been relocated outside `WP_CONTENT_DIR`, that baseline does not exercise `wp-content/.htaccess`. Detect that case and fall back to the probe file only.

11. **A rule-level risk the self-test will not catch.** This concerns the rule itself rather than plugin fit.
    - `\.gz$` in `wp-content/.htaccess` also matches pre-compressed cache files served from `wp-content/cache/`, e.g. WP Super Cache's `index.html.gz` in mod_rewrite mode.
    - The baseline checks only one CSS asset, so such a breakage would pass the self-test.
    - Either add a cache-path baseline when `wp-content/cache` exists, or scope the archive pattern.

12. **Version and branch mechanics.**
    - `build-release.sh` pushes the current branch and runs `git add -A`. Run the release from `master` after merging, and add `vendor/` to `.gitignore` first (see §5).
    - Header and constant have drifted once before (`c632c98`). Bump both, lines 5 and 22.