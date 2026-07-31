<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Http\Controllers\Api\V1\DashboardController;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Project extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'name',
        'url',
        'client_email',
        'notes',
        'health_status',
        'security_status',
        'project_external_id',
        'maintenance_id',
        'health_check_secret',
        'manager_id',
        'developer_id',
        'is_maintenance',
        // Health monitoring fields
        'last_health_check_at',
        'response_time_ms',
        'last_health_details',
        'wp_version',
        'php_version',
        'outdated_plugins_count',
        'ssl_status',
        'ssl_expires_at',
        'uptime_monitoring_enabled',
        // Monitoring & notification fields
        'ssl_alerts_enabled',
        'domain_alerts_enabled',
        'notification_preferences',
        // Domain expiry (WHOIS)
        'domain_expires_at',
        'domain_registrar',
        // Security scanning
        'last_security_scan_at',
        'last_security_scan_risk',
    ];

    protected $appends = ['highest_todo_priority'];

    /**
     * Never expose the lookup hash in serialized output.
     */
    protected $hidden = ['health_check_secret_hash', 'highest_todo_priority_rank'];

    protected function casts(): array
    {
        return [
            'health_check_secret' => EncryptedString::class,
            'last_health_check_at' => 'datetime',
            'ssl_expires_at' => 'date',
            'domain_expires_at' => 'date',
            'last_health_details' => 'array',
            'uptime_monitoring_enabled' => 'boolean',
            'ssl_alerts_enabled' => 'boolean',
            'domain_alerts_enabled' => 'boolean',
            'notification_preferences' => 'array',
            'last_security_scan_at' => 'datetime',
        ];
    }

    /**
     * Get the highest priority from pending todos.
     */
    public function getHighestTodoPriorityAttribute(): ?string
    {
        // Fast path: use the rank precomputed by a list query's subselect
        // (see ProjectController::index) to avoid an N+1 todos load per row.
        if (array_key_exists('highest_todo_priority_rank', $this->attributes)) {
            $rank = (int) $this->attributes['highest_todo_priority_rank'];
            return [4 => 'urgent', 3 => 'high', 2 => 'medium', 1 => 'low'][$rank] ?? null;
        }

        $priorities = ['low', 'medium', 'high', 'urgent'];
        $priorityOrder = array_flip($priorities);

        $highestPriority = null;
        $highestOrder = -1;

        foreach ($this->todos as $todo) {
            $order = $priorityOrder[$todo->priority] ?? -1;
            if ($order > $highestOrder) {
                $highestOrder = $order;
                $highestPriority = $todo->priority;
            }
        }

        return $highestPriority;
    }

    /**
     * Correlated subselect that ranks a project's highest todo priority
     * (4=urgent … 1=low) in a single query, for list endpoints.
     */
    public static function highestTodoPriorityRankSubquery(): \Illuminate\Database\Query\Builder
    {
        return Todo::query()
            ->selectRaw("MAX(CASE priority WHEN 'urgent' THEN 4 WHEN 'high' THEN 3 WHEN 'medium' THEN 2 WHEN 'low' THEN 1 ELSE 0 END)")
            ->whereColumn('todos.project_id', 'projects.id')
            ->getQuery();
    }

    /**
     * Clear dashboard cache when project status changes.
     */
    protected static function booted(): void
    {
        // Keep the deterministic lookup hash in sync with the (encrypted) secret.
        static::saving(function (Project $project) {
            if ($project->isDirty('health_check_secret')) {
                $secret = $project->health_check_secret; // decrypted via cast
                $project->health_check_secret_hash = ($secret !== null && $secret !== '')
                    ? hash('sha256', $secret)
                    : null;
            }
        });

        static::saved(function (Project $project) {
            if ($project->isDirty(['health_status', 'security_status'])) {
                DashboardController::clearCache();
            }
        });

        static::deleting(function (Project $project) {
            // Cascade delete related records
            $project->credentials()->delete();
            $project->resources()->delete();
            $project->todos()->delete();
            $project->tags()->detach();
            $project->developers()->detach();
            $project->managers()->detach();
        });

        static::deleted(function () {
            DashboardController::clearCache();
        });
    }

    /**
     * Configure activity logging options.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'url', 'health_status', 'security_status', 'manager_id', 'developer_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => "Project has been {$eventName}");
    }

    /**
     * Get the manager of the project (legacy single manager).
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * Get all managers assigned to the project.
     */
    public function managers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_manager')
            ->withTimestamps();
    }

    /**
     * Determine whether the given user manages this project.
     *
     * Accepts BOTH the legacy single manager_id column AND the
     * project_manager pivot. This is the single source of truth for
     * manager membership: every manager-membership check platform-wide
     * (policies, MCP tools, controllers) must use this method instead of
     * checking the pivot or the column directly.
     */
    public function isManagedBy(User $user): bool
    {
        return $this->manager_id === $user->id
            || $this->managers()->where('user_id', $user->id)->exists();
    }

    /**
     * Get the developer assigned to the project (legacy single developer).
     */
    public function developer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'developer_id');
    }

    /**
     * Get all developers assigned to the project.
     */
    public function developers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_developer')
            ->withTimestamps();
    }

    /**
     * All users who should be notified about this project's support tickets:
     * every admin plus the assigned managers and developers (legacy single
     * columns and many-to-many pivots), deduplicated.
     */
    public function notifiableTeamMembers(): \Illuminate\Support\Collection
    {
        $members = User::where('role', 'admin')->orWhere('is_admin', true)->get()
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

    /**
     * Get the credentials for the project.
     */
    public function credentials(): HasMany
    {
        return $this->hasMany(Credential::class);
    }

    /**
     * Get the resources for the project.
     */
    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class);
    }

    /**
     * Get the todos for the project.
     */
    public function todos(): HasMany
    {
        return $this->hasMany(Todo::class);
    }

    /**
     * Get the maintenance reports for the project.
     */
    public function maintenanceReports(): HasMany
    {
        return $this->hasMany(MaintenanceReport::class);
    }

    /**
     * Get the support tickets for the project.
     */
    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    /**
     * Get the tags for the project.
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * Get the time entries for the project.
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * Get the backups for the project.
     */
    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }

    /**
     * Get the PHP errors for the project.
     */
    public function phpErrors(): HasMany
    {
        return $this->hasMany(PhpError::class);
    }

    /**
     * Get the library resources linked to this project.
     */
    public function libraryResources(): BelongsToMany
    {
        return $this->belongsToMany(LibraryResource::class, 'project_library_resource')
            ->withTimestamps();
    }

    /**
     * Get the uptime check history for this project.
     */
    public function uptimeChecks(): HasMany
    {
        return $this->hasMany(UptimeCheck::class)->orderBy('checked_at', 'desc');
    }

    /**
     * Get the security scans for this project.
     */
    public function securityScans(): HasMany
    {
        return $this->hasMany(SecurityScan::class)->orderBy('created_at', 'desc');
    }

    public function siteReviews(): HasMany
    {
        return $this->hasMany(SiteReview::class);
    }


    /**
     * Update the tracked time for this project.
     * Calculates total minutes from all completed time entries.
     */
    public function updateTrackedTime(): void
    {
        $totalMinutes = $this->timeEntries()
            ->whereNotNull('ended_at')
            ->sum('duration_minutes');

        // Only update if the column exists (added via migration)
        if (\Schema::hasColumn('projects', 'tracked_minutes')) {
            $this->update(['tracked_minutes' => $totalMinutes]);
        }
    }

    /**
     * Generate a unique external ID in format LP + 5 digits.
     */
    public static function generateExternalId(): string
    {
        $lastProject = static::orderBy('id', 'desc')->first();
        
        if ($lastProject && $lastProject->project_external_id) {
            // Extract the number from the last external ID
            $lastNumber = (int) substr($lastProject->project_external_id, 2);
            $newNumber = $lastNumber + 1;
        } else {
            // Start from 10001 if no projects exist
            $newNumber = 10001;
        }

        return 'LP' . str_pad($newNumber, 5, '0', STR_PAD_LEFT);
    }
}
