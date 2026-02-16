<?php

namespace App\Models;

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

    protected function casts(): array
    {
        return [
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
     * Clear dashboard cache when project status changes.
     */
    protected static function booted(): void
    {
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
