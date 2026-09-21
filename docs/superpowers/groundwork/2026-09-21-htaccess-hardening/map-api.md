# lsm-api implementation map — htaccess hardening, Part 2

Repo: `/Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api`. Branch `feature/htaccess-hardening` equals `main` plus one docs commit (`git diff --stat main..HEAD` shows only the spec file, 227 insertions). Stack: Laravel 12.44.0, PHP 8.3.22, Pest 4. Spec: `docs/superpowers/specs/2026-09-21-htaccess-hardening-design.md`, Part 2 at lines 163-186.

---

## 1. `app/Services/LsmService.php`

**Constants, constructor, factory, isConfigured** (`LsmService.php:12-35`):
```php
    protected const API_NAMESPACE = '/wp-json/lsm/v1';
    protected const DEFAULT_TIMEOUT = 30;
    protected const UPDATE_TIMEOUT = 120;

    protected Project $project;
    protected ?string $apiKey;
    protected string $baseUrl;

    public function __construct(Project $project)
    {
        $this->project = $project;
        $this->apiKey = $project->health_check_secret;
        $this->baseUrl = rtrim($project->url, '/') . self::API_NAMESPACE;
    }

    public static function for(Project $project): self
    {
        return new self($project);
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && !empty($this->project->url);
    }
```
`health_check_secret` is decrypted by the cast `'health_check_secret' => EncryptedString::class` (`app/Models/Project.php:64`).

**Helpers in full** (`LsmService.php:638-690`):
```php
    protected function get(string $endpoint, array $params = []): ?array
    {
        if (!$this->isConfigured()) return null;

        try {
            $response = Http::timeout(self::DEFAULT_TIMEOUT)
                ->withHeaders(['X-LSM-Key' => $this->apiKey])
                ->withOptions(['allow_redirects' => true])
                ->get($this->baseUrl . $endpoint, $params);

            return $this->handleResponse($response);
        } catch (\Exception $e) {
            Log::error("LSM API Error ({$endpoint}): {$e->getMessage()}");
            return null;
        }
    }

    protected function post(string $endpoint, array $data = [], ?int $timeout = null): ?array
    {
        if (!$this->isConfigured()) return null;

        try {
            // Authenticate via header — keeps the secret out of URLs and access logs.
            $response = Http::timeout($timeout ?? self::DEFAULT_TIMEOUT)
                ->withHeaders(['X-LSM-Key' => $this->apiKey])
                ->withOptions(['allow_redirects' => true])
                ->asJson() // Ensure JSON content type
                ->post($this->baseUrl . $endpoint, $data);

            return $this->handleResponse($response);
        } catch (\Exception $e) {
            Log::error("LSM API Error ({$endpoint}): {$e->getMessage()}");
            return null;
        }
    }

    protected function handleResponse(Response $response): ?array
    {
        if (!$response->successful()) {
            Log::warning("LSM API Error: {$response->status()} - {$response->body()}");
            return null;
        }

        $json = $response->json();
        
        // LSM standard response is { success: true, data: ... }
        if (isset($json['success']) && $json['success'] && isset($json['data'])) {
            return $json['data'];
        }
        
        // Some endpoints might return data directly or just success boolean
        return $json;
    }
```

**Auth.** Every request sends the header `X-LSM-Key: <health_check_secret>`, and the key never appears in the URL. This is pinned by `tests/Feature/LsmServiceAuthTest.php:22-25`. The plugin's `authenticate` permission callback accepts that header (`lsm-wp/landeseiten-maintenance/includes/class-lsm-api.php:373-383`).

**Timeout precedent:** `return $this->post('/updates/core', [], self::UPDATE_TIMEOUT);` (`LsmService.php:188`). `get()` takes no timeout argument and always uses 30 s.

**Return semantics — the success:false trap:**

| Situation | `get()` / `post()` returns |
|---|---|
| not configured (no secret or no url) | `null` |
| connection error, timeout, any exception | `null` (logged) |
| HTTP non-2xx, including WP 404 `rest_no_route` on an old plugin and any plugin 400 | `null`; status and body go to the log only, so the reason is lost |
| 2xx `{success:true, data:{…}}` | **only the inner `data`**; the `success` key is stripped |
| 2xx `{success:true}` without `data` | the raw JSON |
| 2xx `{success:false, …}` | the **raw envelope** as a non-empty array |

The consequences for hardening:
- Callers cannot assert `success === true`, because that key is gone on the success path. The only workable check is `($result['success'] ?? null) === false`.
- The standard controller guard `if (!$result)` lets a `success:false` envelope through as a success.
- This is the backup bug the spec mentions. `app/Jobs/CreateBackupJob.php:237-249` does `if (!$response) { return ['success' => false, …]; } return ['success' => true, …];`, so a `{success:false}` body counts as success.
- `null` cannot tell apart "not configured", "unreachable", "plugin older than 2.10 (404)" and "plugin rejected the input (400)".

