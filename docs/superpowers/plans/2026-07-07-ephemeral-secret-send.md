# Ephemeral Secret Send — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let any signed-in team member send a structured secret via a one-time, expiring, encrypted public link that is not tied to any project.

**Architecture:** A dedicated `EphemeralSecret` model (backend, `lsm-api`) stores the secret encrypted at rest, revealed exactly once (burn-after-read) then wiped, with a daily purge of tombstones. A React modal (frontend, `lsm-web`) creates the link; a public reveal page shows it once.

**Tech Stack:** Laravel 12 + Pest (backend), React + Ant Design + TanStack Query + Zustand (frontend), Vite build for typecheck.

## Global Constraints

- Backend tests: Pest, SQLite in-memory (`./vendor/bin/pest`). Frontend has **no** test runner — verify with `npm run build` (typecheck) + `npm run lint` + a manual reveal check.
- `payload` MUST be cast `encrypted:array`; `access_password` MUST be cast `hashed`; never log the payload.
- Public reveal routes MUST be throttled. Recipient link path is `/s/{token}`.
- Any authenticated role may create (no Gate). The reveal endpoints are public.
- Frontend link base: `config('app.frontend_url')` (already defined in `config/app.php:57`).
- Follow existing patterns: controllers extend `App\Http\Controllers\Api\V1\Controller` and use `successResponse`/`errorResponse`/`createdResponse`.

---

### Task 1: Migration, model, and factory

**Files:**
- Create: `database/migrations/2026_07_07_130000_create_ephemeral_secrets_table.php`
- Create: `app/Models/EphemeralSecret.php`
- Create: `database/factories/EphemeralSecretFactory.php`
- Test: `tests/Feature/EphemeralSecretModelTest.php`

**Interfaces:**
- Produces: `App\Models\EphemeralSecret` with fillable `token, created_by, title, payload, access_password, expires_at, viewed_at, last_viewed_ip`; casts `payload => encrypted:array`, `access_password => hashed`, `expires_at/viewed_at => datetime`; methods `isExpired(): bool`, `isBurned(): bool`, `isAvailable(): bool`; relation `creator()`.
- Produces: `EphemeralSecretFactory`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/EphemeralSecretModelTest.php
use App\Models\EphemeralSecret;
use Illuminate\Support\Facades\DB;

test('payload is encrypted at rest and readable as an array', function () {
    $secret = EphemeralSecret::create([
        'token' => 'tok_'.uniqid(),
        'title' => 'Staging FTP',
        'payload' => ['username' => 'deploy', 'password' => 'p@ss;word'],
        'expires_at' => now()->addHour(),
    ]);

    $raw = DB::table('ephemeral_secrets')->where('id', $secret->id)->value('payload');
    expect($raw)->not->toContain('deploy');            // encrypted
    expect($secret->fresh()->payload)->toBe(['username' => 'deploy', 'password' => 'p@ss;word']);
});

test('availability helpers reflect expiry and burn state', function () {
    $live = EphemeralSecret::factory()->create(['expires_at' => now()->addHour(), 'viewed_at' => null]);
    expect($live->isAvailable())->toBeTrue();

    $expired = EphemeralSecret::factory()->create(['expires_at' => now()->subMinute()]);
    expect($expired->isAvailable())->toBeFalse();
    expect($expired->isExpired())->toBeTrue();

    $burned = EphemeralSecret::factory()->create(['viewed_at' => now()]);
    expect($burned->isAvailable())->toBeFalse();
    expect($burned->isBurned())->toBeTrue();
});
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `./vendor/bin/pest tests/Feature/EphemeralSecretModelTest.php`
Expected: FAIL — `Class "App\Models\EphemeralSecret" not found`.

- [ ] **Step 3: Create the migration**

```php
<?php
// database/migrations/2026_07_07_130000_create_ephemeral_secrets_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ephemeral_secrets', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title')->nullable();
            $table->text('payload')->nullable();          // encrypted JSON, nulled on burn
            $table->string('access_password')->nullable(); // bcrypt hash
            $table->timestamp('expires_at');
            $table->timestamp('viewed_at')->nullable();
            $table->string('last_viewed_ip', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ephemeral_secrets');
    }
};
```

- [ ] **Step 4: Create the model**

