# Verification of the technical assumptions in the .htaccess hardening design spec

Spec checked: `/Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api/docs/superpowers/specs/2026-09-21-htaccess-hardening-design.md`.

I read the Apache httpd 2.4.x source (`server/request.c`, `server/config.c`, `server/core.c`), WordPress trunk, and plugin sources directly. "UNVERIFIED" marks a claim I could not confirm from a primary source.

## Spec changes required

These are the changes to make before building; the numbered sections below give the evidence.

**A. `.gz` in `block_archives` will 403 real page views.**
- WP Super Cache in Expert (mod_rewrite) mode serves pages from `wp-content/cache/supercache/<host>/…/index-https.html.gz`.
- `FilesMatch` is evaluated against the rewritten filename, so every gzip-capable anonymous visitor gets 403 on cached pages.
- Any site using the Apache-documented precompressed `.css.gz`/`.js.gz` rewrite for assets under wp-content breaks the same way.
- Fix: never match bare `.gz`. Use a positive list such as `(?i)(\.(wpress|sql|zip|tar|tgz|bak)|\.(sql|tar|bak|zip|wpress)\.gz)$`.

**B. "An empty rule set removes the block" is false.**
- `insert_with_markers()` with an empty insertion leaves `# BEGIN`, three comment lines and `# END` in the file.
- If the file was absent it creates it with just that. Block and file removal must be the engine's own code.

**C. A single `.bak` probe gives a false "effective" result behind nginx or a CDN.**
- `.bak`, `.sql` and `.wpress` are not in Plesk's nginx static list or Cloudflare's cached-extension list. `.zip`, `.gz` and `.tar` are.
- So `.bak` returns 403 from Apache while `.zip` is still served by nginx.
- Probe a real file for every protected extension, or at minimum zip, wpress and sql. Report results per extension.

**D. A bare 403 is ambiguous.**
- It can come from a Cloudflare challenge, a host WAF or a loopback block. Basic auth on staging gives 401.
- Make probes differential. Before the write, expect 200 with the token body. After the write, expect 403.
- If the pre-write result is already not 200, report `already_blocked_foreign` or `loopback_blocked` rather than `on`.

**E. The PHP probe "whose body is a plain string" cannot tell "PHP executed" from "source served raw".**
- Both return the same body. Use `<?php` code with a computed output, as described under item 10.

**F. "Homepage is served from the root" is false on sites with rewrite-mode page caches.**
- WP Super Cache Expert, WP Fastest Cache, W3TC Disk:Enhanced, and WP Rocket with its rewrite rules active all rewrite `/` to a file under `wp-content/cache/`.
- A broken `wp-content/.htaccess` therefore returns 500 for anonymous cached page views.
- `/wp-json/`, `/wp-admin/`, POSTs and query-string requests are unaffected, so the plugin stays reachable and can roll back.
- Add `home_url('/')` to the baseline, fetched without a query string or cookies and with `Accept-Encoding: gzip`.

**G. LiteSpeed Enterprise ignores `<IfModule>` tests and executes both branches.**
- This is harmless here because both branches deny.
- OpenLiteSpeed honours only rewrite rules and needs a restart.
- `SERVER_SOFTWARE` is `LiteSpeed` for both editions. `$_SERVER['LSWS_EDITION']` starting with `Openlitespeed` identifies OpenLiteSpeed, which should report `unsupported`.

**H. Media Library archives will 403.**
- WP core allows zip, gz, tar, rar and 7z uploads. Any such file linked from a page will return 403 once `block_archives` is on.
- Add a pre-enable scan that lists attachments with these types and warns.

## 1. Bad `wp-content/.htaccess` returns 500 only under `wp-content/`

**Verdict: PARTLY TRUE.**

