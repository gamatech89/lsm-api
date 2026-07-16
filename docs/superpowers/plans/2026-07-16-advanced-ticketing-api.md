# Advanced Ticketing — lsm-api Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add two-way conversation threads, attachments, email notifications, and plugin-facing API endpoints to the support ticket system.

**Architecture:** New `support_ticket_messages` and `support_ticket_attachments` tables hang off the existing `SupportTicket`. A new `PluginTicketController` (authenticated by the site API key in `X-LSM-Key`, resolved via `projects.health_check_secret_hash`) serves the WP plugin. Existing Sanctum staff routes gain a reply endpoint and message data in the show response. Notifications go to project team members (staff) as `database+mail`, and to the ticket's `client_email` as on-demand mail.

**Tech Stack:** Laravel 12, PHP 8.2, Pest tests, SQLite in tests, local disk storage.

**Spec:** `docs/superpowers/specs/2026-07-16-advanced-ticketing-design.md`

## Global Constraints

- Branch: `feature/advanced-ticketing` (already created; spec committed on it).
- Attachments: images (png, jpg, jpeg, webp, gif) + pdf; max **5 MB** each; max **5** per message/ticket; stored on `local` disk under `support-attachments/{ticket_id}/` (never publicly served).
- Plugin routes rate limit: **30/min**; legacy webhook gets the same throttle.
- Client reply reopens `resolved`/`closed` tickets to `in_progress` and clears `read_at`; any client reply clears `read_at`.
- Use string columns (not DB enums) for new tables — SQLite test harness.
- Run tests with: `php artisan test` (Pest). All existing tests must keep passing.
- Commit messages end with `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.

---

### Task 0: Baseline verification (does today's ticketing work?)

**Files:** none (verification only)

- [ ] **Step 1: Run the existing test suite**

Run: `cd /Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api && php artisan test`
Expected: PASS (note the count). If failures exist, STOP and report — do not build on a broken baseline.

- [ ] **Step 2: Simulate the current plugin webhook locally**

```bash
php artisan tinker --execute="
\$p = App\Models\Project::factory()->create(['url' => 'https://baseline-check.example.com', 'health_check_secret' => 'BASELINE_KEY_123']);
echo \$p->id;
"
```

Then (with `php artisan serve` running in background on :8000):

```bash
curl -s -X POST http://localhost:8000/api/v1/webhooks/support-ticket \
  -H 'Content-Type: application/json' \
  -d '{"api_key":"BASELINE_KEY_123","site_url":"https://baseline-check.example.com","type":"bug","subject":"Baseline test","message":"Does the webhook work?","client_email":"client@example.com","client_name":"Baseline"}'
```

Expected: `201` with `data.ticket_number` like `ST-000NN`.

⚠️ Only do this against a scratch/dev database (`.env` SQLite dev DB), never production. Delete the scratch project/ticket afterwards or use a throwaway DB copy.

- [ ] **Step 3: Report baseline status** — note results in the task summary (this answers the user's "does ticketing work" question for the API side).

---

### Task 1: Messages & attachments — migrations + models

**Files:**
- Create: `database/migrations/2026_07_16_000001_create_support_ticket_messages_table.php`
- Create: `database/migrations/2026_07_16_000002_create_support_ticket_attachments_table.php`
- Create: `app/Models/SupportTicketMessage.php`
- Create: `app/Models/SupportTicketAttachment.php`
- Modify: `app/Models/SupportTicket.php` (add relations + `addClientMessage`/`addStaffMessage`)
- Test: `tests/Feature/SupportTicketThreadTest.php`

**Interfaces:**
- Produces: `SupportTicket::messages(): HasMany` (ordered by created_at), `SupportTicket::staffMessages(): HasMany`, `SupportTicket::attachments(): HasMany`, `SupportTicket::addClientMessage(string $message, ?string $authorName = null): SupportTicketMessage`, `SupportTicket::addStaffMessage(\App\Models\User $user, string $message): SupportTicketMessage`. `SupportTicketMessage` fields: `support_ticket_id, author_type ('client'|'staff'), user_id, author_name, message`. `SupportTicketAttachment` fields: `support_ticket_id, support_ticket_message_id, filename, path, mime, size`, relation `message()`.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Feature/SupportTicketThreadTest.php

use App\Models\Project;
use App\Models\SupportTicket;
use App\Models\User;

function threadTicket(array $overrides = []): SupportTicket
{
    $project = Project::factory()->create();

    return SupportTicket::create(array_merge([
        'project_id' => $project->id,
        'type' => 'bug',
        'subject' => 'Thread test',
        'message' => 'Original description',
        'client_email' => 'client@example.com',
        'client_name' => 'Client Name',
        'status' => 'open',
        'priority' => 'high',
    ], $overrides));
}

test('a client message is appended to the thread with author info', function () {
    $ticket = threadTicket();

    $msg = $ticket->addClientMessage('More details here');

    expect($msg->author_type)->toBe('client');
    expect($msg->author_name)->toBe('Client Name');
    expect($msg->user_id)->toBeNull();
    expect($ticket->messages()->count())->toBe(1);
});

test('a staff message records the authoring user', function () {
    $ticket = threadTicket();
    $staff = User::factory()->create(['role' => 'admin', 'name' => 'Agent Smith']);

    $msg = $ticket->addStaffMessage($staff, 'We are on it');

    expect($msg->author_type)->toBe('staff');
    expect($msg->user_id)->toBe($staff->id);
    expect($msg->author_name)->toBe('Agent Smith');
});

test('a client reply reopens a resolved ticket and marks it unread', function () {
    $ticket = threadTicket(['status' => 'resolved', 'read_at' => now(), 'resolved_at' => now()]);

    $ticket->addClientMessage('It is still broken');

    $ticket->refresh();
    expect($ticket->status)->toBe('in_progress');
    expect($ticket->read_at)->toBeNull();
    expect($ticket->resolved_at)->toBeNull();
});

test('a client reply on an open ticket clears read_at but keeps status', function () {
    $ticket = threadTicket(['status' => 'in_progress', 'read_at' => now()]);

    $ticket->addClientMessage('Bump');

    $ticket->refresh();
    expect($ticket->status)->toBe('in_progress');
    expect($ticket->read_at)->toBeNull();
});

test('a staff reply does not reopen or unread the ticket', function () {
    $ticket = threadTicket(['status' => 'resolved', 'read_at' => now()]);
    $staff = User::factory()->create(['role' => 'admin']);

    $ticket->addStaffMessage($staff, 'Fixed for real');

    $ticket->refresh();
    expect($ticket->status)->toBe('resolved');
    expect($ticket->read_at)->not->toBeNull();
});

test('deleting a ticket cascades to messages and attachments', function () {
    $ticket = threadTicket();
    $msg = $ticket->addClientMessage('With attachment');
    $ticket->attachments()->create([
        'support_ticket_message_id' => $msg->id,
        'filename' => 'shot.png',
        'path' => 'support-attachments/1/shot.png',
        'mime' => 'image/png',
        'size' => 1234,
    ]);

    $ticket->forceDelete();

    expect(\App\Models\SupportTicketMessage::count())->toBe(0);
    expect(\App\Models\SupportTicketAttachment::count())->toBe(0);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=SupportTicketThreadTest`