**Security-settings methods** (`LsmService.php:463-477`):
```php
    /**
     * Get current security settings.
     */
    public function getSecuritySettings(): ?array
    {
        return $this->get('/security/settings');
    }

    /**
     * Update security settings.
     */
    public function updateSecuritySettings(array $settings): ?array
    {
        return $this->post('/security/settings', $settings);
    }
```
Plugin envelope for reference (`class-lsm-api.php:1894-1906`): `return rest_ensure_response(['success' => true, 'data' => [ 'comments_enabled' => …, ]]);`.

**New methods.** Place them after `getSecurityHeaderSnippets()` (`LsmService.php:490-493`). Plugin paths come from spec lines 153-156:
```php
public function getHardeningStatus(): ?array   { return $this->get('/hardening/status'); }
public function setHardeningRule(string $rule, bool $enabled): ?array
    { return $this->post('/hardening/rule', ['rule' => $rule, 'enabled' => $enabled], self::UPDATE_TIMEOUT); }
public function pauseHardening(int $minutes): ?array
    { return $this->post('/hardening/pause', ['minutes' => $minutes], self::UPDATE_TIMEOUT); }
public function resumeHardening(): ?array
    { return $this->post('/hardening/resume', [], self::UPDATE_TIMEOUT); }
```
Section 11 (items 1 and 5) explains why a raw variant that does not unwrap is safer.

---

## 2. `app/Http/Controllers/Api/V1/LsmController.php`

Imports (`LsmController.php:5-11`): `Controller, SyncWpAccountsJob, Project, LsmService, JsonResponse, Request, Gate`.

**Pattern to copy** (`LsmController.php:896-948`):
```php
    /**
     * Get current security settings.
     */
    public function getSecuritySettings(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $result = $lsm->getSecuritySettings();

        if (!$result) {
            return response()->json(['error' => 'Failed to fetch security settings'], 500);
        }

        return response()->json($result);
    }

    /**
     * Update security settings.
     */
    public function updateSecuritySettings(Project $project, Request $request): JsonResponse
    {
        Gate::authorize('update', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $settings = $request->only([
            'comments_enabled',
            'registration_enabled',
            'xmlrpc_enabled',
            'rest_api_public',
            'file_editing_disabled',
            'debug_enabled',
            'security_headers_enabled',
        ]);

        $result = $lsm->updateSecuritySettings($settings);

        if (!$result) {
            return response()->json(['error' => 'Failed to update security settings'], 500);
        }

        return response()->json($result);
    }
```
`updateSecuritySettings` does no validation (`$request->only`) and no `success` check.

**Validation convention:** an inline `$request->validate()` placed after `Gate::authorize`. This controller uses no FormRequest classes.
- `LsmController.php:218-222`:
```php
    public function updatePlugin(Project $project, Request $request): JsonResponse
    {
        Gate::authorize('update', $project);

        $request->validate(['slug' => 'required|string']);
```
- `LsmController.php:1057-1060`:
```php
        $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);
```

**Response envelope conventions:**
- Success is normally the bare plugin data, unwrapped: `response()->json($result)` (`:915`, `:947`).
- Only the newest methods wrap, using a strict null check (`LsmController.php:1040-1047`): `if ($result === null) { …500 }` followed by `return response()->json(['success' => true, 'data' => $result]);`.
- Errors are `['error' => 'LSM not configured']` with 400, and `['error' => 'Failed to …']` with 500.
- There is no 422 precedent in this controller. Laravel's validation 422 has the shape `{message, errors}`.
- An authorization failure returns the default 403 `{"message":"This action is unauthorized."}`. `bootstrap/app.php:35-39` passes API responses through untouched.
- Middleware errors use another shape: `{'success': false, 'message', 'code'}` (`app/Http/Middleware/EnsureTwoFactorEnrolled.php:43-47`).
- Do not copy `SecurityScanController`. It has no `Gate`/`authorize` call at all (grep for `authorize|Gate|->can(|abort(` returns nothing), and `tests/Feature/Scanner/ScanIdForwardingTest.php:82-86` shows a default-role viewer getting 200 on `POST …/lsm/security-scan`.

---

## 3. `routes/api.php`

Outer group (`routes/api.php:16`): `Route::prefix('v1')->name('api.v1.')->group(function () {`

Protected group (`routes/api.php:121`):
```php
    Route::middleware(['auth:sanctum', \App\Http\Middleware\RejectIntegrationTokens::class, \App\Http\Middleware\EnsureTwoFactorEnrolled::class])->group(function () {
```
LSM group (`routes/api.php:227`): `Route::prefix('projects/{project}/lsm')->name('projects.lsm.')->group(function () {`. It closes at `:302`.