**Evidence**
- For a request, httpd looks for `.htaccess` only in the directories along the path of the mapped file. Source: https://httpd.apache.org/docs/2.4/howto/htaccess.html.
- A directive not allowed by AllowOverride "causes an internal server error". Source: https://httpd.apache.org/docs/2.4/mod/core.html#allowoverride.
- In `server/config.c` the check is `(parms->override & cmd->req_override) == 0`, which yields "not allowed here". It becomes a warning only with `AllowOverride … Nonfatal=Override`.
- With `AllowOverride None` the file is never read, so the rule is silently ineffective and no 500 occurs.
- A per-directory mod_rewrite substitution triggers an internal redirect. The redirect re-runs `ap_process_request_internal()`, including the directory walk of the new path. Source: https://httpd.apache.org/docs/2.4/developer/request.html.
- I verified these plugin rewrite targets from source:
  - WP Super Cache `inc/htaccess.php`: target is `{wp-content}/cache/supercache/%{SERVER_NAME}/$1/index-https.html.gz`.
  - WP Fastest Cache `inc/admin.php` line 884: target is `/wp-content/cache/all/$1/index.html`.
  - WP Rocket `inc/functions/htaccess.php`: target is `…/index%{ENV:WPR_SSL}….html_gzip`, conditional on `REQUEST_METHOD GET` and `QUERY_STRING =""`.
  - W3TC `PgCache_Environment.php`: same pattern with a `_gzip`/`_br` suffix.
- These anonymous page views are HTTP requests under wp-content after the rewrite. They would return 500.

**What else is affected**
- Theme CSS/JS, uploads images and plugin assets (including admin assets) would return 500, so pages render unstyled. wp-admin core assets live under `/wp-admin` and `/wp-includes`, so they are fine.
- PHP `include` is a filesystem read and is unaffected.
- I grepped lsm-wp read-only and found no direct-access PHP endpoint under wp-content. Recovery goes through REST, so the plugin stays reachable.
- On LiteSpeed Enterprise an unsupported directive is ignored rather than causing a 500. The only source is third-party: https://stackharbor.com/en/knowledge-base/litespeed-htaccess-compatibility/. LiteSpeed's own docs are silent.

**Design consequence**
- Apply change F above.
- Use `WP_CONTENT_DIR` and `wp_upload_dir()['basedir']`, not hard-coded paths.
- Fetch baseline assets with a unique query string so a CDN or nginx cached 200 cannot mask a 500. The homepage check must not carry a query string.
- `<IfModule>` does not protect against an AllowOverride that lacks AuthConfig. Only the self-test and rollback do.

## 2. `insert_with_markers()`

**Verdict: spec PARTLY TRUE. The location and the require are correct. "Empty set removes the block" is FALSE.**

**Evidence:** https://github.com/WordPress/wordpress-develop/blob/trunk/src/wp-admin/includes/misc.php (function at line 114).

**File and includes**
- The function lives in `wp-admin/includes/misc.php`. That file contains only function definitions and has no side effects when included.
- No other include is needed. It uses `switch_to_locale`, `get_locale`, `__` and `apply_filters`, all of which are loaded in REST context.

**Missing file**
- If the directory is not writable it returns false.
- Otherwise it calls `touch()`, returning false on failure, then `chmod(perms | 0644)`.

**Return values**
- An existing unwritable file returns false.
- An `fopen('r+')` failure returns false.
- An unchanged block returns true without writing anything.
- In all other cases it returns `(bool) fwrite bytes`.

**Locking and atomicity**
- It takes a blocking `flock($fp, LOCK_EX)` and ignores the return value.
- The write is in place: `fseek(0)`, `fwrite`, `ftruncate`. It is not atomic.
- Apache does not honour flock and can read a half-written file. A crash leaves a truncated file, so the snapshot and crash flag are genuinely needed.
- An alternative is the engine's own temp-file plus `rename()` writer that preserves permissions.

**Line endings**
- Each line is `rtrim`med of `"\r\n"` and the file is re-joined with `"\n"`. A CRLF file is converted entirely to LF.
- A trailing newline is kept only if it existed. A newly created file starts with an empty line and has no newline after `# END`.