Expected: FAIL (missing tables/classes/methods).

- [ ] **Step 3: Create the migrations**

```php
<?php
// database/migrations/2026_07_16_000001_create_support_ticket_messages_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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

```php
<?php
// database/migrations/2026_07_16_000002_create_support_ticket_attachments_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('support_ticket_message_id')->nullable()
                ->constrained('support_ticket_messages')->cascadeOnDelete();
            $table->string('filename');
            $table->string('path', 500);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_attachments');
    }
};
```

- [ ] **Step 4: Create the models**

```php
<?php
// app/Models/SupportTicketMessage.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One message in a support ticket conversation thread.
 */
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

    public function attachments(): HasMany
    {
        return $this->hasMany(SupportTicketAttachment::class);
    }
}
```

```php
<?php
// app/Models/SupportTicketAttachment.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * File attached to a support ticket or one of its messages.
 * Files live on the local disk under support-attachments/{ticket_id}/.
 */
class SupportTicketAttachment extends Model
{
    protected $fillable = [
        'support_ticket_id',
        'support_ticket_message_id',
        'filename',
        'path',
        'mime',
        'size',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(SupportTicketMessage::class, 'support_ticket_message_id');
    }
}
```

- [ ] **Step 5: Add relations + thread methods to `SupportTicket`**

In `app/Models/SupportTicket.php`, add `HasMany` to the imports:

```php
use Illuminate\Database\Eloquent\Relations\HasMany;
```

Add below the existing `todo()` relationship:

```php
    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)->orderBy('created_at');
    }

    public function staffMessages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)->where('author_type', 'staff');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SupportTicketAttachment::class);
    }
```

Add below the existing `createTodo()` method:

```php
    /**
     * Append a client reply. Reopens resolved/closed tickets and flags unread for staff.
     */
    public function addClientMessage(string $message, ?string $authorName = null): SupportTicketMessage
    {
        $msg = $this->messages()->create([
            'author_type' => 'client',
            'author_name' => $authorName ?: ($this->client_name ?: $this->client_email),
            'message' => $message,
        ]);

        $updates = ['read_at' => null];
        if (in_array($this->status, ['resolved', 'closed'])) {
            $updates['status'] = 'in_progress';
            $updates['resolved_at'] = null;
        }
        $this->update($updates);

        return $msg;
    }

    /**
     * Append a staff reply.
     */
    public function addStaffMessage(User $user, string $message): SupportTicketMessage
    {
        return $this->messages()->create([
            'author_type' => 'staff',
            'user_id' => $user->id,
            'author_name' => $user->name,
            'message' => $message,
        ]);
    }
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=SupportTicketThreadTest`
Expected: PASS (6 tests).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_16_000001_create_support_ticket_messages_table.php \
        database/migrations/2026_07_16_000002_create_support_ticket_attachments_table.php \
        app/Models/SupportTicketMessage.php app/Models/SupportTicketAttachment.php \
        app/Models/SupportTicket.php tests/Feature/SupportTicketThreadTest.php
git commit -m "feat: support ticket message thread and attachment models

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: Attachment storage service

**Files:**
- Create: `app/Services/SupportTicketAttachmentService.php`
- Test: `tests/Feature/SupportTicketAttachmentServiceTest.php`

**Interfaces:**
- Consumes: models from Task 1.
- Produces: `SupportTicketAttachmentService::rules(): array` (validation rules for an `attachments` array field), `->store(SupportTicket $ticket, ?SupportTicketMessage $message, array $files): array` (stores UploadedFiles, returns created `SupportTicketAttachment[]`), `->download(SupportTicketAttachment $attachment): StreamedResponse`.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Feature/SupportTicketAttachmentServiceTest.php

use App\Models\Project;
use App\Models\SupportTicket;
use App\Services\SupportTicketAttachmentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function attachmentTicket(): SupportTicket
{
    $project = Project::factory()->create();

    return SupportTicket::create([
        'project_id' => $project->id,
        'type' => 'bug',
        'subject' => 'Attachment test',
        'message' => 'desc',
        'client_email' => 'client@example.com',
        'status' => 'open',
        'priority' => 'high',
    ]);
}

test('stores uploaded files under the ticket directory and records metadata', function () {
    Storage::fake('local');
    $ticket = attachmentTicket();
    $service = new SupportTicketAttachmentService();

    $file = UploadedFile::fake()->image('screenshot.png', 800, 600);
    [$attachment] = $service->store($ticket, null, [$file]);

    expect($attachment->filename)->toBe('screenshot.png');
    expect($attachment->support_ticket_id)->toBe($ticket->id);
    expect($attachment->support_ticket_message_id)->toBeNull();
    expect($attachment->path)->toStartWith("support-attachments/{$ticket->id}/");
    Storage::disk('local')->assertExists($attachment->path);
});

test('links attachments to a message when one is given', function () {
    Storage::fake('local');
    $ticket = attachmentTicket();
    $msg = $ticket->addClientMessage('with file');
    $service = new SupportTicketAttachmentService();

    [$attachment] = $service->store($ticket, $msg, [UploadedFile::fake()->image('a.png')]);

    expect($attachment->support_ticket_message_id)->toBe($msg->id);
});

test('validation rules reject more than 5 files, oversized files, and wrong types', function () {
    $rules = SupportTicketAttachmentService::rules();

    $tooMany = validator(
        ['attachments' => array_fill(0, 6, UploadedFile::fake()->image('x.png'))],
        $rules
    );
    expect($tooMany->fails())->toBeTrue();

    $tooBig = validator(
        ['attachments' => [UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')]],
        $rules
    );
    expect($tooBig->fails())->toBeTrue();

    $wrongType = validator(
        ['attachments' => [UploadedFile::fake()->create('evil.php', 10, 'text/x-php')]],
        $rules
    );
    expect($wrongType->fails())->toBeTrue();

    $ok = validator(
        ['attachments' => [UploadedFile::fake()->image('ok.jpg'), UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')]],
        $rules
    );
    expect($ok->fails())->toBeFalse();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=SupportTicketAttachmentServiceTest`
Expected: FAIL with "Class App\Services\SupportTicketAttachmentService not found".

- [ ] **Step 3: Implement the service**

