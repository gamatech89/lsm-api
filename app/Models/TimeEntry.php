<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class TimeEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'project_id',
        'description',
        'started_at',
        'ended_at',
        'duration_minutes',
        'is_billable',
        'status',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'timesheet_id',
        'todo_id',
        'invoice_id',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'approved_at' => 'datetime',
        'is_billable' => 'boolean',
    ];

    /**
     * Status constants
     */
    const STATUS_DRAFT = 'draft';
    const STATUS_SUBMITTED = 'submitted';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_PAID = 'paid';

    /**
     * Boot methods
     */
    protected static function booted(): void
    {
        static::saving(function ($entry) {
            // Calculate duration when timer stops
            if ($entry->ended_at && $entry->started_at) {
                $entry->duration_minutes = $entry->started_at->diffInMinutes($entry->ended_at);
            }
        });

        static::saved(function ($entry) {
            // Update project tracked time
            if ($entry->duration_minutes) {
                $entry->project?->updateTrackedTime();
            }
        });
    }

    /**
     * Relationships
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function timesheet(): BelongsTo
    {
        return $this->belongsTo(Timesheet::class);
    }

    public function todo(): BelongsTo
    {
        return $this->belongsTo(Todo::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Scopes
     */
    public function scopeRunning($query)
    {
        return $query->whereNull('ended_at');
    }

    public function scopeCompleted($query)
    {
        return $query->whereNotNull('ended_at');
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForProject($query, $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    public function scopeInDateRange($query, $start, $end)
    {
        return $query->whereBetween('started_at', [$start, $end]);
    }

    public function scopeBillable($query)
    {
        return $query->where('is_billable', true);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Check if entry is running (timer active)
     */
    public function isRunning(): bool
    {
        return is_null($this->ended_at);
    }

    /**
     * Stop the timer
     */
    public function stop(): self
    {
        if ($this->isRunning()) {
            $this->ended_at = Carbon::now();
            $this->save();
        }
        return $this;
    }

    /**
     * Get formatted duration
     */
    public function getFormattedDurationAttribute(): string
    {
        $minutes = $this->duration_minutes ?? 0;
        
        if ($this->isRunning()) {
            $minutes = $this->started_at->diffInMinutes(Carbon::now());
        }

        $hours = floor($minutes / 60);
        $mins = $minutes % 60;

        return sprintf('%02d:%02d', $hours, $mins);
    }

    /**
     * Get duration in hours (decimal)
     */
    public function getDurationHoursAttribute(): float
    {
        return round(($this->duration_minutes ?? 0) / 60, 2);
    }
}