**Content outside the markers**
- It is preserved. The block is replaced in place, or appended at the end of the file if there are no markers.
- Markers are found with `str_contains`, so matching is by substring. Do not use marker names that are prefixes of one another.
- Only the first BEGIN and the first END are honoured.
- A BEGIN with no END causes everything after it to be treated as the old block and deleted. Validate marker pairing before calling.

**Empty `$insertion`**
- The instruction lines are always merged in, so the file ends up with `# BEGIN X`, three `#` comment lines and `# END X`.
- The block is never removed.

**Comment lines (since 5.3)**
- The text is "The directives (lines) between "BEGIN %1$s" and "END %1$s" are / dynamically generated, and should only be modified via WordPress filters. / Any changes to the directives between these markers will be overwritten."
- It is filterable through `insert_with_markers_inline_instructions`.
- It is translated through `switch_to_locale( get_locale() )`. However, `load_default_textdomain()` loads `admin-<locale>.mo` only under `is_admin()`, install, repair or auto-update (`wp-includes/l10n.php`).
- So a REST write produces the English comment. A write from wp-admin, such as deactivation from the Plugins screen, produces German.
- The block text therefore differs by context. Compare rule bodies with `extract_from_markers()`, which skips `#` lines, or force a fixed comment through the filter.

**Design consequence**
- Write your own "remove block" routine. Delete the file when it becomes empty and the snapshot recorded that it was absent.
- Do not assert byte-exact blocks in tests unless the comment filter is applied.

## 3. `<IfModule>` inside `<FilesMatch>`, the two syntaxes, and the required overrides

**Verdict: TRUE, with caveats.**

**Evidence**
- `<IfModule>`, `<Files>` and `<FilesMatch>` have context "server config, virtual host, directory, .htaccess" and Override "All". Source: https://httpd.apache.org/docs/2.4/mod/core.html.
- In source they are `OR_ALL`, and the check is a bitwise AND. Any non-None override class therefore permits them.
- `Require` needs override AuthConfig. Source: https://httpd.apache.org/docs/2.4/mod/mod_authz_core.html#require.
- `Order`, `Allow` and `Deny` need Limit, and `Satisfy` needs AuthConfig. Source: https://httpd.apache.org/docs/2.4/mod/mod_access_compat.html.
- `<IfModule>` is `EXEC_ON_READ`, so the branch that does not match is never parsed. On 2.4 only `Require` is evaluated, and only AuthConfig is needed.
- Sucuri's plugin ships exactly this nesting. Source: https://github.com/Sucuri/sucuri-wordpress-plugin/blob/main/src/hardening.lib.php.

**Apache 2.4 with mod_access_compat loaded**
- Our block emits only `Require`, so there is no mixing inside the block.
- If foreign `Order/Deny` rules exist elsewhere, both mechanisms run (access_checker and auth_checker). Under the default `Satisfy All` both must pass, so a deny from either one wins.
- The docs say "Mixing old directives … with new ones … is technically possible but discouraged". Source: https://httpd.apache.org/docs/2.4/upgrading.html.
- mod_access_compat directives in a new section inherit nothing from previous sections.

**LiteSpeed Enterprise**
- The vendor documentation says: "All conditional checking via IfModule (with one notable exception) is ignored, and the directives enclosed within are always executed". The exception is `<IfModule LiteSpeed>`. Source: https://docs.litespeedtech.com/lsws/cp/cpanel/switch-apache/.
- Both branches run, giving deny plus deny, which is harmless. The docs are silent on negated tests specifically.

**Design consequence**
- The dual syntax protects against a missing module. It does not protect against an AllowOverride without AuthConfig, which yields a 500 that the rollback catches.
- Never put a "granted" directive in one branch and "denied" in the other. On LiteSpeed both would execute.

## 4. Merge order between parent and deeper `.htaccess`

**Verdict: the order below is confirmed.**