```php
<?php
// app/Services/SupportTicketAttachmentService.php

namespace App\Services;

use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stores and serves support ticket attachments.
 * Files live on the local disk (outside the public root) under support-attachments/{ticket_id}/.
 */
class SupportTicketAttachmentService
{
    public const MAX_FILES = 5;
    public const MAX_KB = 5120; // 5 MB
    public const ALLOWED_EXTENSIONS = 'png,jpg,jpeg,webp,gif,pdf';

    /**
     * Validation rules for an `attachments` request array.
     */
    public static function rules(): array
    {
        return [
            'attachments' => 'sometimes|array|max:' . self::MAX_FILES,
            'attachments.*' => 'file|max:' . self::MAX_KB . '|mimes:' . self::ALLOWED_EXTENSIONS,
        ];
    }

    /**
     * Store uploaded files against a ticket (and optionally one of its messages).
     *
     * @param UploadedFile[] $files
     * @return SupportTicketAttachment[]
     */
    public function store(SupportTicket $ticket, ?SupportTicketMessage $message, array $files): array
    {
        return collect($files)
            ->filter(fn ($file) => $file instanceof UploadedFile)
            ->map(function (UploadedFile $file) use ($ticket, $message) {
                $path = $file->store("support-attachments/{$ticket->id}", 'local');

                return $ticket->attachments()->create([
                    'support_ticket_message_id' => $message?->id,
                    'filename' => $file->getClientOriginalName(),
                    'path' => $path,
                    'mime' => $file->getMimeType() ?: 'application/octet-stream',
                    'size' => $file->getSize() ?: 0,
                ]);
            })
            ->values()
            ->all();
    }

    /**
     * Stream an attachment as a download with its original name.
     */
    public function download(SupportTicketAttachment $attachment): StreamedResponse
    {
        return Storage::disk('local')->download(
            $attachment->path,
            $attachment->filename,
            ['Content-Type' => $attachment->mime]
        );
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=SupportTicketAttachmentServiceTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/SupportTicketAttachmentService.php tests/Feature/SupportTicketAttachmentServiceTest.php
git commit -m "feat: attachment storage service for support tickets

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: Email notifications + Project team helper

**Files:**
- Modify: `app/Models/Project.php` (add `notifiableTeamMembers()`)
- Create: `app/Notifications/SupportTicketReceivedNotification.php`
- Create: `app/Notifications/SupportTicketClientReplyNotification.php`
- Create: `app/Notifications/SupportTicketStaffReplyNotification.php`
- Create: `app/Notifications/SupportTicketResolvedNotification.php`
- Test: `tests/Feature/SupportTicketNotificationTest.php`

**Interfaces:**
- Consumes: Task 1 models.
- Produces: `Project::notifiableTeamMembers(): \Illuminate\Support\Collection` (unique Users: all admins + assigned/legacy managers and developers). Notification constructors: `SupportTicketReceivedNotification(SupportTicket $ticket)`, `SupportTicketClientReplyNotification(SupportTicket $ticket, SupportTicketMessage $message)`, `SupportTicketStaffReplyNotification(SupportTicket $ticket, SupportTicketMessage $message)`, `SupportTicketResolvedNotification(SupportTicket $ticket)`. Staff notifications: `['database','mail']`; client notifications: `['mail']` (sent on-demand via `Notification::route('mail', $ticket->client_email)`).

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Feature/SupportTicketNotificationTest.php

use App\Models\Project;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportTicketReceivedNotification;
use App\Notifications\SupportTicketStaffReplyNotification;
use Illuminate\Support\Facades\Notification;

test('notifiableTeamMembers includes admins and assigned team, deduplicated', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $manager = User::factory()->create(['role' => 'manager']);
    $developer = User::factory()->create(['role' => 'developer']);
    $outsider = User::factory()->create(['role' => 'developer']);

    $project = Project::factory()->create([
        'manager_id' => $manager->id,
        'developer_id' => $developer->id,
    ]);
    // also attach via many-to-many to prove dedup
    $project->developers()->attach($developer->id);

    $members = $project->notifiableTeamMembers();
    $ids = $members->pluck('id');

    expect($ids)->toContain($admin->id);
    expect($ids)->toContain($manager->id);
    expect($ids)->toContain($developer->id);
    expect($ids)->not->toContain($outsider->id);
    expect($ids->count())->toBe($ids->unique()->count());
});

test('staff notification renders mail and database payload', function () {
    $project = Project::factory()->create();
    $ticket = SupportTicket::create([
        'project_id' => $project->id,
        'type' => 'urgent',
        'subject' => 'Site broken',
        'message' => 'Help!',
        'client_email' => 'client@example.com',
        'client_name' => 'Cl',
        'status' => 'open',
        'priority' => 'critical',
    ]);
    $admin = User::factory()->create(['role' => 'admin']);

    $notification = new SupportTicketReceivedNotification($ticket);

    expect($notification->via($admin))->toBe(['database', 'mail']);
    $mail = $notification->toMail($admin);
    expect($mail->subject)->toContain($ticket->ticket_number);
    $array = $notification->toArray($admin);
    expect($array['type'])->toBe('support_ticket_received');
    expect($array['ticket_id'])->toBe($ticket->id);
});

test('staff reply notification goes to the client email on demand', function () {
    Notification::fake();

    $project = Project::factory()->create();
    $ticket = SupportTicket::create([
        'project_id' => $project->id,
        'type' => 'bug',
        'subject' => 'Broken',
        'message' => 'desc',
        'client_email' => 'client@example.com',
        'status' => 'open',
        'priority' => 'high',
    ]);
    $staff = User::factory()->create(['role' => 'admin']);
    $message = $ticket->addStaffMessage($staff, 'We fixed it');

    Notification::route('mail', $ticket->client_email)
        ->notify(new SupportTicketStaffReplyNotification($ticket, $message));

    Notification::assertSentOnDemand(
        SupportTicketStaffReplyNotification::class,
        fn ($notification, $channels, $notifiable) =>
            $notifiable->routes['mail'] === 'client@example.com' && $channels === ['mail']
    );
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=SupportTicketNotificationTest`
Expected: FAIL (missing method/classes).

- [ ] **Step 3: Add `notifiableTeamMembers()` to `app/Models/Project.php`**

Add near the other team-related methods (after the `developers()` relation):

```php
    /**
     * All users who should be notified about this project's support tickets:
     * every admin plus the assigned managers and developers (legacy single
     * columns and many-to-many pivots), deduplicated.
     */
    public function notifiableTeamMembers(): \Illuminate\Support\Collection
    {
        $members = User::where('role', 'admin')->get()
            ->merge($this->managers()->get())
            ->merge($this->developers()->get());

        if ($this->manager) {
            $members->push($this->manager);
        }
        if ($this->developer) {
            $members->push($this->developer);
        }

        return $members->unique('id')->values();
    }
```

(`manager`/`developer` belongsTo relations already exist at `app/Models/Project.php:172` and `:189`.)