Surrounding block (`routes/api.php:284-301`):
```php
            // Security Settings
            Route::get('/security-settings', [V1\LsmController::class, 'getSecuritySettings'])->name('security-settings');
            Route::post('/security-settings', [V1\LsmController::class, 'updateSecuritySettings'])->name('security-settings.update');
            Route::get('/security-headers', [V1\LsmController::class, 'getSecurityHeaders'])->name('security-headers');
            Route::get('/security-headers/snippets', [V1\LsmController::class, 'getSecurityHeaderSnippets'])->name('security-headers.snippets');

            // Security Scanning
            Route::post('/security-scan', [V1\SecurityScanController::class, 'scan'])->name('security-scan');
            …
            // Media Library
            Route::get('/unused-media', [V1\LsmController::class, 'getUnusedMedia'])->name('unused-media');
            Route::post('/delete-media', [V1\LsmController::class, 'deleteMedia'])->name('delete-media');
        });
```
- Full URL today: `GET|POST /api/v1/projects/{project}/lsm/security-settings`. Route names are `api.v1.projects.lsm.security-settings` and `api.v1.projects.lsm.security-settings.update`. `{project}` binds implicitly by id.
- Insert the new routes after line 288 and before the `// Security Scanning` comment:
```php
            // Server hardening (.htaccess)
            Route::get('/hardening', [V1\LsmController::class, 'getHardening'])->name('hardening');
            Route::post('/hardening/rule', [V1\LsmController::class, 'setHardeningRule'])->name('hardening.rule');
            Route::post('/hardening/pause', [V1\LsmController::class, 'pauseHardening'])->name('hardening.pause');
            Route::post('/hardening/resume', [V1\LsmController::class, 'resumeHardening'])->name('hardening.resume');
```
- That gives `/api/v1/projects/{project}/lsm/hardening[/rule|/pause|/resume]`.
- Do not use the `role:admin` middleware for the admin-only action. `CheckRole` compares `role` only, `if (!in_array($request->user()->role, $roles))` (`app/Http/Middleware/CheckRole.php:23`), so it ignores the `is_admin` flag the spec requires.

---

## 4. `app/Policies/ProjectPolicy.php`

The policy is auto-discovered. `AppServiceProvider.php:54-56` registers only the TimeEntry, Timesheet and Invoice policies.

`before()` (`ProjectPolicy.php:14-20`):
```php
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        return null;
    }
```
`view()` (`:35-53`):
```php
    public function view(User $user, Project $project): bool
    {
        // Managers see every project while permissions.managers_view_all_projects
        // is on (visibility only — update/delete/credentials stay assignment-scoped).
        if ($user->canViewAllProjects()) {
            return true;
        }

        if ($user->role === 'manager') {
            return $project->isManagedBy($user);
        }

        if ($user->role === 'developer') {
            return $project->developer_id === $user->id
                || $project->developers()->where('user_id', $user->id)->exists();
        }

        return false;
    }
```
`managesProject()` (`:69-72`):
```php
    private function managesProject(User $user, Project $project): bool
    {
        return $project->isManagedBy($user);
    }
```
`update()` (`:78-91`):
```php
    public function update(User $user, Project $project): bool
    {
        if ($user->role === 'manager') {
            return $this->managesProject($user, $project);
        }
        
        if ($user->role === 'developer') {
            // Check both legacy single developer and many-to-many relationship
            return $project->developer_id === $user->id 
                || $project->developers()->where('user_id', $user->id)->exists();
        }
        
        return false;
    }
```
`manageCredentials()` (`:127-139`):
```php
    public function manageCredentials(User $user, Project $project): bool
    {
        if ($user->role === 'admin' || $user->is_admin) {
            return true;
        }

        if ($user->role === 'manager') {
            return $this->managesProject($user, $project);
        }

        // Developers cannot manage credentials
        return false;
    }
```
`assignTeam()` (`:144-147`):
```php
    public function assignTeam(User $user, Project $project): bool
    {
        return $user->role === 'manager' && $this->managesProject($user, $project);
    }
```
Admin-only precedent (`:118-121`): `public function forceDelete(...): bool { return false; // Only admins via before() }`.

`Project::isManagedBy` (`app/Models/Project.php:193-197`):
```php
    public function isManagedBy(User $user): bool
    {
        return $this->manager_id === $user->id
            || $this->managers()->where('user_id', $user->id)->exists();
    }
```
The pivot tables are `project_manager` (`Project.php:180`) and `project_developer` (`Project.php:212`).

User helpers (`app/Models/User.php`):
```php
    public function isAdmin(): bool            // :85-88
    {
        return $this->role === 'admin' || $this->is_admin;
    }
    public function isManager(): bool          // :115-118
    {
        return $this->role === 'manager';
    }
    public function canViewAllProjects(): bool // :126-130
    {
        return $this->isAdmin()
            || ($this->isManager() && config('permissions.managers_view_all_projects', false));
    }
    public function isDeveloper(): bool        // :135-138
    {
        return $this->role === 'developer';
    }
```
The role enum is `['admin', 'manager', 'developer', 'viewer']` with default `viewer` (`database/migrations/0001_01_01_000000_create_users_table.php:20`).

**Who passes `update` today:**
- **Admin:** `role === 'admin'`, or any role with `is_admin = true`. `before()` grants every ability and the ability method never runs. This includes a manager who holds the flag.
- **Manager:** only when `isManagedBy()` is true, through the legacy `projects.manager_id` or a `project_manager` pivot row. An unassigned manager gets 403.
- **Developer:** only when `projects.developer_id === id` or a `project_developer` pivot row exists.
- **Viewer:** never, and a viewer also fails `view`.