**Evidence**
- The documented order is:
  - `<Directory>` and `.htaccess` together, shortest path to longest, with the child overriding the parent;
  - then `<DirectoryMatch>`;
  - then `<Files>`/`<FilesMatch>`;
  - then `<Location>`;
  - then `<If>`.
  
  Source: https://httpd.apache.org/docs/2.4/sections.html.
- In `server/core.c`, `merge_core_dir_configs` does `apr_array_append(base->sec_file, new->sec_file)`. The parent's Files sections come first, the child's come later, and later ones win.
- `AuthMerging` defaults to Off, so "a more specific configuration section's authorization directives override those of the preceding sections". Source: https://httpd.apache.org/docs/2.4/mod/mod_authz_core.html#authmerging.

**Net effect**
- A `<FilesMatch>` deny in wp-content/.htaccess beats a plain directory-level `Require all granted` in a child `.htaccess`, because Files sections merge after all directory-level directives.
- A `<Files>` or `<FilesMatch>` with `Require all granted` in a child `.htaccess` overrides ours.
- A `<Location>` in the vhost overrides both.

**Design consequence**
- Foreign rules can live in places PHP cannot read, such as the vhost config, or in deeper directories.
- A probe in the wp-content root therefore proves nothing about `ai1wm-backups/`.
- AI1WM's own `ai1wm-backups/.htaccess` contains only AddType, DirectoryIndex and Options. It has no authz directives (verified from `class-ai1wm-file-htaccess.php` on plugins.svn.wordpress.org).
- Manual audit rules could still sit in that directory.
- For pause and resume, additionally send a `HEAD` to the newest real `.wpress` file URL. This is the only test of what the user will actually hit.

## 5. Authorization mid-transfer

**Verdict: TRUE. The docs describe the architecture but never state this explicitly.**

**Evidence**
- `.htaccess` "is loaded every time a document is requested" (htaccess how-to, linked under item 1).
- access_checker, check_user_id and auth_checker run once in `ap_process_request_internal()` before fixups and before the handler. I verified the ordering in `request.c`, and it matches https://httpd.apache.org/docs/2.4/developer/request.html.
- The handler then streams from an already-open file. Nothing re-checks during the transfer.
- A Range or resume request is a new request and gets 403.
- LiteSpeed's docs are silent. Keep the planned real-site check.

**Design consequence**
- A multi-GB `.wpress` download that stalls and auto-resumes after the pause expires fails with 403.
- Say so in the confirm text.
- Do not auto-resume earlier than the selected duration.

## 6. LiteSpeed Enterprise and OpenLiteSpeed

**Verdict: PARTLY TRUE. Enterprise honours the directives. OpenLiteSpeed does not.**

**OpenLiteSpeed**
- "OLS currently supports mod_rewrite rules from Apache", and other directives are ignored.
- "you must restart OLS after making any changes to your rewrite rules". Source: https://docs.openlitespeed.org/config/rewriterules/.
- A third-party forum post confirms a restart is needed after `.htaccess` changes: https://forum.directadmin.com/threads/openlitespeed-must-be-reloaded-after-change-htaccess.58486/.

**LiteSpeed Enterprise**
- It is described as a drop-in replacement for Apache (docs.litespeedtech.com, linked under item 3).
- A third-party source says Files/FilesMatch, Require and Order/Deny are native, and changes are live on the next request (StackHarbor, linked under item 1). LiteSpeed's own docs give no explicit directive matrix.
- It does not support rewrite rules placed inside `<FilesMatch>`. That is irrelevant to this design.

**SERVER_SOFTWARE**
- The LiteSpeed Cache plugin checks `LSWS_EDITION` for the `Openlitespeed` prefix first, and only then tests `'LiteSpeed' === SERVER_SOFTWARE` to conclude Enterprise.
- That ordering shows both editions report `LiteSpeed`. Source: https://github.com/litespeedtech/lscache_wp/blob/master/litespeed-cache.php lines 95–108.