```php
<?php
// app/Models/EphemeralSecret.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class EphemeralSecret extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'token', 'created_by', 'title', 'payload',
        'access_password', 'expires_at', 'viewed_at', 'last_viewed_ip',
    ];

    protected $hidden = ['payload', 'access_password'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'access_password' => 'hashed',
            'expires_at' => 'datetime',
            'viewed_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isBurned(): bool
    {
        return $this->viewed_at !== null;
    }

    public function isAvailable(): bool
    {
        return ! $this->isExpired() && ! $this->isBurned();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        // Title only — the secret payload is never logged.
        return LogOptions::defaults()->logOnly(['title'])->dontSubmitEmptyLogs();
    }
}
```

- [ ] **Step 5: Create the factory**

```php
<?php
// database/factories/EphemeralSecretFactory.php
namespace Database\Factories;

use App\Models\EphemeralSecret;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class EphemeralSecretFactory extends Factory
{
    protected $model = EphemeralSecret::class;

    public function definition(): array
    {
        return [
            'token' => Str::random(40),
            'created_by' => null,
            'title' => 'Test secret',
            'payload' => ['password' => 'secret-value'],
            'access_password' => null,
            'expires_at' => now()->addHour(),
            'viewed_at' => null,
            'last_viewed_ip' => null,
        ];
    }
}
```

- [ ] **Step 6: Run the test and confirm it passes**

Run: `./vendor/bin/pest tests/Feature/EphemeralSecretModelTest.php`
Expected: PASS (all cases).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_07_130000_create_ephemeral_secrets_table.php \
        app/Models/EphemeralSecret.php \
        database/factories/EphemeralSecretFactory.php \
        tests/Feature/EphemeralSecretModelTest.php
git commit -m "feat: ephemeral secret model, migration, factory"
```

---

### Task 2: Create endpoint (`store`)

**Files:**
- Create: `app/Http/Controllers/Api/V1/EphemeralSecretController.php` (store method + `unavailableReason` helper; show/access added in later tasks)
- Modify: `routes/api.php` (add the authenticated create route)
- Test: `tests/Feature/EphemeralSecretStoreTest.php`

**Interfaces:**
- Consumes: `App\Models\EphemeralSecret` (Task 1).
- Produces: `POST /api/v1/ephemeral-secrets` returning `{ success, data: { link, expires_at } }`; `EphemeralSecretController::store(Request): JsonResponse`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/EphemeralSecretStoreTest.php
use App\Models\EphemeralSecret;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('any role can create an ephemeral secret and gets a link', function () {
    foreach (['admin', 'manager', 'developer'] as $role) {
        $user = User::factory()->create(['role' => $role]);
        $response = $this->actingAs($user)->postJson('/api/v1/ephemeral-secrets', [
            'title' => 'Staging FTP',
            'username' => 'deploy',
            'password' => 'p@ss;word',
            'expires_in_minutes' => 60,
        ]);
        $response->assertStatus(201);
        expect($response->json('data.link'))->toContain('/s/');
    }
});

test('the payload is stored encrypted, not in plaintext', function () {
    $user = User::factory()->create(['role' => 'developer']);
    $this->actingAs($user)->postJson('/api/v1/ephemeral-secrets', [
        'password' => 'topsecret123',
        'expires_in_minutes' => 60,
    ])->assertStatus(201);

    $raw = DB::table('ephemeral_secrets')->latest('id')->value('payload');
    expect($raw)->not->toContain('topsecret123');
});

test('a secret with no fields is rejected', function () {
    $user = User::factory()->create(['role' => 'developer']);
    $this->actingAs($user)->postJson('/api/v1/ephemeral-secrets', [
        'title' => 'Empty',
        'expires_in_minutes' => 60,
    ])->assertStatus(422);
});

test('creating requires authentication', function () {
    $this->postJson('/api/v1/ephemeral-secrets', ['password' => 'x', 'expires_in_minutes' => 60])
        ->assertStatus(401);
});
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `./vendor/bin/pest tests/Feature/EphemeralSecretStoreTest.php`
Expected: FAIL — 404 (route not defined).

- [ ] **Step 3: Create the controller with `store`**

```php
<?php
// app/Http/Controllers/Api/V1/EphemeralSecretController.php
namespace App\Http\Controllers\Api\V1;