**MANAGERS_VIEW_ALL_PROJECTS:**
- `config/permissions.php:23` reads it with a default of true.
- Only `view()` line 39 consults it, through `canViewAllProjects()`.
- `update`, `delete`, `manageCredentials` and `assignTeam` ignore it.
- `tests/Feature/ManagerSeesAllProjectsTest.php:66-77` pins that an unassigned manager still gets 403 on PUT.
- `phpunit.xml:42` pins the flag to `true` with `force="true"`.
- With `view` on the GET, every manager can read every project's hardening status, while all three POSTs stay assignment-scoped for non-admins. `can` must therefore be computed per user and per project.

**The three abilities, given that `before()` short-circuits.** Add these after `assignTeam()`. The file's style reads `$user->role` directly, as in `:146`:
```php
    /** Pause/resume the archive rule: anyone with update on the project. */
    public function pauseHardening(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }

    /** Turn a rule on / adopt a manual rule: managers who manage the project (admins via before()). */
    public function enableHardening(User $user, Project $project): bool
    {
        return $user->role === 'manager' && $this->managesProject($user, $project);
    }

    /** Turn a rule off: admins only — granted exclusively by before(). */
    public function disableHardening(User $user, Project $project): bool
    {
        return false;
    }
```
Resulting matrix:

| User | pause | enable | disable |
|---|---|---|---|
| admin role or `is_admin` flag | ✔ | ✔ | ✔ |
| manager who manages the project | ✔ | ✔ | ✘ |
| assigned developer | ✔ | ✘ | ✘ |
| unassigned manager | ✘ (GET works if the flag is on) | ✘ | ✘ |
| unassigned developer, viewer | ✘ (and `view` 403) | ✘ | ✘ |

Test caveat: `tests/Feature/ProjectManagerLegacyPolicyTest.php:19-20,35` calls `new ProjectPolicy()` directly, and its comment says "before() (admin bypass) is not involved". Admin assertions must go through the Gate (`$admin->can('disableHardening', $project)`) or through HTTP. `(new ProjectPolicy)->disableHardening($admin, …)` returns false.

---

## 5. How the frontend learns abilities today

It does not get them from the API. No per-project `can`, `permissions` or `abilities` block exists.
- `grep -rn "'can'\|abilities\|permissions\|->can(\|Gate::" app/Http/Resources` has one hit, and it is unrelated (Sanctum token scopes): `app/Http/Resources/IntegrationTokenResource.php:23: 'scopes' => $this->abilities ?? [],`.
- The same grep over `app/Http/Controllers` hits only `ScannerCollectorController.php:101,121-122`, where "permissions" is a scan-findings key.
- `ProjectResource` exposes only the raw ingredients (`app/Http/Resources/ProjectResource.php:66-71`): `'manager_id'`, `'manager'`, `'managers' => UserResource::collection($this->whenLoaded('managers'))`, `'developer_id'`, `'developer'`, `'developers'`.
- `UserResource.php:27-28` exposes `'role' => $this->role, 'is_admin' => (bool) $this->is_admin`.
- The web app derives rights on the client (`lsm-web/src/features/projects/pages/ProjectDetailPageV2.tsx:234`): `const canEdit = isAdmin || (currentUser?.role === 'manager' && (project.manager_id === currentUser?.id || project.managers?.some(m => m.id === currentUser?.id)));`

The spec's `can: {pause, enable, disable}` in the status response (spec line 204) is therefore a new convention. Build it in the GET handler:
```php
'can' => [
    'pause'   => Gate::allows('pauseHardening', $project),
    'enable'  => Gate::allows('enableHardening', $project),
    'disable' => Gate::allows('disableHardening', $project),
],
```
Merge it into the unwrapped plugin status with `array_merge`. `Gate::allows` runs `before()`, so admins get `true` for all three.

---

## 6. Migrations and models

- Naming: `YYYY_MM_DD_HHMMSS_<verb>_<thing>.php`, using the anonymous class form `return new class extends Migration`. The directory holds 72 files.
- Latest three:
  - `2026_07_31_090200_widen_credentials_username_to_text.php`
  - `2026_08_04_120000_backfill_session_token_expiry.php`
  - `2026_08_04_120100_add_type_and_ip_to_personal_access_tokens.php`
- Suggested name for the new one: `2026_09_21_120000_create_project_hardening_pauses_table.php`.

Example of a small table with foreign keys (`database/migrations/2026_07_16_000001_create_support_ticket_messages_table.php:7-28`):
```php
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->string('author_type', 10); // 'client' | 'staff' (string, not enum — SQLite tests)
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_name')->default('');
            $table->text('message');
            $table->timestamps();

            $table->index(['support_ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
    }
};
```
Suggested columns for `project_hardening_pauses`:
- `id`
- `foreignId('project_id')->constrained()->cascadeOnDelete()`
- `foreignId('user_id')->nullable()->constrained()->nullOnDelete()`
- `timestamp('paused_until')`
- `timestamp('resumed_at')->nullable()`
- `timestamps()`
- `index(['resumed_at', 'paused_until'])`