**Design consequence**
- In preflight, report `unsupported` with reason `openlitespeed` when `LSWS_EDITION` has the `Openlitespeed` prefix. The probe remains the final arbiter.

## 7. nginx in front of Apache

**Verdict: TRUE.**

**Plesk**
- With "Serve static files directly by nginx", "requests for files with the specified extensions never reach Apache … rewrite rules or .htaccess directives are not applied". Source: https://docs.plesk.com/en-US/obsidian/customer-guide/websites-and-domains/hosting-settings/web-server-settings/apache-and-nginx-settings.72320/.
- By default the option is off. Proxy mode and Smart static files processing are on. The docs do not say whether Smart mode applies `.htaccess`.
- The preset extension list as quoted on the Plesk forum:
  - `ac3 avi bmp bz2 css cue dat doc docx dts eot exe flv gif gz htm html ico img iso jpeg jpg js mkv mp3 mp4 mpeg mpg ogg pdf png ppt pptx qt rar rm svg swf tar tgz ttf txt wav woff woff2 xls xlsx zip`, plus webp;
  - it contains gz, tar, tgz and zip;
  - it does not contain bak, sql or wpress.
  
  Source: https://talk.plesk.com/threads/serve-static-files-directly-by-nginx-for-webp.357195/.

**SiteGround**
- NGINX Direct Delivery is on by default. It covers "Images, CSS files, JavaScript files, and plain HTML", and the cache "refreshes every 3 hours". Source: https://www.siteground.com/tutorials/supercacher/nginx-direct-delivery.
- No extension list is published.

**Raidboxes**
- `.htaccess` is honoured only from RB 2.0 onwards. NGINX boxes ignore it. Source: https://helpcenter.raidboxes.de/en/articles/102196-do-you-support-htaccess-files.

**Not found**
- I found no authoritative source for all-inkl or for Hostinger fronting Apache with nginx.

**SERVER_SOFTWARE**
- PHP runs under Apache in these setups, so `SERVER_SOFTWARE` still reports Apache.

**Design consequence**
- Apply change C above. `.zip` is the best single probe, but probe every extension.
- Probe files must really exist. A `try_files` rule on a nonexistent file falls through to Apache, which returns 403 even though an existing file of that type would be served by nginx.
- Give each probe file a token body and verify the body on a 200 response.
- When only some extensions are blocked, keep the rule and flag `partial` with the per-extension list. This is a recommendation rather than rolling back, because rollback would re-expose `.wpress` and `.sql`.

## 8. Loopback requests

**Verdict: the Site Health arguments are confirmed. Yes, pass the `https_local_ssl_verify` filter.**

**Evidence:** `WP_Site_Health::can_perform_loopback()` in `class-wp-site-health.php` at about line 3273.

**What Site Health sends**
- `wp_remote_post( site_url('wp-cron.php') )`
- `cookies = wp_unslash($_COOKIE)`
- `timeout = 10`
- header `Cache-Control: no-cache`
- `sslverify = apply_filters('https_local_ssl_verify', false, $url)`
- an `Authorization: Basic` header copied from `PHP_AUTH_USER` and `PHP_AUTH_PW` when present

**`WP_Http` defaults**
- `sslverify` defaults to true.
- `timeout` defaults to 5.
- `redirection` defaults to 5.
- `reject_unsafe_urls` defaults to false.
- Core applies only `https_ssl_verify`, so the caller must pass the local filter explicitly.
- `block_request()` exempts the site's own host under `WP_HTTP_BLOCK_EXTERNAL`.

**Reasons loopbacks fail**
- The official page names only plugin and theme conflicts. Source: https://developer.wordpress.org/advanced-administration/wordpress/loopback/.
- Other reasons are practitioner knowledge rather than documented:
  - basic auth on staging;
  - a host firewall blocking self-requests;
  - DNS, hosts-file or NAT hairpin problems;
  - certificate mismatch;
  - a Cloudflare challenge;
  - timeouts.