use App\Models\EphemeralSecret;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EphemeralSecretController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'username' => 'nullable|string|max:1000',
            'password' => 'nullable|string|max:5000',
            'url' => 'nullable|string|max:2000',
            'note' => 'nullable|string|max:10000',
            'expires_in_minutes' => 'required|integer|min:5|max:10080',
            'access_password' => 'nullable|string|min:4',
        ]);

        $fields = array_filter([
            'username' => $validated['username'] ?? null,
            'password' => $validated['password'] ?? null,
            'url' => $validated['url'] ?? null,
            'note' => $validated['note'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if (empty($fields)) {
            return $this->errorResponse('At least one of username, password, url or note is required.', 422);
        }

        $secret = EphemeralSecret::create([
            'token' => Str::random(40),
            'created_by' => auth()->id(),
            'title' => $validated['title'] ?? null,
            'payload' => $fields,
            'access_password' => $validated['access_password'] ?? null,
            'expires_at' => now()->addMinutes($validated['expires_in_minutes']),
        ]);

        activity()->causedBy(auth()->user())->performedOn($secret)->log('created ephemeral secret');

        return $this->createdResponse([
            'link' => rtrim(config('app.frontend_url'), '/') . '/s/' . $secret->token,
            'expires_at' => $secret->expires_at,
        ]);
    }

    protected function unavailableReason(?EphemeralSecret $secret): string
    {
        if (! $secret) {
            return 'not_found';
        }
        if ($secret->isBurned()) {
            return 'viewed';
        }
        if ($secret->isExpired()) {
            return 'expired';
        }
        return 'not_found';
    }
}
```

- [ ] **Step 4: Add the create route**

In `routes/api.php`, inside the `Route::middleware('auth:sanctum')->group(...)` block (e.g. just after the `two-factor` group), add:

```php
        // Ephemeral secret send (any authenticated role)
        Route::post('/ephemeral-secrets', [V1\EphemeralSecretController::class, 'store'])
            ->name('ephemeral-secrets.store');
```

- [ ] **Step 5: Run the tests and confirm they pass**

Run: `./vendor/bin/pest tests/Feature/EphemeralSecretStoreTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/V1/EphemeralSecretController.php routes/api.php \
        tests/Feature/EphemeralSecretStoreTest.php
git commit -m "feat: create ephemeral secret endpoint"
```

---

### Task 3: Metadata endpoint (`show`) — does not burn

**Files:**
- Modify: `app/Http/Controllers/Api/V1/EphemeralSecretController.php` (add `show`)
- Modify: `routes/api.php` (public throttled GET route)
- Test: `tests/Feature/EphemeralSecretShowTest.php`

**Interfaces:**
- Produces: `GET /api/v1/s/{token}` → available: `{ available:true, title, has_password, expires_at }`; unavailable: HTTP 404 `{ available:false, reason }` where reason ∈ `not_found|expired|viewed`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/EphemeralSecretShowTest.php
use App\Models\EphemeralSecret;

test('show returns metadata only, never the secret', function () {
    $secret = EphemeralSecret::factory()->create([
        'title' => 'Staging FTP',
        'payload' => ['password' => 'do-not-leak'],
    ]);

    $response = $this->getJson("/api/v1/s/{$secret->token}");

    $response->assertOk();
    $response->assertJsonPath('available', true);
    $response->assertJsonPath('title', 'Staging FTP');
    expect($response->getContent())->not->toContain('do-not-leak');
    expect($response->json('password'))->toBeNull();
});

test('show reports has_password when a gate is set', function () {
    $secret = EphemeralSecret::factory()->create(['access_password' => 'letmein']);
    $this->getJson("/api/v1/s/{$secret->token}")->assertJsonPath('has_password', true);
});

test('show returns unavailable for expired, burned, and missing secrets', function () {
    $expired = EphemeralSecret::factory()->create(['expires_at' => now()->subMinute()]);
    $this->getJson("/api/v1/s/{$expired->token}")->assertStatus(404)->assertJsonPath('reason', 'expired');

    $burned = EphemeralSecret::factory()->create(['viewed_at' => now()]);
    $this->getJson("/api/v1/s/{$burned->token}")->assertStatus(404)->assertJsonPath('reason', 'viewed');

    $this->getJson('/api/v1/s/nope')->assertStatus(404)->assertJsonPath('reason', 'not_found');
});
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `./vendor/bin/pest tests/Feature/EphemeralSecretShowTest.php`
Expected: FAIL — 404 with no `available` key (route undefined).

- [ ] **Step 3: Add the `show` method to the controller**

```php
    public function show(string $token): JsonResponse
    {
        $secret = EphemeralSecret::where('token', $token)->first();

        if (! $secret || ! $secret->isAvailable()) {
            return response()->json([
                'available' => false,
                'reason' => $this->unavailableReason($secret),
            ], 404);
        }

        return response()->json([
            'available' => true,
            'title' => $secret->title,
            'has_password' => ! empty($secret->access_password),
            'expires_at' => $secret->expires_at,
        ]);
    }
```

- [ ] **Step 4: Add the public route**

In `routes/api.php`, in the public (pre-`auth:sanctum`) section near the other public share routes, add:

```php
    // Ephemeral secret reveal (public, throttled)
    Route::get('/s/{token}', [V1\EphemeralSecretController::class, 'show'])
        ->middleware('throttle:20,1')
        ->name('ephemeral-secrets.show');
```

- [ ] **Step 5: Run the tests and confirm they pass**

Run: `./vendor/bin/pest tests/Feature/EphemeralSecretShowTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/V1/EphemeralSecretController.php routes/api.php \
        tests/Feature/EphemeralSecretShowTest.php
git commit -m "feat: ephemeral secret metadata endpoint"
```

---

### Task 4: Reveal endpoint (`access`) — burn after read

**Files:**
- Modify: `app/Http/Controllers/Api/V1/EphemeralSecretController.php` (add `access`)
- Modify: `routes/api.php` (public throttled POST route)
- Test: `tests/Feature/EphemeralSecretAccessTest.php`

**Interfaces:**
- Produces: `POST /api/v1/s/{token}/access` → `{ data: { title, username?, password?, url?, note? }, revealed_once:true }` on first success; `403 { message }` on wrong password (no burn); `404 { available:false, reason }` when gone.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/EphemeralSecretAccessTest.php
use App\Models\EphemeralSecret;

test('access reveals the payload once, then burns it', function () {
    $secret = EphemeralSecret::factory()->create([
        'title' => 'Staging FTP',
        'payload' => ['username' => 'deploy', 'password' => 'p@ss;word'],
    ]);

    $first = $this->postJson("/api/v1/s/{$secret->token}/access");
    $first->assertOk();
    $first->assertJsonPath('data.username', 'deploy');
    $first->assertJsonPath('data.password', 'p@ss;word');
    $first->assertJsonPath('revealed_once', true);

    // Burned: second attempt is gone, and the payload is wiped at rest.
    $this->postJson("/api/v1/s/{$secret->token}/access")->assertStatus(404)->assertJsonPath('reason', 'viewed');
    expect($secret->fresh()->payload)->toBeNull();
    expect($secret->fresh()->viewed_at)->not->toBeNull();
});

test('a password-protected secret needs the correct password and does not burn on failure', function () {
    $secret = EphemeralSecret::factory()->create([
        'access_password' => 'letmein',
        'payload' => ['password' => 'the-secret'],
    ]);

    $this->postJson("/api/v1/s/{$secret->token}/access", ['password' => 'wrong'])->assertStatus(403);
    expect($secret->fresh()->isAvailable())->toBeTrue(); // not burned

    $ok = $this->postJson("/api/v1/s/{$secret->token}/access", ['password' => 'letmein']);
    $ok->assertOk()->assertJsonPath('data.password', 'the-secret');
});

test('access on an expired secret is unavailable', function () {
    $secret = EphemeralSecret::factory()->create(['expires_at' => now()->subMinute()]);
    $this->postJson("/api/v1/s/{$secret->token}/access")->assertStatus(404)->assertJsonPath('reason', 'expired');
});
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `./vendor/bin/pest tests/Feature/EphemeralSecretAccessTest.php`
Expected: FAIL — 404 without the expected shape (route undefined).

- [ ] **Step 3: Add imports and the `access` method**

At the top of the controller add:

```php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
```

Add the method:

```php
    public function access(Request $request, string $token): JsonResponse
    {
        $data = null;

        $result = DB::transaction(function () use ($request, $token, &$data) {
            $secret = EphemeralSecret::where('token', $token)->lockForUpdate()->first();

            if (! $secret || ! $secret->isAvailable()) {
                return ['reason' => $this->unavailableReason($secret)];
            }

            if (! empty($secret->access_password)
                && ! Hash::check((string) $request->input('password'), $secret->access_password)) {
                return ['password_error' => true];
            }

            $data = $secret->payload;                 // decrypted array
            $secret->payload = null;                  // burn
            $secret->viewed_at = now();
            $secret->last_viewed_ip = $request->ip();
            $secret->save();

            return ['secret' => $secret];
        });

        if (! empty($result['password_error'])) {
            return response()->json(['available' => true, 'message' => 'Incorrect password.'], 403);
        }

        if (empty($result['secret'])) {
            return response()->json(['available' => false, 'reason' => $result['reason']], 404);
        }

        activity()->performedOn($result['secret'])
            ->withProperties(['ip' => $request->ip()])
            ->log('revealed ephemeral secret');

        return response()->json([
            'data' => array_merge(['title' => $result['secret']->title], $data),
            'revealed_once' => true,
        ]);
    }
```

- [ ] **Step 4: Add the public route**

In `routes/api.php`, right after the `/s/{token}` GET route, add:

```php
    Route::post('/s/{token}/access', [V1\EphemeralSecretController::class, 'access'])
        ->middleware('throttle:10,1')
        ->name('ephemeral-secrets.access');
```

- [ ] **Step 5: Run the tests and confirm they pass**

Run: `./vendor/bin/pest tests/Feature/EphemeralSecretAccessTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/V1/EphemeralSecretController.php routes/api.php \
        tests/Feature/EphemeralSecretAccessTest.php
git commit -m "feat: ephemeral secret one-time reveal with burn-after-read"
```

---

### Task 5: Purge command + schedule

**Files:**
- Create: `app/Console/Commands/PurgeEphemeralSecrets.php`
- Modify: `routes/console.php` (schedule daily)
- Test: `tests/Feature/PurgeEphemeralSecretsTest.php`

**Interfaces:**
- Produces: artisan command `ephemeral-secrets:purge` deleting rows whose `expires_at` or `viewed_at` is older than 7 days.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/PurgeEphemeralSecretsTest.php
use App\Models\EphemeralSecret;

test('purge deletes old expired/viewed secrets and keeps live and recent ones', function () {
    $live = EphemeralSecret::factory()->create(['expires_at' => now()->addHour()]);
    $recentlyViewed = EphemeralSecret::factory()->create(['viewed_at' => now()->subDay()]);
    $oldExpired = EphemeralSecret::factory()->create(['expires_at' => now()->subDays(8)]);
    $oldViewed = EphemeralSecret::factory()->create(['viewed_at' => now()->subDays(8)]);

    $this->artisan('ephemeral-secrets:purge')->assertExitCode(0);

    expect(EphemeralSecret::find($live->id))->not->toBeNull();
    expect(EphemeralSecret::find($recentlyViewed->id))->not->toBeNull();
    expect(EphemeralSecret::find($oldExpired->id))->toBeNull();
    expect(EphemeralSecret::find($oldViewed->id))->toBeNull();
});
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `./vendor/bin/pest tests/Feature/PurgeEphemeralSecretsTest.php`
Expected: FAIL — command `ephemeral-secrets:purge` not defined.

- [ ] **Step 3: Create the command**

```php
<?php
// app/Console/Commands/PurgeEphemeralSecrets.php
namespace App\Console\Commands;

use App\Models\EphemeralSecret;
use Illuminate\Console\Command;

class PurgeEphemeralSecrets extends Command
{
    protected $signature = 'ephemeral-secrets:purge';

    protected $description = 'Delete expired or already-viewed ephemeral secrets past the 7-day retention window';

    public function handle(): int
    {
        $cutoff = now()->subDays(7);

        $count = EphemeralSecret::query()
            ->where(fn ($q) => $q->where('expires_at', '<', $cutoff)
                                  ->orWhere('viewed_at', '<', $cutoff))
            ->delete();

        $this->info("Purged {$count} ephemeral secret(s).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Schedule it**

Append to `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('ephemeral-secrets:purge')
    ->daily()
    ->onOneServer()
    ->name('ephemeral-secrets-purge');
```

(If `use Illuminate\Support\Facades\Schedule;` is already imported at the top, don't duplicate it.)

- [ ] **Step 5: Run the tests and confirm they pass**

Run: `./vendor/bin/pest tests/Feature/PurgeEphemeralSecretsTest.php`
Expected: PASS.

- [ ] **Step 6: Run the whole backend suite (no regressions)**

Run: `./vendor/bin/pest`
Expected: all green, including the earlier security/backup/php-error suites.

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/PurgeEphemeralSecrets.php routes/console.php \
        tests/Feature/PurgeEphemeralSecretsTest.php
git commit -m "feat: scheduled purge of expired ephemeral secrets"
```

---

### Task 6: Frontend types + API client

**Files:**
- Modify: `lsm-web/packages/types/src/index.ts` (add types)
- Create: `lsm-web/src/lib/ephemeral-secrets-api.ts`
- Modify: `lsm-web/src/lib/api.ts` (register the module on `api`)

**Interfaces:**
- Produces: `createEphemeralSecretsApi(client)` with `create(payload)`, `show(token)`, `access(token, password?)`; types `EphemeralSecretInput`, `EphemeralSecretMeta`, `EphemeralSecretReveal`.

- [ ] **Step 1: Add types**

Append to `lsm-web/packages/types/src/index.ts`:

```ts
export interface EphemeralSecretInput {
  title?: string;
  username?: string;
  password?: string;
  url?: string;
  note?: string;
  expires_in_minutes: number;
  access_password?: string | null;
}

export interface EphemeralSecretMeta {
  available: boolean;
  reason?: 'not_found' | 'expired' | 'viewed';
  title?: string;
  has_password?: boolean;
  expires_at?: string;
}

export interface EphemeralSecretReveal {
  data: { title?: string; username?: string; password?: string; url?: string; note?: string };
  revealed_once: boolean;
}
```

- [ ] **Step 2: Create the API module**

```ts
// lsm-web/src/lib/ephemeral-secrets-api.ts
import type { EphemeralSecretInput, EphemeralSecretMeta, EphemeralSecretReveal } from '@lsm/types';

export function createEphemeralSecretsApi(client: any) {
  return {
    create: (payload: EphemeralSecretInput) =>
      client.post<{ data: { link: string; expires_at: string } }>('/ephemeral-secrets', payload),
    show: (token: string) =>
      client.get<EphemeralSecretMeta>(`/s/${token}`),
    access: (token: string, password?: string) =>
      client.post<EphemeralSecretReveal>(`/s/${token}/access`, password ? { password } : {}),
  };
}
```

- [ ] **Step 3: Register on the api object**

In `lsm-web/src/lib/api.ts`, add the import near the other `create*Api` imports:

```ts
import { createEphemeralSecretsApi } from './ephemeral-secrets-api';
```

and add to the exported `api` object:

```ts
  ephemeralSecrets: createEphemeralSecretsApi(client),
```

- [ ] **Step 4: Typecheck**

Run: `cd lsm-web && npm run build`
Expected: build succeeds (no TS errors).

- [ ] **Step 5: Commit**

```bash
cd lsm-web
git add packages/types/src/index.ts src/lib/ephemeral-secrets-api.ts src/lib/api.ts
git commit -m "feat: ephemeral secrets types and api client"
```

---

### Task 7: Create modal + top-bar entry

**Files:**
- Create: `lsm-web/src/features/secrets/SendSecretModal.tsx`
- Modify: `lsm-web/src/components/layouts/AuthenticatedLayout.tsx` (add a "Send a secret" button that opens the modal)

**Interfaces:**
- Consumes: `api.ephemeralSecrets.create` (Task 6).
- Produces: `<SendSecretModal open onClose />` component.

- [ ] **Step 1: Create the modal**

```tsx
// lsm-web/src/features/secrets/SendSecretModal.tsx
import { useState } from 'react';
import { Modal, Form, Input, InputNumber, Button, Switch, Typography, App } from 'antd';
import { CopyOutlined, SafetyCertificateOutlined } from '@ant-design/icons';
import { useMutation } from '@tanstack/react-query';
import { api } from '@/lib/api';

const { Text, Paragraph } = Typography;

interface Props {
  open: boolean;
  onClose: () => void;
}

export function SendSecretModal({ open, onClose }: Props) {
  const [form] = Form.useForm();
  const [link, setLink] = useState<string | null>(null);
  const [hasPassword, setHasPassword] = useState(false);
  const { message } = App.useApp();

  const mutation = useMutation({
    mutationFn: (values: any) =>
      api.ephemeralSecrets.create({
        title: values.title || undefined,
        username: values.username || undefined,
        password: values.password || undefined,
        url: values.url || undefined,
        note: values.note || undefined,
        expires_in_minutes: values.expires_in_minutes,
        access_password: values.access_password || null,
      }),
    onSuccess: (res) => {
      setLink(res.data.data.link);
      message.success('One-time link created');
    },
    onError: () => message.error('Could not create the link'),
  });

  const close = () => {
    setLink(null);
    setHasPassword(false);
    form.resetFields();
    onClose();
  };

  const copy = () => {
    if (link) {
      navigator.clipboard.writeText(link);
      message.success('Link copied');
    }
  };

  return (
    <Modal
      open={open}
      onCancel={close}
      title={<><SafetyCertificateOutlined /> Send a secret (one-time)</>}
      footer={null}
      destroyOnClose
    >
      {link ? (
        <div>
          <Paragraph>Share this one-time link. It expires and is deleted after the first view.</Paragraph>
          <Input.Group compact>
            <Input style={{ width: 'calc(100% - 40px)' }} value={link} readOnly />
            <Button icon={<CopyOutlined />} onClick={copy} />
          </Input.Group>
          <Button style={{ marginTop: 16 }} onClick={close} block>Done</Button>
        </div>
      ) : (
        <Form
          form={form}
          layout="vertical"
          initialValues={{ expires_in_minutes: 1440 }}
          onFinish={(v) => mutation.mutate(v)}
        >
          <Form.Item name="title" label="Title"><Input placeholder="e.g. Staging FTP" /></Form.Item>
          <Form.Item name="username" label="Username"><Input autoComplete="off" /></Form.Item>
          <Form.Item name="password" label="Password"><Input.Password autoComplete="new-password" /></Form.Item>
          <Form.Item name="url" label="URL"><Input placeholder="https://..." /></Form.Item>
          <Form.Item name="note" label="Note"><Input.TextArea rows={2} /></Form.Item>
          <Form.Item name="expires_in_minutes" label="Expires in (minutes)">
            <InputNumber min={5} max={10080} style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item label="Require a password to open">
            <Switch checked={hasPassword} onChange={setHasPassword} />
          </Form.Item>
          {hasPassword && (
            <Form.Item name="access_password" label="Access password" rules={[{ min: 4 }]}>
              <Input.Password autoComplete="new-password" />
            </Form.Item>
          )}
          <Text type="secondary">Fill at least one of username / password / URL / note.</Text>
          <Button type="primary" htmlType="submit" block loading={mutation.isPending} style={{ marginTop: 16 }}>
            Create one-time link
          </Button>
        </Form>
      )}
    </Modal>
  );
}
```

- [ ] **Step 2: Add the top-bar button**

In `lsm-web/src/components/layouts/AuthenticatedLayout.tsx`: import the modal and a `useState`, render a button in the header actions area, and mount the modal. Add near the top:

```tsx
import { useState } from 'react';
import { SafetyCertificateOutlined } from '@ant-design/icons';
import { SendSecretModal } from '@/features/secrets/SendSecretModal';
```

Inside the component, add state:

```tsx
  const [secretModalOpen, setSecretModalOpen] = useState(false);
```

In the header's right-hand actions (next to the existing notification/user controls), add a button and mount the modal (place the modal just before the layout's closing tag):

```tsx
        <Button icon={<SafetyCertificateOutlined />} onClick={() => setSecretModalOpen(true)}>
          Send a secret
        </Button>
        <SendSecretModal open={secretModalOpen} onClose={() => setSecretModalOpen(false)} />
```

(If `Button` isn't already imported from `antd` in this file, add it to the existing antd import.)

- [ ] **Step 3: Typecheck + lint**

Run: `cd lsm-web && npm run build && npm run lint`
Expected: both pass.

- [ ] **Step 4: Manual check**

Start the app (`npm run dev`), log in, click **Send a secret**, fill a password, create a link, and confirm the link is shown and copyable. (Full reveal flow is verified in Task 8.)

- [ ] **Step 5: Commit**

```bash
cd lsm-web
git add src/features/secrets/SendSecretModal.tsx src/components/layouts/AuthenticatedLayout.tsx
git commit -m "feat: send-a-secret modal and top-bar entry"
```

---

### Task 8: Public reveal page + route

**Files:**
- Create: `lsm-web/src/features/share/pages/EphemeralSecretRevealPage.tsx`
- Modify: `lsm-web/src/App.tsx` (public route `/s/:token`)

**Interfaces:**
- Consumes: `api.ephemeralSecrets.show`, `api.ephemeralSecrets.access` (Task 6).

- [ ] **Step 1: Create the reveal page**

```tsx
// lsm-web/src/features/share/pages/EphemeralSecretRevealPage.tsx
import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { Card, Input, Button, Typography, Result, Spin, App } from 'antd';
import { CopyOutlined, EyeOutlined } from '@ant-design/icons';
import { api } from '@/lib/api';
import type { EphemeralSecretMeta, EphemeralSecretReveal } from '@lsm/types';

const { Text, Paragraph } = Typography;

export function EphemeralSecretRevealPage() {
  const { token = '' } = useParams();
  const { message } = App.useApp();
  const [meta, setMeta] = useState<EphemeralSecretMeta | null>(null);
  const [loading, setLoading] = useState(true);
  const [password, setPassword] = useState('');
  const [revealing, setRevealing] = useState(false);
  const [secret, setSecret] = useState<EphemeralSecretReveal['data'] | null>(null);
  const [gone, setGone] = useState<string | null>(null);

  useEffect(() => {
    api.ephemeralSecrets.show(token)
      .then((res) => setMeta(res.data))
      .catch((e) => setMeta(e.response?.data ?? { available: false, reason: 'not_found' }))
      .finally(() => setLoading(false));
  }, [token]);

  const reveal = async () => {
    setRevealing(true);
    try {
      const res = await api.ephemeralSecrets.access(token, password || undefined);
      setSecret(res.data.data);
    } catch (e: any) {
      if (e.response?.status === 403) {
        message.error('Incorrect password');
      } else {
        setGone(e.response?.data?.reason ?? 'not_found');
      }
    } finally {
      setRevealing(false);
    }
  };

  const copy = (value?: string) => {
    if (value) {
      navigator.clipboard.writeText(value);
      message.success('Copied');
    }
  };

  const reasonText = (r?: string) =>
    r === 'expired' ? 'This link has expired.'
      : r === 'viewed' ? 'This secret has already been viewed and is no longer available.'
      : 'This link is invalid.';

  if (loading) return <div style={{ display: 'flex', justifyContent: 'center', marginTop: 80 }}><Spin /></div>;

  if (gone || !meta?.available) {
    return <Result status="warning" title="Unavailable" subTitle={reasonText(gone ?? meta?.reason)} />;
  }

  if (secret) {
    return (
      <div style={{ maxWidth: 480, margin: '40px auto' }}>
        <Card title={secret.title || 'Shared secret'}>
          <Paragraph type="danger">This was the only view — the secret has now been deleted.</Paragraph>
          {(['username', 'password', 'url', 'note'] as const).map((k) =>
            secret[k] ? (
              <div key={k} style={{ marginBottom: 12 }}>
                <Text type="secondary" style={{ textTransform: 'capitalize' }}>{k}</Text>
                <Input.Group compact>
                  <Input style={{ width: 'calc(100% - 40px)' }} value={secret[k]} readOnly />
                  <Button icon={<CopyOutlined />} onClick={() => copy(secret[k])} />
                </Input.Group>
              </div>
            ) : null,
          )}
        </Card>
      </div>
    );
  }

  return (
    <div style={{ maxWidth: 480, margin: '40px auto' }}>
      <Card title={meta.title || 'A secret was shared with you'}>
        <Paragraph>This is a one-time secret. Once you reveal it, it cannot be viewed again.</Paragraph>
        {meta.has_password && (
          <Input.Password
            placeholder="Access password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            style={{ marginBottom: 12 }}
          />
        )}
        <Button type="primary" icon={<EyeOutlined />} loading={revealing} onClick={reveal} block>
          Reveal (one-time)
        </Button>
      </Card>
    </div>
  );
}
```

- [ ] **Step 2: Register the public route**

In `lsm-web/src/App.tsx`, import the page and add a public route alongside `/share/:token` (line ~69):

```tsx
import { EphemeralSecretRevealPage } from '@/features/share/pages/EphemeralSecretRevealPage';
```

```tsx
      <Route path="/s/:token" element={<EphemeralSecretRevealPage />} />
```

- [ ] **Step 3: Typecheck + lint**

Run: `cd lsm-web && npm run build && npm run lint`
Expected: both pass.

- [ ] **Step 4: Manual end-to-end check**

With the API running and the app in `npm run dev`: create a secret via the modal (Task 7), open the returned `/s/:token` link in an incognito window, reveal it (enter the password if set), confirm the fields show with copy buttons, then reload the page and confirm it now says "already been viewed."

- [ ] **Step 5: Commit**

```bash
cd lsm-web
git add src/features/share/pages/EphemeralSecretRevealPage.tsx src/App.tsx
git commit -m "feat: public one-time secret reveal page"
```

---

## Notes for the deploy (out of plan scope)

This feature is developed on `feature/ephemeral-secret-send` (backend) and a matching branch in `lsm-web`. It ships together with the pending security work in the same coordinated deploy: backend migrate (`ephemeral_secrets` table) + frontend build upload. The `FRONTEND_URL` env var must be set correctly on the API server for the generated links to point at the SPA.