The auto-generated index name is 55 characters, under MySQL's 64 limit.

Small model example, whole file (`app/Models/SupportTicketMessage.php:12-36`):
```php
class SupportTicketMessage extends Model
{
    protected $fillable = [
        'support_ticket_id',
        'author_type',
        'user_id',
        'author_name',
        'message',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    …
}
```
Casts use the method form, not a `$casts` property (`app/Models/EphemeralSecret.php:22-30`): `protected function casts(): array { return [ … 'expires_at' => 'datetime', 'viewed_at' => 'datetime', ]; }`.

Other conventions:
- Models use `$fillable`, never `$guarded`.
- `HasFactory` appears only where a factory exists. `database/factories` has Credential, EphemeralSecret, Project, SecurityScan, Todo and User.
- Relationships carry typed returns.
- The app timezone is UTC (`config/app.php:70`). The plugin's `pause_until` is a unix int, so convert it with `Carbon::createFromTimestamp()`.
- `Project` and `User` both use `SoftDeletes` (`Project.php:18`, `User.php:18`). An open pause row can point at a trashed project, in which case `$pause->project` is `null`. The command must handle that.

---

## 7. Scheduler and command template

Two entries showing the conventions (`routes/console.php:51-57` and `:196-201`):
```php
Schedule::command('sites:check-ssl-expiry')
    ->dailyAt('09:00')
    ->timezone('Europe/Berlin')
    ->withoutOverlapping()
    ->runInBackground()
    ->name('ssl-expiry-check')
    ->onOneServer();
```
```php
Schedule::command('todos:send-due-reminders')
    ->hourly()
    ->timezone('Europe/Berlin')
    ->withoutOverlapping()
    ->name('todo-due-reminders')
    ->onOneServer();
```
The high-frequency precedent is uptime (`routes/console.php:76-81`): `->cron("*/{$uptimeInterval} * * * *")->withoutOverlapping()->runInBackground()->name('live-uptime-monitoring')->onOneServer();`. No entry uses `everyTenMinutes()` yet.

**Mutex expiry in use today.**
- Every `withoutOverlapping()` call in `routes/console.php` is bare. They are at lines 25, 31, 37, 54, 78, 107, 118, 135, 163, 182 and 199.
- `grep -rn "withoutOverlapping(" routes app | grep -v "withoutOverlapping()"` returns nothing, so no entry sets an explicit expiry.
- The framework default is 1440 minutes, 24 h: `public function withoutOverlapping($expiresAt = 1440)` (`vendor/laravel/framework/src/Illuminate/Console/Scheduling/ManagesAttributes.php:145`).
- The follow-up from the August incident has not been applied: a stuck `sites:check-uptime` mutex meant no rows were written from 25 to 28 Aug until `php artisan schedule:clear-cache` cleared it, and the recorded follow-up was a shorter expiry. `grep -rni mutex app routes docs` returns nothing.
- The production mutex lives in the cache store, and `.env.example:40` sets `CACHE_STORE=database`. The incident note records that Hostinger can kill a process mid-run, which leaves the mutex held.
- For this job a stuck mutex means a paused archive rule is not re-enabled by the platform for up to 24 h. The plugin's own expiry on plugin load is the only backstop. Use an explicit short expiry:
```php
Schedule::command('hardening:resume-expired')
    ->everyTenMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->name('hardening-resume-expired')
    ->onOneServer();
```
- `routes/console.php:144-146` says: "Queue is set to 'sync' driver on Hostinger shared hosting … Jobs execute immediately inline." Do the work inline in the command and do not dispatch queued jobs.

**Command that loops over projects and calls LsmService** (`app/Console/Commands/RunSecurityScans.php:12-66`, trimmed):
```php
class RunSecurityScans extends Command
{
    protected $signature = 'security:scan
                            {--project= : Scan a specific project by ID}
                            {--quick : Run a quick scan instead of full}
                            {--notify : Send notifications for threats}';

    protected $description = 'Run automated security scans on connected WordPress sites';

    public function handle(): int
    {
        …
            $projects = Project::whereNotNull('health_check_secret')
                ->whereNotNull('url')
                ->get();
        …
        foreach ($projects as $project) {
            $this->info("Scanning: {$project->name} ({$project->url})");

            $lsm = LsmService::for($project);

            if (!$lsm->isConfigured()) {
                $this->warn("  ⏭ Skipping: LSM not configured");
                continue;
            }
```
- The loop wraps each project in try/catch and ends with `usleep(500000); // 500ms` "to avoid overwhelming shared hosting" (`:139-150`). It returns `$failCount > 0 ? 1 : 0` (`:156`).
- Minimal template (`app/Console/Commands/PurgeEphemeralSecrets.php:8-27`): `protected $signature = 'ephemeral-secrets:purge';`, `public function handle(): int { … $this->info("Purged {$count} …"); return self::SUCCESS; }`.
- Commands are auto-discovered from `app/Console/Commands`.
- Sibling jobs and commands filter archived projects with `->where('status', '!=', 'archived')` (`CheckSiteUptime.php:61`, `SyncPhpErrorsJob.php:57`). The column is `status`. There is no `archived` boolean (`tests/Feature/ScheduledJobsArchivedFilterTest.php:10-16`).

