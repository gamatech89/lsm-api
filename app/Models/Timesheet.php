<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class Timesheet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'week_number',
        'year',
        'week_start',
        'week_end',
        'status',
        'total_minutes',
        'total_billable_minutes',
        'submitted_at',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'notes',
    ];

    protected $casts = [
        'week_start' => 'date',
        'week_end' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    /**
     * Status constants
     */
    const STATUS_OPEN = 'open';
    const STATUS_SUBMITTED = 'submitted';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_PAID = 'paid';

    /**
     * Relationships
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * Scopes
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopePendingApproval($query)
    {
        return $query->where('status', self::STATUS_SUBMITTED);
    }

    public function scopeForWeek($query, $weekNumber, $year)
    {
        return $query->where('week_number', $weekNumber)->where('year', $year);
    }

    /**
     * Get or create timesheet for a specific week
     */
    public static function getOrCreateForWeek(int $userId, ?Carbon $date = null): self
    {
        $date = $date ?? Carbon::now();
        
        $weekNumber = $date->isoWeek();
        $year = $date->isoWeekYear();
        $weekStart = $date->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $date->copy()->endOfWeek(Carbon::SUNDAY);

        return static::firstOrCreate(
            [
                'user_id' => $userId,
                'week_number' => $weekNumber,
                'year' => $year,
            ],
            [
                'week_start' => $weekStart,
                'week_end' => $weekEnd,
                'status' => self::STATUS_OPEN,
            ]
        );
    }

    /**
     * Get current week's timesheet for a user
     */
    public static function currentWeek(int $userId): self
    {
        return static::getOrCreateForWeek($userId);
    }

    /**
     * Recalculate totals from entries
     */
    public function recalculateTotals(): self
    {
        $this->total_minutes = $this->entries()->completed()->sum('duration_minutes');
        $this->total_billable_minutes = $this->entries()->completed()->billable()->sum('duration_minutes');
        $this->save();

        return $this;
    }

    /**
     * Submit timesheet for approval
     */
    public function submit(): self
    {
        $this->recalculateTotals();
        $this->status = self::STATUS_SUBMITTED;
        $this->submitted_at = Carbon::now();
        $this->save();

        // Update all entries to submitted
        $this->entries()->where('status', TimeEntry::STATUS_DRAFT)->update([
            'status' => TimeEntry::STATUS_SUBMITTED,
        ]);

        return $this;
    }

    /**
     * Approve timesheet
     */
    public function approve(int $approverId): self
    {
        $this->status = self::STATUS_APPROVED;
        $this->approved_by = $approverId;
        $this->approved_at = Carbon::now();
        $this->rejection_reason = null;
        $this->save();

        // Update all entries to approved
        $this->entries()->update([
            'status' => TimeEntry::STATUS_APPROVED,
            'approved_by' => $approverId,
            'approved_at' => Carbon::now(),
        ]);

        return $this;
    }

    /**
     * Reject timesheet
     */
    public function reject(int $approverId, string $reason): self
    {
        $this->status = self::STATUS_REJECTED;
        $this->approved_by = $approverId;
        $this->approved_at = Carbon::now();
        $this->rejection_reason = $reason;
        $this->save();

        // Update all entries to rejected
        $this->entries()->update([
            'status' => TimeEntry::STATUS_REJECTED,
            'rejection_reason' => $reason,
        ]);

        return $this;
    }

    /**
     * Get formatted total
     */
    public function getFormattedTotalAttribute(): string
    {
        $hours = floor($this->total_minutes / 60);
        $mins = $this->total_minutes % 60;
        return sprintf('%02d:%02d', $hours, $mins);
    }

    /**
     * Get week label (e.g., "Week 2, 2026")
     */
    public function getWeekLabelAttribute(): string
    {
        return "Week {$this->week_number}, {$this->year}";
    }
}