- In a platform-initiated REST call `PHP_AUTH_*` is absent, so a basic-auth site returns 401.

**Design consequence**
- Use `sslverify => apply_filters('https_local_ssl_verify', false, $url)`.
- Set timeout to 10 and `redirection => 0`. A custom `ErrorDocument 403 https://…` would otherwise turn a 403 into 302 then 200.
- Send no cookies.
- Map WP_Error, 401, 5xx and the header `cf-mitigated: challenge` to `loopback_blocked`. They must never count as "403 effective". Source for the header: https://developers.cloudflare.com/cloudflare-challenges/challenge-types/challenge-pages/detect-response/.
- Make one control request first, to a `.txt` probe or to `wp-content/index.php`.

## 9. Caching layers

**Verdict: PARTLY TRUE. The probe itself is safe. Real archives that are already cached at an edge are not covered.**

**Evidence**
- Cloudflare caches by extension only, and its default list includes 7Z, BZ2, GZ, RAR, TAR and ZIP. It does not include bak, sql or wpress.
- Default edge TTLs are:
  - 200, 206 and 301: 120 minutes;
  - 302 and 303: 20 minutes;
  - 404 and 410: 3 minutes.
  
  Other status codes, including 403, are not cached by default.
- The maximum cacheable file size is 512 MB on Free, Pro and Business plans. Source: https://developers.cloudflare.com/cache/concepts/default-cache-behavior/.
- The default cache level is Standard: "Delivers a different resource each time the query string changes". Source: https://developers.cloudflare.com/cache/how-to/set-caching-levels/.
- LiteSpeed Cache's behaviour for static files and 403 responses is not documented.

**Design consequence**
- A unique random filename guarantees a cache miss everywhere. The query string is redundant but harmless.
- A `.zip`, `.gz` or `.tar` backup that was already downloaded through Cloudflare can stay available from the edge for about 2 hours after enabling, plus the browser TTL. Document this. The plugin cannot purge it.
- A stale cached 403 after a pause is unlikely with default settings.

## 10. PHP probe in uploads

**Scanner evidence**
- Imunify360 scans new and modified files in real time through fanotify, and quarantines them based on signatures, heuristics or ML. Source: https://blog.imunify360.com/enabling-real-time-scanning-in-imunify360.
- Hostinger shared hosting uses Monarx, which is real-time, hooks the PHP engine and can auto-quarantine. Sources: https://www.monarx.com/platform/threat-removal and https://www.websitebuilderexpert.com/news/hostinger-and-monarx-partnership/.
- Neither vendor documents flagging benign PHP files by location. The residual risk is a lingering file or an audit finding, not the content.

**Hosts that already block PHP in uploads**
- Managed nginx hosts commonly block or never execute PHP in uploads, and they return 403 or 404. The pre-write probe detects this. It is not vendor-documented.

**Safest probe**
- Name the file `lsm-probe-<16hex>.php`.
- Body:
  ```php
  <?php /* LSM hardening self-test; auto-deleted */ echo 'lsm-probe-exec:' . strrev('<token>');
  ```
- The body uses no superglobals, eval, include or base64. The expected output, the reversed token, never appears in the source.
- Interpret results as follows:
  - the reversed token means PHP executed;
  - a body containing `<?php` means the source was served raw;
  - 403 means blocked;
  - 404 although the file exists means it was quarantined or blocked by a server rule.
- Delete the file in `finally`. Sweep stale `lsm-probe-*` files on plugin load.
- Exclude `lsm-probe-*` from LSM's own scanner (`class-lsm-scan-collector.php`).

**Zero-file fallback**
- Request a nonexistent `lsm-probe-x.php`. On Apache, `ap_file_walk` matches the basename of `r->filename` without checking that the file exists, and authz runs before the handler.
- The response is therefore 403 with the rule and 404 without it. LiteSpeed does not document this behaviour.