---

## 8. Tests

**Env** (`phpunit.xml:21-42`):
- `APP_ENV=testing`, `CACHE_STORE=array`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `MAIL_MAILER=array`, `QUEUE_CONNECTION=sync`, `SESSION_DRIVER=array`.
- Pinned flags:
  - `<env name="MFA_ENFORCED_ROLES" value=""/>` (line 35, not forced).
  - `BACKUP_SCHEDULE_ENABLED=false force="true"` (37).
  - `BACKUP_ENABLED=true force="true"` (40).
  - `MANAGERS_VIEW_ALL_PROJECTS=true force="true"` (42).
- Pest is wired in `tests/Pest.php:14-16`: `pest()->extend(Tests\TestCase::class)->use(Illuminate\Foundation\Testing\RefreshDatabase::class)->in('Feature');`. Every Feature test runs all 72 migrations on SQLite.
- `tests/TestCase.php` is empty and there are no helper traits. The only global helper is `actingWithScopes()` (`tests/Pest.php:54-59`), which is for MCP scope tests.
- Helper functions in Pest files are global PHP functions. Existing names include `scanForwardingProject`, `legacyOnlySetup`, `validCredentialPayload`, `validProjectPayload`, `seedActiveAndArchivedProjects`, `monitoredProject` and `makeUnmanagedProject`. Pick unique names or you get "Cannot redeclare".

**SQLite gotcha, as documented:**
- `docs/superpowers/plans/2026-07-16-advanced-ticketing-api.md:19`: "Use string columns (not DB enums) for new tables — SQLite test harness."
- `docs/superpowers/plans/2026-08-04-mcp-integration-tokens.md:15`: "**Tests run on SQLite `:memory:`** (`phpunit.xml:26-27`). No raw MySQL SQL in migrations — this repo has been bitten before. Use driver-agnostic PHP-side data manipulation."
- Migration comment at `2026_07_16_000001…:14`: `(string, not enum — SQLite tests)`.
- Schema tests skip on SQLite: `tests/Feature/TodoStatusColumnSchemaTest.php:7` has `markTestSkipped('enum CHECK not representable on sqlite harness')`.
- For the new table: no enums, no raw SQL, and date comparisons through Eloquent/Carbon only.

**Faking the plugin.**

Service-level fake (`tests/Feature/LsmServiceAuthTest.php:8-25`):
```php
    Http::fake([
        '*' => Http::response(['success' => true, 'data' => ['ok' => true]], 200),
    ]);

    $project = Project::factory()->create([
        'url' => 'https://client.example.com',
        'health_check_secret' => 'SECRETKEY123',
    ]);
    …
    Http::assertSent(function ($request) {
        return $request->hasHeader('X-LSM-Key', 'SECRETKEY123')
            && ! str_contains($request->url(), 'SECRETKEY123');
    });
```
Controller hitting an LSM route (`tests/Feature/Scanner/ScanIdForwardingTest.php:76-94`):
```php
    Http::fake([
        '*' => Http::response(['success' => true, 'status' => 'clean', 'summary' => []], 200),
    ]);
    $project = scanForwardingProject();
    $user = \App\Models\User::factory()->create();

    $this->actingAs($user)
        ->postJson("/api/v1/projects/{$project->id}/lsm/security-scan", ['scan_type' => 'standard'])
        ->assertOk();
    …
    Http::assertSent(function ($request) use ($scanId) {
        return str_contains($request->url(), '/security/scan')
            && $request['scan_id'] === $scanId;
    });
```
URL-pattern fakes (`tests/Feature/UptimeMonitoringTest.php:159-170`): `'site.example.com/wp-json/lsm/v1/health*' => Http::response([...], 200), '*' => Http::response('ok', 200),`.

Command through the faked plugin (`ScanIdForwardingTest.php:97-104`): `$this->artisan('security:scan')->assertExitCode(0);`.

Other patterns:
- Unreachable-site precedent is `Http::fake(['*' => Http::response('server error', 500)])` (`UptimeMonitoringTest.php:31`).
- `Http::failedConnection()` and `Http::sequence()` exist in the framework (`vendor/laravel/framework/src/Illuminate/Http/Client/Factory.php:207,223`) but are unused in tests so far.
- `Http::preventStrayRequests()` is used at `tests/Feature/RejectIntegrationTokensTest.php:56`.
- For hardening, fake per endpoint, for example `'*/wp-json/lsm/v1/hardening/status*'` and `'*/hardening/resume*'`.
- A project is "configured" only when both `url` and `health_check_secret` are set. `ProjectFactory` does not set the secret.