- [ ] **Step 4: Create the four notifications**

```php
<?php
// app/Notifications/SupportTicketReceivedNotification.php

namespace App\Notifications;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SupportTicketReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected SupportTicket $ticket) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $project = $this->ticket->project;

        return (new MailMessage)
            ->subject("🎫 New Ticket {$this->ticket->ticket_number}: {$this->ticket->subject}")
            ->greeting("New support ticket for {$project->name}")
            ->line("**Type:** {$this->ticket->type_label}")
            ->line('**Priority:** ' . ucfirst($this->ticket->priority))
            ->line("**From:** {$this->ticket->client_name} <{$this->ticket->client_email}>")
            ->line("**Subject:** {$this->ticket->subject}")
            ->line($this->ticket->message)
            ->action('View Ticket', config('app.frontend_url') . "/projects/{$this->ticket->project_id}")
            ->salutation('— LSM Platform');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'support_ticket_received',
            'ticket_id' => $this->ticket->id,
            'ticket_number' => $this->ticket->ticket_number,
            'project_id' => $this->ticket->project_id,
            'project_name' => $this->ticket->project->name,
            'subject' => $this->ticket->subject,
            'priority' => $this->ticket->priority,
        ];
    }
}
```

```php
<?php
// app/Notifications/SupportTicketClientReplyNotification.php

namespace App\Notifications;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SupportTicketClientReplyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected SupportTicket $ticket,
        protected SupportTicketMessage $message,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("💬 Client replied on {$this->ticket->ticket_number}: {$this->ticket->subject}")
            ->greeting("New client reply on {$this->ticket->project->name}")
            ->line("**From:** {$this->message->author_name}")
            ->line($this->message->message)
            ->action('View Ticket', config('app.frontend_url') . "/projects/{$this->ticket->project_id}")
            ->salutation('— LSM Platform');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'support_ticket_client_reply',
            'ticket_id' => $this->ticket->id,
            'ticket_number' => $this->ticket->ticket_number,
            'project_id' => $this->ticket->project_id,
            'project_name' => $this->ticket->project->name,
            'subject' => $this->ticket->subject,
            'message_id' => $this->message->id,
        ];
    }
}
```

```php
<?php
// app/Notifications/SupportTicketStaffReplyNotification.php

namespace App\Notifications;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mail-only notification sent on-demand to the ticket's client_email.
 */
class SupportTicketStaffReplyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected SupportTicket $ticket,
        protected SupportTicketMessage $message,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $greetingName = $this->ticket->client_name ?: 'there';

        return (new MailMessage)
            ->subject("Re: [{$this->ticket->ticket_number}] {$this->ticket->subject}")
            ->greeting("Hello {$greetingName},")
            ->line('Our team replied to your support ticket:')
            ->line($this->message->message)
            ->line('To view the conversation or reply, open the Support panel on your website (support button on your site, or the Landeseiten Maintenance page in your WordPress admin).')
            ->salutation('— Landeseiten Support');
    }
}
```

```php
<?php
// app/Notifications/SupportTicketResolvedNotification.php

namespace App\Notifications;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mail-only notification sent on-demand to the ticket's client_email.
 */
class SupportTicketResolvedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected SupportTicket $ticket) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $greetingName = $this->ticket->client_name ?: 'there';

        $mail = (new MailMessage)
            ->subject("✅ Resolved: [{$this->ticket->ticket_number}] {$this->ticket->subject}")
            ->greeting("Hello {$greetingName},")
            ->line("Your support ticket **{$this->ticket->ticket_number}** has been resolved.");

        if ($this->ticket->resolution_notes) {
            $mail->line($this->ticket->resolution_notes);
        }

        return $mail
            ->line('If the problem persists, just reply from the Support panel on your website and the ticket will be reopened.')
            ->salutation('— Landeseiten Support');
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=SupportTicketNotificationTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Models/Project.php app/Notifications/SupportTicketReceivedNotification.php \
        app/Notifications/SupportTicketClientReplyNotification.php \
        app/Notifications/SupportTicketStaffReplyNotification.php \
        app/Notifications/SupportTicketResolvedNotification.php \
        tests/Feature/SupportTicketNotificationTest.php
git commit -m "feat: support ticket email notifications for staff and clients

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: Plugin authentication middleware

**Files:**
- Create: `app/Http/Middleware/AuthenticateLsmPlugin.php`
- Test: `tests/Feature/PluginTicketAuthTest.php` (auth cases only; endpoint behavior tested in Task 5)

**Interfaces:**
- Produces: middleware class `\App\Http\Middleware\AuthenticateLsmPlugin` that reads `X-LSM-Key`, resolves the `Project` by `health_check_secret_hash = sha256(key)`, returns 401 JSON on failure, and exposes the project via `$request->attributes->get('lsm_project')`.

- [ ] **Step 1: Write the failing tests**

Note: routes don't exist yet — register a throwaway test route inside the test so the middleware is exercised in isolation.

```php
<?php
// tests/Feature/PluginTicketAuthTest.php

use App\Http\Middleware\AuthenticateLsmPlugin;
use App\Models\Project;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(AuthenticateLsmPlugin::class)->get('/_test/plugin-auth', function (\Illuminate\Http\Request $request) {
        return response()->json(['project_id' => $request->attributes->get('lsm_project')->id]);
    });
});

test('request without an API key is rejected with 401', function () {
    $this->getJson('/_test/plugin-auth')->assertStatus(401);
});

test('request with an unknown API key is rejected with 401', function () {
    Project::factory()->create(['health_check_secret' => 'REAL_KEY']);

    $this->getJson('/_test/plugin-auth', ['X-LSM-Key' => 'WRONG_KEY'])->assertStatus(401);
});