**Does denying PHP in uploads break plugins?**
- Sucuri ships the same rule with an allowlist and warns that "many plugins and theme … rely on the ability to execute PHP files in the content directory". Source: https://docs.sucuri.net/plugins/wordpress-hardening-options/.
- That warning is mainly about wp-content hardening. I found no current mainstream plugin that needs HTTP execution of PHP from uploads. This is UNVERIFIED as a universal claim.
- Filesystem includes are unaffected. That covers Twig and MailPoet caches, `.l10n.php` files and `wflogs`.

**Design consequence**
- Before enabling, list the existing `*.php` files under uploads in the response. This serves both as a breakage check and as a malware signal.
- Harden the regex as Sucuri does with `(?i:php)`. For example: `(?i)\.(php\d*|phtml|phar|pht)(\.|$)`.
- The reason is that mod_mime `AddHandler` extension matching is case-insensitive and supports multiple extensions. The proposed case-sensitive `\.php$` misses `x.PHP` and `x.php.xyz`.

## 11. Legitimate workflows broken by the archive rule

`FilesMatch` applies to the final rewritten filename. `ap_file_walk` tests the basename of `r->filename` after the internal redirect.

**Breaks, verified**
- **AI1WM:** direct download links. This is the known case the pause feature exists for.
- **WP Super Cache, Expert mode:** `.html.gz` pages return 403. See change A.
- **Precompressed asset rewrite:** the Apache-documented rewrite `^(.*)\.(css|js)` → `$1.$2.gz` breaks when it is used for wp-content assets. Source: https://httpd.apache.org/docs/2.4/mod/mod_deflate.html.
- **WP Fastest Cache, opt-in only:** the same rule is emitted when the constant `WPFC_GZIP_FOR_COMBINED_FILES` is defined (`inc/admin.php`, about line 723). It is not the default.
- **Media Library archives:** WP core allows them (`wp_get_mime_types`: tar, zip, gz|gzip, rar, 7z).

**Breaks, per vendor docs**
- **WooCommerce, "Redirect only" mode:** "redirected directly to the file URL". Force and X-Accel/X-Sendfile modes are unaffected. Source: https://woocommerce.com/document/digital-downloadable-product-handling/.
- **Duplicator Lite:** it downloads directly from `wp-content/backups-dup-lite/`, and its KB says the storage `.htaccess` "can interfere with downloads". Source: https://duplicator.com/knowledge-base/how-to-resolve-package-file-download-issues-404-3-corrupted-files/.
- **Rule gap:** `.daf`, `.tgz`, `.7z` and `.rar` are not covered by the rule.

**Safe, verified from source**
- **WP Rocket pages:** uses `.html_gzip`.
- **WP Rocket minified assets:** the `.css.gz`/`.js.gz` files "will not be served automatically". Source: https://docs.wp-rocket.me/article/1330-serve-pre-compressed-css-js-files.
- **W3TC:** uses `_gzip`/`_br` for both page cache and minify.
- **Cache Enabler:** its `.gz` files are served by PHP `readfile` in `cache_enabler_engine.class.php` line 482.
- **Autoptimize:** writes `.gz` only when the filter `autoptimize_filter_cache_create_static_gzip` is set. The default is false.
- **Plugin and theme updates:** they use PHP `download_url`.

**UNVERIFIED (believed to be streamed by PHP)**
- UpdraftPlus, BackWPup, the EDD default download mode, Elementor kit export, WP Migrate. Also Borlabs Cache and Swift Performance, where the concern is `.gz` cache files.

**Design consequence**
- Apply the narrowed pattern from change A.
- In preflight, read the root `.htaccess` and `wp-content/cache/.htaccess`, read-only. Look for RewriteRule targets ending in `.gz` and for `AddEncoding gzip .gz`. If found, refuse or use the narrowed pattern.
- Include in the status response the count of Media Library archive attachments and a flag for WooCommerce redirect mode, so the confirm modal can warn.