**Users per role and assignment:**
- The factory default is `'role' => 'viewer'` (`database/factories/UserFactory.php:32`). States `admin()`, `manager()` and `developer()` are at `:49-74`.
- Most tests write `User::factory()->create(['role' => 'manager'])`.
- For an `is_admin` flag holder use `User::factory()->create(['role' => 'manager', 'is_admin' => true])`.
- Assignment patterns (`tests/Feature/ProjectManagerLegacyAuthzTest.php:27-29, 52-54, 68-70`):
```php
    $manager = User::factory()->create(['role' => 'manager']);
    $project = Project::factory()->create(['manager_id' => $manager->id]);   // legacy column
    …
    $project->managers()->attach($manager->id);                              // pivot
    …
    $developer = User::factory()->create(['role' => 'developer']);
    $project = Project::factory()->create(['developer_id' => $developer->id]);
    $project->developers()->attach($developer->id);
```
- Factory states `withManager(User)` and `withDeveloper(User)` also exist (`ProjectFactory.php:64-79`).

**MFA gate:**
- `EnsureTwoFactorEnrolled` sits in the route group (`api.php:121`) and blocks users where `mustEnrollTwoFactor()` is true (`User.php:95-110`).
- In tests `MFA_ENFORCED_ROLES=""` makes `config('auth.mfa_enforced_roles')` empty (`config/auth.php:126-129`), so nobody is enforced. Feature tests use plain `$this->actingAs($user)` with no 2FA setup.
- `tests/Feature/MfaEnforcementTest.php:7` re-enables it per file with `beforeEach(fn () => config(['auth.mfa_enforced_roles' => ['admin']]));`.
- To be robust against an exported shell variable, since line 35 is not forced, create admins with `'two_factor_confirmed_at' => now()` as in `MfaEnforcementTest.php:30`.
- `RejectIntegrationTokens` does not affect `actingAs()`, because there is no PersonalAccessToken (`RejectIntegrationTokens.php:46-48`).