test('request with a valid API key resolves the owning project', function () {
    $project = Project::factory()->create(['health_check_secret' => 'REAL_KEY_42']);

    $this->getJson('/_test/plugin-auth', ['X-LSM-Key' => 'REAL_KEY_42'])
        ->assertOk()
        ->assertJson(['project_id' => $project->id]);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=PluginTicketAuthTest`
Expected: FAIL with "Class ... AuthenticateLsmPlugin not found".

- [ ] **Step 3: Implement the middleware**

```php
<?php
// app/Http/Middleware/AuthenticateLsmPlugin.php

namespace App\Http\Middleware;

use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates requests from the Landeseiten Maintenance WP plugin.
 *
 * The plugin sends the per-site API key in the X-LSM-Key header. The key is
 * matched against projects.health_check_secret_hash (SHA-256), the same
 * mechanism the legacy support-ticket webhook uses. The resolved project is
 * exposed as the 'lsm_project' request attribute.
 */
class AuthenticateLsmPlugin
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header('X-LSM-Key', '');

        if ($key === '') {
            return response()->json(['success' => false, 'message' => 'Missing API key'], 401);
        }

        $project = Project::where('health_check_secret_hash', hash('sha256', $key))->first();

        if (!$project) {
            return response()->json(['success' => false, 'message' => 'Invalid API key'], 401);
        }

        $request->attributes->set('lsm_project', $project);

        return $next($request);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=PluginTicketAuthTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Middleware/AuthenticateLsmPlugin.php tests/Feature/PluginTicketAuthTest.php
git commit -m "feat: X-LSM-Key middleware for plugin-facing ticket routes

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: Plugin-facing ticket endpoints

**Files:**
- Create: `app/Http/Controllers/Api/V1/PluginTicketController.php`
- Modify: `routes/api.php` (new plugin route group after the webhook at line ~74; add throttle to the legacy webhook)
- Modify: `app/Http/Controllers/Api/V1/SupportTicketController.php` (`receiveFromPlugin` sends staff notification)
- Test: `tests/Feature/PluginTicketEndpointsTest.php`

**Interfaces:**
- Consumes: Tasks 1–4 (models, `SupportTicketAttachmentService`, notifications, middleware).
- Produces routes (all behind `throttle:30,1` + `AuthenticateLsmPlugin`):
  - `GET  /api/v1/plugin/support-tickets` → `{success, data: [{id, ticket_number, type, type_label, subject, status, priority, message_count, last_message_at, last_staff_reply_at, created_at}]}`
  - `GET  /api/v1/plugin/support-tickets/{id}` → detail incl. `messages: [{id, author_type, author_name, message, created_at, attachments: [{id, filename, mime, size}]}]` and ticket-level `attachments`
  - `POST /api/v1/plugin/support-tickets` (multipart) → `201 {success, data: {id, ticket_number}}`
  - `POST /api/v1/plugin/support-tickets/{id}/messages` (multipart) → `201` with the created message
  - `GET  /api/v1/plugin/support-tickets/attachments/{attachment}` → file download

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Feature/PluginTicketEndpointsTest.php

use App\Models\Project;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportTicketClientReplyNotification;
use App\Notifications\SupportTicketReceivedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

function pluginProject(string $key = 'PLUGIN_KEY_1'): Project
{
    return Project::factory()->create(['health_check_secret' => $key]);
}

function pluginTicket(Project $project, array $overrides = []): SupportTicket
{
    return SupportTicket::create(array_merge([
        'project_id' => $project->id,
        'type' => 'bug',
        'subject' => 'Plugin ticket',
        'message' => 'desc',
        'client_email' => 'client@example.com',
        'client_name' => 'Client',
        'status' => 'open',
        'priority' => 'high',
    ], $overrides));
}

test('lists only the authenticated project tickets', function () {
    $mine = pluginProject('KEY_MINE');
    $other = pluginProject('KEY_OTHER');
    pluginTicket($mine, ['subject' => 'Mine']);
    pluginTicket($other, ['subject' => 'NotMine']);

    $response = $this->getJson('/api/v1/plugin/support-tickets', ['X-LSM-Key' => 'KEY_MINE']);

    $response->assertOk();
    $subjects = collect($response->json('data'))->pluck('subject');
    expect($subjects)->toContain('Mine');
    expect($subjects)->not->toContain('NotMine');
});

test('creates a ticket with attachments and notifies staff', function () {
    Storage::fake('local');
    Notification::fake();
    $admin = User::factory()->create(['role' => 'admin']);
    $project = pluginProject('KEY_CREATE');

    $response = $this->post('/api/v1/plugin/support-tickets', [
        'type' => 'urgent',
        'subject' => 'Homepage down',
        'message' => 'The homepage shows an error',
        'client_email' => 'owner@site.com',
        'client_name' => 'Owner',
        'problem_page' => 'https://site.com/',
        'attachments' => [UploadedFile::fake()->image('screenshot.png')],
    ], ['X-LSM-Key' => 'KEY_CREATE', 'Accept' => 'application/json']);

    $response->assertCreated();
    $ticket = SupportTicket::where('subject', 'Homepage down')->firstOrFail();
    expect($ticket->priority)->toBe('critical'); // urgent → critical
    expect($ticket->attachments()->count())->toBe(1);
    Notification::assertSentTo($admin, SupportTicketReceivedNotification::class);
});

test('shows a ticket with its full thread, 404s for foreign tickets', function () {
    $project = pluginProject('KEY_SHOW');
    $ticket = pluginTicket($project);
    $ticket->addClientMessage('First reply');
    $staff = User::factory()->create(['role' => 'admin']);
    $ticket->addStaffMessage($staff, 'Staff answer');

    $response = $this->getJson("/api/v1/plugin/support-tickets/{$ticket->id}", ['X-LSM-Key' => 'KEY_SHOW']);
    $response->assertOk();
    expect($response->json('data.messages'))->toHaveCount(2);
    expect($response->json('data.messages.1.author_type'))->toBe('staff');

    $foreign = pluginProject('KEY_FOREIGN');
    $foreignTicket = pluginTicket($foreign);
    $this->getJson("/api/v1/plugin/support-tickets/{$foreignTicket->id}", ['X-LSM-Key' => 'KEY_SHOW'])
        ->assertNotFound();
});

test('client reply reopens the ticket and notifies staff', function () {
    Notification::fake();
    $admin = User::factory()->create(['role' => 'admin']);
    $project = pluginProject('KEY_REPLY');
    $ticket = pluginTicket($project, ['status' => 'resolved', 'read_at' => now(), 'resolved_at' => now()]);

    $response = $this->post("/api/v1/plugin/support-tickets/{$ticket->id}/messages", [
        'message' => 'Still broken!',
    ], ['X-LSM-Key' => 'KEY_REPLY', 'Accept' => 'application/json']);

    $response->assertCreated();
    $ticket->refresh();
    expect($ticket->status)->toBe('in_progress');
    expect($ticket->read_at)->toBeNull();
    Notification::assertSentTo($admin, SupportTicketClientReplyNotification::class);
});

test('downloads an attachment belonging to the project, 404s otherwise', function () {
    Storage::fake('local');
    $project = pluginProject('KEY_DL');
    $ticket = pluginTicket($project);
    $path = UploadedFile::fake()->image('mine.png')->store("support-attachments/{$ticket->id}", 'local');
    $attachment = $ticket->attachments()->create([
        'filename' => 'mine.png', 'path' => $path, 'mime' => 'image/png', 'size' => 100,
    ]);

    $this->get("/api/v1/plugin/support-tickets/attachments/{$attachment->id}", ['X-LSM-Key' => 'KEY_DL'])
        ->assertOk();

    $this->get("/api/v1/plugin/support-tickets/attachments/{$attachment->id}", ['X-LSM-Key' => 'KEY_DL_NOPE'])
        ->assertStatus(401);

    pluginProject('KEY_DL2');
    $this->get("/api/v1/plugin/support-tickets/attachments/{$attachment->id}", ['X-LSM-Key' => 'KEY_DL2'])
        ->assertNotFound();
});

test('legacy webhook still creates tickets and now notifies staff', function () {
    Notification::fake();
    $admin = User::factory()->create(['role' => 'admin']);
    $project = Project::factory()->create([
        'url' => 'https://legacyhook.example.com',
        'health_check_secret' => 'LEGACY_KEY',
    ]);

    $response = $this->postJson('/api/v1/webhooks/support-ticket', [
        'api_key' => 'LEGACY_KEY',
        'site_url' => 'https://legacyhook.example.com',
        'type' => 'question',
        'subject' => 'Legacy path',
        'message' => 'Old plugin version',
        'client_email' => 'old@client.com',
    ]);

    $response->assertCreated();
    Notification::assertSentTo($admin, SupportTicketReceivedNotification::class);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=PluginTicketEndpointsTest`
Expected: FAIL (404s — routes/controller missing).

- [ ] **Step 3: Implement the controller**

```php
<?php
// app/Http/Controllers/Api/V1/PluginTicketController.php

namespace App\Http\Controllers\Api\V1;

use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Notifications\SupportTicketClientReplyNotification;
use App\Notifications\SupportTicketReceivedNotification;
use App\Services\SupportTicketAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ticket endpoints consumed by the Landeseiten Maintenance WP plugin.
 *
 * All routes sit behind the AuthenticateLsmPlugin middleware, which resolves
 * the calling site's Project into the 'lsm_project' request attribute. Every
 * ticket/attachment id is verified to belong to that project (404 otherwise).
 */
class PluginTicketController extends Controller
{
    public function __construct(protected SupportTicketAttachmentService $attachments) {}

    public function index(Request $request): JsonResponse
    {
        $project = $request->attributes->get('lsm_project');

        $tickets = $project->supportTickets()
            ->withCount('messages')
            ->withMax('messages as last_message_at', 'created_at')
            ->withMax('staffMessages as last_staff_reply_at', 'created_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (SupportTicket $ticket) => [
                'id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'type' => $ticket->type,
                'type_label' => $ticket->type_label,
                'subject' => $ticket->subject,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'message_count' => $ticket->messages_count,
                'last_message_at' => $ticket->last_message_at,
                'last_staff_reply_at' => $ticket->last_staff_reply_at,
                'created_at' => $ticket->created_at?->toISOString(),
            ]);

        return $this->successResponse($tickets);
    }

    public function show(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        $project = $request->attributes->get('lsm_project');
        abort_unless($supportTicket->project_id === $project->id, 404);

        $supportTicket->load([
            'messages.attachments',
            'attachments' => fn ($q) => $q->whereNull('support_ticket_message_id'),
        ]);

        return $this->successResponse([
            'id' => $supportTicket->id,
            'ticket_number' => $supportTicket->ticket_number,
            'type' => $supportTicket->type,
            'type_label' => $supportTicket->type_label,
            'subject' => $supportTicket->subject,
            'message' => $supportTicket->message,
            'status' => $supportTicket->status,
            'priority' => $supportTicket->priority,
            'problem_page' => $supportTicket->problem_page,
            'resolution_notes' => $supportTicket->resolution_notes,
            'created_at' => $supportTicket->created_at?->toISOString(),
            'attachments' => $supportTicket->attachments->map(fn ($a) => $this->attachmentSummary($a)),
            'messages' => $supportTicket->messages->map(fn ($m) => [
                'id' => $m->id,
                'author_type' => $m->author_type,
                'author_name' => $m->author_name,
                'message' => $m->message,
                'created_at' => $m->created_at?->toISOString(),
                'attachments' => $m->attachments->map(fn ($a) => $this->attachmentSummary($a)),
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $project = $request->attributes->get('lsm_project');

        $validated = $request->validate(array_merge([
            'type' => 'required|in:bug,content,design,feature,question,urgent',
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
            'client_email' => 'required|email',
            'client_name' => 'nullable|string|max:255',
            'problem_page' => 'nullable|string|max:500',
        ], SupportTicketAttachmentService::rules()));

        $priority = match ($validated['type']) {
            'urgent' => 'critical',
            'bug' => 'high',
            default => 'medium',
        };

        $ticket = SupportTicket::create([
            'project_id' => $project->id,
            'type' => $validated['type'],
            'subject' => $validated['subject'],
            'message' => $validated['message'],
            'client_email' => $validated['client_email'],
            'client_name' => $validated['client_name'] ?? '',
            'problem_page' => $validated['problem_page'] ?? null,
            'site_url' => $project->url,
            'status' => 'open',
            'priority' => $priority,
        ]);

        $this->attachments->store($ticket, null, $request->file('attachments', []));

        foreach ($project->notifiableTeamMembers() as $member) {
            $member->notify(new SupportTicketReceivedNotification($ticket));
        }

        return $this->createdResponse([
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
        ], 'Support ticket created successfully');
    }

    public function storeMessage(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        $project = $request->attributes->get('lsm_project');
        abort_unless($supportTicket->project_id === $project->id, 404);

        $validated = $request->validate(array_merge([
            'message' => 'required|string',
            'author_name' => 'nullable|string|max:255',
        ], SupportTicketAttachmentService::rules()));

        $message = $supportTicket->addClientMessage($validated['message'], $validated['author_name'] ?? null);
        $this->attachments->store($supportTicket, $message, $request->file('attachments', []));

        foreach ($project->notifiableTeamMembers() as $member) {
            $member->notify(new SupportTicketClientReplyNotification($supportTicket, $message));
        }

        return $this->createdResponse([
            'id' => $message->id,
            'author_type' => $message->author_type,
            'author_name' => $message->author_name,
            'message' => $message->message,
            'created_at' => $message->created_at?->toISOString(),
        ], 'Reply added');
    }

    public function downloadAttachment(Request $request, SupportTicketAttachment $attachment)
    {
        $project = $request->attributes->get('lsm_project');
        abort_unless($attachment->ticket && $attachment->ticket->project_id === $project->id, 404);

        return $this->attachments->download($attachment);
    }

    private function attachmentSummary(SupportTicketAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'filename' => $attachment->filename,
            'mime' => $attachment->mime,
            'size' => $attachment->size,
        ];
    }
}
```

- [ ] **Step 4: Register routes and throttle the legacy webhook**

In `routes/api.php`, replace the webhook registration (currently lines 73–75):

```php
    // WEBHOOKS (Public - authenticated via API key in payload)
    Route::post('/webhooks/support-ticket', [V1\SupportTicketController::class, 'receiveFromPlugin'])
        ->middleware('throttle:30,1')
        ->name('webhooks.support-ticket');

    // PLUGIN TICKETING (authenticated via X-LSM-Key header)
    Route::prefix('plugin/support-tickets')
        ->middleware(['throttle:30,1', \App\Http\Middleware\AuthenticateLsmPlugin::class])
        ->name('plugin.support-tickets.')
        ->group(function () {
            Route::get('/', [V1\PluginTicketController::class, 'index'])->name('index');
            Route::post('/', [V1\PluginTicketController::class, 'store'])->name('store');
            Route::get('/attachments/{attachment}', [V1\PluginTicketController::class, 'downloadAttachment'])
                ->name('attachments.download');
            Route::get('/{supportTicket}', [V1\PluginTicketController::class, 'show'])->name('show');
            Route::post('/{supportTicket}/messages', [V1\PluginTicketController::class, 'storeMessage'])->name('messages.store');
        });
```

(The `/attachments/{attachment}` route MUST be registered before `/{supportTicket}` so it isn't captured by the wildcard.)

- [ ] **Step 5: Notify staff from the legacy webhook**

In `app/Http/Controllers/Api/V1/SupportTicketController.php`, inside `receiveFromPlugin`, after the `SupportTicket::create([...])` call and before the `Log::info(...)` line, add:

```php
            foreach ($project->notifiableTeamMembers() as $member) {
                $member->notify(new \App\Notifications\SupportTicketReceivedNotification($ticket));
            }
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=PluginTicketEndpointsTest`
Expected: PASS (6 tests). Also run `php artisan test --filter=SupportTicket` to confirm nothing else broke.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/V1/PluginTicketController.php routes/api.php \
        app/Http/Controllers/Api/V1/SupportTicketController.php tests/Feature/PluginTicketEndpointsTest.php
git commit -m "feat: plugin-facing ticket endpoints (list, thread, create, reply, attachments)

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 6: Staff-side thread endpoints + resolved notification

**Files:**
- Create: `app/Http/Resources/SupportTicketMessageResource.php`
- Modify: `app/Http/Resources/SupportTicketResource.php` (messages + attachments whenLoaded)
- Modify: `app/Http/Controllers/Api/V1/SupportTicketController.php` (`show` loads thread; new `storeMessage` + `downloadAttachment`; `update` sends resolved notification)
- Modify: `routes/api.php` (staff message + attachment routes in the SUPPORT TICKETS block, `routes/api.php:352-362`)
- Test: `tests/Feature/StaffTicketThreadTest.php`

**Interfaces:**
- Consumes: Tasks 1–3.
- Produces:
  - `POST /api/v1/support-tickets/{support_ticket}/messages` (Sanctum; multipart `message` + `attachments[]`) → 201 `SupportTicketMessageResource`
  - `GET /api/v1/support-tickets/attachments/{attachment}` (Sanctum) → download
  - `GET /api/v1/support-tickets/{support_ticket}` now includes `messages: SupportTicketMessageResource[]` and ticket-level `attachments`.
  - Message resource shape: `{id, author_type, author_name, user_id, message, created_at, attachments: [{id, filename, mime, size}]}`

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Feature/StaffTicketThreadTest.php

use App\Models\Project;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportTicketResolvedNotification;
use App\Notifications\SupportTicketStaffReplyNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

function staffTicket(array $overrides = []): SupportTicket
{
    $project = Project::factory()->create();

    return SupportTicket::create(array_merge([
        'project_id' => $project->id,
        'type' => 'bug',
        'subject' => 'Staff thread test',
        'message' => 'desc',
        'client_email' => 'client@example.com',
        'client_name' => 'Client',
        'status' => 'open',
        'priority' => 'high',
    ], $overrides));
}

test('staff reply is stored and the client is emailed', function () {
    Notification::fake();
    Storage::fake('local');
    $admin = User::factory()->create(['role' => 'admin']);
    $ticket = staffTicket();

    $response = $this->actingAs($admin)->post("/api/v1/support-tickets/{$ticket->id}/messages", [
        'message' => 'We deployed a fix',
        'attachments' => [UploadedFile::fake()->image('fix.png')],
    ], ['Accept' => 'application/json']);

    $response->assertCreated();
    expect($ticket->messages()->count())->toBe(1);
    expect($ticket->messages()->first()->author_type)->toBe('staff');
    expect($ticket->attachments()->count())->toBe(1);

    Notification::assertSentOnDemand(
        SupportTicketStaffReplyNotification::class,
        fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'client@example.com'
    );
});

test('developer not assigned to the project cannot reply', function () {
    $developer = User::factory()->create(['role' => 'developer']);
    $ticket = staffTicket();

    $this->actingAs($developer)->post("/api/v1/support-tickets/{$ticket->id}/messages", [
        'message' => 'sneaky',
    ], ['Accept' => 'application/json'])->assertForbidden();
});

test('show includes the message thread with attachments', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $ticket = staffTicket();
    $ticket->addClientMessage('Client says hi');

    $response = $this->actingAs($admin)->getJson("/api/v1/support-tickets/{$ticket->id}");

    $response->assertOk();
    expect($response->json('data.messages'))->toHaveCount(1);
    expect($response->json('data.messages.0.author_type'))->toBe('client');
});

test('resolving a ticket via update emails the client once', function () {
    Notification::fake();
    $admin = User::factory()->create(['role' => 'admin']);
    $ticket = staffTicket();

    $this->actingAs($admin)->putJson("/api/v1/support-tickets/{$ticket->id}", [
        'status' => 'resolved',
        'resolution_notes' => 'Fixed the CSS',
    ])->assertOk();

    Notification::assertSentOnDemand(
        SupportTicketResolvedNotification::class,
        fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'client@example.com'
    );

    // updating again while already resolved must not re-send
    $this->actingAs($admin)->putJson("/api/v1/support-tickets/{$ticket->id}", [
        'priority' => 'low',
    ])->assertOk();

    Notification::assertSentOnDemandTimes(SupportTicketResolvedNotification::class, 1);
});

test('staff can download attachments, unauthenticated users cannot', function () {
    Storage::fake('local');
    $admin = User::factory()->create(['role' => 'admin']);
    $ticket = staffTicket();
    $path = UploadedFile::fake()->image('x.png')->store("support-attachments/{$ticket->id}", 'local');
    $attachment = $ticket->attachments()->create([
        'filename' => 'x.png', 'path' => $path, 'mime' => 'image/png', 'size' => 10,
    ]);

    $this->actingAs($admin)->get("/api/v1/support-tickets/attachments/{$attachment->id}")->assertOk();
    $this->getJson("/api/v1/support-tickets/attachments/{$attachment->id}")->assertStatus(401);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=StaffTicketThreadTest`
Expected: FAIL (routes missing).

- [ ] **Step 3: Create the message resource**

```php
<?php
// app/Http/Resources/SupportTicketMessageResource.php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupportTicketMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'author_type' => $this->author_type,
            'author_name' => $this->author_name,
            'user_id' => $this->user_id,
            'message' => $this->message,
            'created_at' => $this->created_at?->toISOString(),
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($a) => [
                'id' => $a->id,
                'filename' => $a->filename,
                'mime' => $a->mime,
                'size' => $a->size,
            ])),
        ];
    }
}
```

- [ ] **Step 4: Extend `SupportTicketResource`**

In `app/Http/Resources/SupportTicketResource.php`, add before the `// Timestamps` block:

```php
            // Conversation thread (loaded on show)
            'messages' => SupportTicketMessageResource::collection($this->whenLoaded('messages')),
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments
                ->whereNull('support_ticket_message_id')
                ->values()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'filename' => $a->filename,
                    'mime' => $a->mime,
                    'size' => $a->size,
                ])),
```

- [ ] **Step 5: Extend the staff controller**

In `app/Http/Controllers/Api/V1/SupportTicketController.php`:

Change `show` (line ~116) to load the thread:

```php
    public function show(SupportTicket $supportTicket): SupportTicketResource
    {
        Gate::authorize('view', $supportTicket->project);

        $supportTicket->load(['todo:id,title,status', 'messages.attachments', 'attachments']);

        // Auto-mark as read when viewing
        $supportTicket->markAsRead();

        return new SupportTicketResource($supportTicket);
    }
```

In `update` (line ~135), replace the resolved_at block with one that also emails the client on the transition:

```php
        // If marking as resolved, set resolved_at and email the client (only on transition)
        $becameResolved = isset($validated['status'])
            && $validated['status'] === 'resolved'
            && $supportTicket->status !== 'resolved';

        if ($becameResolved && !$supportTicket->resolved_at) {
            $validated['resolved_at'] = now();
        }

        $supportTicket->update($validated);

        if ($becameResolved && $supportTicket->client_email) {
            \Illuminate\Support\Facades\Notification::route('mail', $supportTicket->client_email)
                ->notify(new \App\Notifications\SupportTicketResolvedNotification($supportTicket));
        }

        $supportTicket->load('todo:id,title,status');
```

Add the two new actions after `unreadCount`:

```php
    /**
     * Add a staff reply to the ticket thread and email the client.
     */
    public function storeMessage(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        Gate::authorize('update', $supportTicket->project);

        $validated = $request->validate(array_merge(
            ['message' => 'required|string'],
            \App\Services\SupportTicketAttachmentService::rules()
        ));

        $message = $supportTicket->addStaffMessage($request->user(), $validated['message']);
        app(\App\Services\SupportTicketAttachmentService::class)
            ->store($supportTicket, $message, $request->file('attachments', []));

        if ($supportTicket->client_email) {
            \Illuminate\Support\Facades\Notification::route('mail', $supportTicket->client_email)
                ->notify(new \App\Notifications\SupportTicketStaffReplyNotification($supportTicket, $message));
        }

        return $this->createdResponse(
            new \App\Http\Resources\SupportTicketMessageResource($message->load('attachments')),
            'Reply added'
        );
    }

    /**
     * Download a ticket attachment (staff).
     */
    public function downloadAttachment(\App\Models\SupportTicketAttachment $attachment)
    {
        Gate::authorize('view', $attachment->ticket->project);

        return app(\App\Services\SupportTicketAttachmentService::class)->download($attachment);
    }
```

- [ ] **Step 6: Register the staff routes**

In `routes/api.php`, inside the SUPPORT TICKETS block (after the `create-todo` route at line ~360), add:

```php
        Route::post('/support-tickets/{support_ticket}/messages', [V1\SupportTicketController::class, 'storeMessage'])
            ->name('support-tickets.messages.store');
        Route::get('/support-tickets/attachments/{attachment}', [V1\SupportTicketController::class, 'downloadAttachment'])
            ->name('support-tickets.attachments.download');
```

Note: `Route::apiResource('projects.support-tickets', ...)->shallow()` registers `GET /support-tickets/{support_ticket}`. The literal `attachments` segment would be captured by that wildcard for GET — but the apiResource is registered with implicit model binding on numeric ids only if a `where` is applied, which it is not. To be safe, register the attachments route BEFORE the apiResource line, i.e. move it directly above `Route::apiResource('projects.support-tickets', ...)`:

```php
        Route::get('/support-tickets', [V1\SupportTicketController::class, 'indexAll'])
            ->name('support-tickets.index-all');
        Route::get('/support-tickets/attachments/{attachment}', [V1\SupportTicketController::class, 'downloadAttachment'])
            ->name('support-tickets.attachments.download');
        Route::apiResource('projects.support-tickets', V1\SupportTicketController::class)->shallow();
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=StaffTicketThreadTest`
Expected: PASS (5 tests).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Resources/SupportTicketMessageResource.php app/Http/Resources/SupportTicketResource.php \
        app/Http/Controllers/Api/V1/SupportTicketController.php routes/api.php tests/Feature/StaffTicketThreadTest.php
git commit -m "feat: staff ticket replies, thread in show response, resolved-email to client

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 7: Full-suite verification

**Files:** none

- [ ] **Step 1: Run the entire test suite**

Run: `php artisan test`
Expected: PASS — baseline count (Task 0) + ~23 new tests, zero failures.

- [ ] **Step 2: Route sanity check**

Run: `php artisan route:list --path=plugin/support-tickets && php artisan route:list --path=support-tickets`
Expected: all new routes listed with correct middleware (plugin group shows `throttle:30,1` + `AuthenticateLsmPlugin`; staff routes show `auth:sanctum`).

- [ ] **Step 3: End-to-end smoke test via local server**

With `php artisan serve` running and a scratch project (`health_check_secret = 'SMOKE_KEY'`):

```bash
# create with attachment
curl -s -X POST http://localhost:8000/api/v1/plugin/support-tickets \
  -H 'X-LSM-Key: SMOKE_KEY' -H 'Accept: application/json' \
  -F 'type=bug' -F 'subject=Smoke test' -F 'message=E2E check' \
  -F 'client_email=smoke@test.com' -F 'attachments[]=@/tmp/test.png'
# list
curl -s http://localhost:8000/api/v1/plugin/support-tickets -H 'X-LSM-Key: SMOKE_KEY'
# thread
curl -s http://localhost:8000/api/v1/plugin/support-tickets/<ID> -H 'X-LSM-Key: SMOKE_KEY'
# client reply
curl -s -X POST http://localhost:8000/api/v1/plugin/support-tickets/<ID>/messages \
  -H 'X-LSM-Key: SMOKE_KEY' -F 'message=More info'
```

Expected: 201/200 responses; `MAIL_MAILER=log` in dev — check `storage/logs/laravel.log` contains the staff notification mails.

- [ ] **Step 4: Final commit if any fixups were needed**