**Commands:**
- One file: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan test tests/Feature/HardeningTest.php`, or `./vendor/bin/pest tests/Feature/HardeningTest.php`.
- By name: `php artisan test --filter=HardeningTest`.
- `composer test` runs `config:clear` and then `php artisan test` (`composer.json:58-61`).
- `bootstrap/cache` holds only `packages.php` and `services.php`, so no cached config shadows the phpunit env.

**Run results.**
- No security-settings test exists. `grep -rn "security-settings\|securitySettings\|SecuritySettings" tests` returns nothing.
- I ran the nearest tests instead, with `--do-not-cache-result` so nothing was written. `git status --short` was empty before and after.
  - `php artisan test --filter=LsmServiceAuthTest --do-not-cache-result`: PASS, 1 passed (1 assertion), 0.53 s.
  - `php artisan test --do-not-cache-result tests/Feature/ProjectManagerLegacyPolicyTest.php tests/Feature/Scanner/ScanIdForwardingTest.php`: PASS, 12 passed (48 assertions), 1.67 s.
  - `php artisan test --do-not-cache-result tests/Feature/Auth`: PASS, 11 passed (41 assertions). The older note about failing Breeze Auth tests is stale. The directory now holds ChangePasswordTest, EmailTwoFactorLoginTest, RegistrationTest and TwoFactorTest.
- I did not run the full suite.

---

## 9. MCP

- No MCP tool exposes security settings or hardening. `grep -rniE "security.?settings|SecuritySettings|hardening|htaccess" app/Mcp` returns nothing.
- The only "security" hits under `app/Mcp` are the `security_status` filter in `ListProjectsTool.php:26,40,57-61`, a `security_status` default in `CreateProjectTool.php:90`, and template names in `ApplyTodoTemplateTool.php:28,150`.
- The `Wp*` tools cover cache, maintenance, updates, backups, PHP errors, login, emergency recovery and database optimization. `BulkWpActionTool` calls no security endpoint.
- Nothing needs to change for hardening.

---

## 10. Previous plans

Plans live in `docs/superpowers/plans/`. Newest three:
- `2026-08-04-mcp-integration-tokens.md`
- `2026-07-22-ticket-ui-redesign.md`
- `2026-07-18-remote-malware-scanner.md`

Specs live in `docs/superpowers/specs/`. The newest is `2026-09-21-htaccess-hardening-design.md`, preceded by `2026-08-04-mcp-integration-tokens-design.md`. The plans carry a "Tech Stack / conventions" header block worth mirroring (for example `2026-08-04-mcp-integration-tokens.md:9-15`).

---

## 11. Where Part 2 conflicts with the codebase

1. **`success:false` → 422 (spec:186) cannot be built by copying the security-settings pattern.**
   - `handleResponse()` strips `success` on success and returns the raw envelope on failure (`LsmService.php:684-689`).
   - The controller idiom `if (!$result)` followed by `return response()->json($result)` (`LsmController.php:943-947`) would forward `{success:false,…}` as HTTP 200.
   - Each hardening POST needs an explicit branch: `null` means unreachable or error; `($result['success'] ?? null) === false` means 422; anything else is the unwrapped status.
   - A cleaner alternative is a raw, non-unwrapping helper in `LsmService` that returns `[status, json]`.
   - Use `=== null`, not `!$result`, as `getUnusedMedia` already does (`:1040`).

2. **The 422 body shape collides with validation.**
   - Laravel validation already returns 422 `{message, errors}` on the same endpoints, for a bad `rule` or `minutes`.
   - The controller's error convention is `{'error': '…'}`.
   - Define a distinct body, for example `{'error': 'hardening_rolled_back', 'reason': <plugin reason>, 'status': <fresh status>, 'last_result': …}`, so the web hook can tell the two 422s apart.
   - In a failure envelope the fresh status sits under `$result['data']`. On success it is `$result` itself.

3. **Plugin-side 4xx responses lose their reason.**
   - `handleResponse()` turns any non-2xx into `null` and only logs it (`:676-679`).
   - The API must validate inputs itself, mirroring the plugin: `'rule' => 'required|string|in:block_archives,block_debug_log,block_uploads_php'`, `'enabled' => 'required|boolean'`, `'minutes' => 'required|integer|in:15,30,60'`.

4. **One route needs two abilities.**
   - `POST /hardening/rule` needs `enableHardening` when `enabled=true` and `disableHardening` when `enabled=false`.
   - The controller convention is authorize first, then validate (`:220-222`), but here the ability depends on the payload.
   - A fail-closed form: `$enabled = $request->boolean('enabled'); Gate::authorize($enabled ? 'enableHardening' : 'disableHardening', $project);`, then `$request->validate(...)`. A missing or garbage `enabled` is treated as disable, so a non-admin gets 403 and an admin gets the 422.

5. **Plugins older than 2.10.0 are indistinguishable from unreachable sites.**
   - Old plugins return 404 `rest_no_route`, which becomes `null`, and the copied pattern turns that into HTTP 500 "Failed to fetch…".
   - The spec defines `unsupported` per rule but no "plugin too old" state for the panel.
   - Either add a raw status-code-aware call in `LsmService` and return 200 `{supported:false, reason:'plugin_outdated'}`, or accept a generic 500 on every not-yet-updated site.
   - The same ambiguity affects the command. The spec's "Unreachable site → leave open, retry" (spec:183-184) would also retry forever against a downgraded or removed plugin.

6. **`can` in the status response has no precedent.**
   - The GET handler must merge platform-computed keys (`can`, `pause_overdue`) into the unwrapped plugin status array.
   - The GET is authorized with `view`, which includes every manager while the flag is on. An unassigned manager must therefore get 200 with all `can` values false, and 403 on the POSTs.
   - Add that case to the permission-matrix test.

7. **`disableHardening` "admins (isAdmin(), incl. is_admin flag)".**
   - The policy method body must be `return false;` because `before()` already grants admins.
   - Do not implement it with the `role:admin` middleware (`CheckRole.php:23` ignores `is_admin`).
   - Do not copy `manageCredentials()`'s inline `$user->role === 'admin' || $user->is_admin` check. It is redundant dead code under `before()`.
   - Direct-instantiation policy tests will see `false` for admins (see section 4).

8. **Scheduler: "`withoutOverlapping`" with no expiry (spec:181-182) repeats the August incident pattern.**
   - The default is 1440 minutes and no entry in the repo overrides it.
   - Use `withoutOverlapping(10)` together with `->name()` and `->onOneServer()`, per house style.
   - POSTs use the 120 s `UPDATE_TIMEOUT` and status GETs use 30 s, run sequentially and inline because the queue is `sync` in production. One run can take up to about 150 s per overdue site.

9. **Pause-row lifecycle gaps.** The spec says "written on pause, closed on resume" and leaves these open:
   - Write the row only after the plugin call succeeds. A `success:false` pause rolled back with `pause_ineffective_foreign_rule` must not leave an open row.
   - Pausing again while an open row exists: update the open row's `paused_until` and `user_id`, or close it and create a new one. Make sure only one open row exists per project.
   - When the plugin auto-resumes on load, the command sees status `on` rather than `paused`. The spec only closes the row "on success" of a resume call, so this row would stay open forever and `pause_overdue` would stay true. The command should close the row whenever the status is reachable and no longer `paused`.
   - An admin turning `block_archives` off during a pause should also close the open row.
   - Skip rows whose project is soft-deleted (`$pause->project === null`), `status = 'archived'`, or no longer configured.
   - Define `pause_overdue` concretely: an open row for the project with `paused_until < now()`, optionally combined with the plugin status still being `paused`.

10. **The `user_id` FK.** `User` is soft-deleted, so a hard FK rarely fires. Follow the existing pattern `foreignId('user_id')->nullable()->constrained()->nullOnDelete()` rather than the non-null column the spec implies. `project_id` should be `->constrained()->cascadeOnDelete()`. Use timestamps for `paused_until` and `resumed_at`, and no enums.

11. **No Part 2 item conflicts with MCP, with `RejectIntegrationTokens`, or with MFA in tests.** Placing the routes inside the group at `api.php:227` inherits `auth:sanctum`, `RejectIntegrationTokens` and `EnsureTwoFactorEnrolled` automatically.