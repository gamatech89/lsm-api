<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Invoice extends Model
{
    // Invoice statuses
    const STATUS_DRAFT = 'draft';
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_DECLINED = 'declined';
    const STATUS_PAID = 'paid';

    protected $fillable = [
        'user_id',
        'timesheet_id',
        'invoice_number',
        'period_start',
        'period_end',
        'total_hours',
        'total_amount',
        'status',
        'notes',
        'approved_by',
        'approved_at',
        'paid_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'total_hours' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    /**
     * Generate unique invoice number with database locking to prevent duplicates.
     * Uses pessimistic locking to ensure atomic invoice number generation.
     */
    public static function generateInvoiceNumber(): string
    {
        return DB::transaction(function () {
            $year = date('Y');
            $month = date('m');
            
            // Lock the table to prevent concurrent reads during count
            $count = static::whereYear('created_at', $year)
                ->whereMonth('created_at', $month)
                ->lockForUpdate()
                ->count() + 1;
            
            return sprintf('INV-%s%s-%04d', $year, $month, $count);
        });
    }

    /**
     * User who this invoice is for
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Related timesheet
     */
    public function timesheet(): BelongsTo
    {
        return $this->belongsTo(Timesheet::class);
    }

    /**
     * User who approved the invoice
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Time entries included in this invoice
     */
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

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Mark invoice as approved
     */
    public function approve(int $approverId): void
    {
        $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_by' => $approverId,
            'approved_at' => now(),
        ]);
    }

    /**
     * Mark invoice as paid
     */
    public function markAsPaid(): void
    {
        $this->update([
            'status' => self::STATUS_PAID,
            'paid_at' => now(),
        ]);
    }

    /**
     * Decline invoice
     */
    public function decline(): void
    {
        $this->update([
            'status' => self::STATUS_DECLINED,
        ]);
    }

    /**
     * Formatted total
     */
    public function getFormattedTotalAttribute(): string
    {
        return '$' . number_format($this->total_amount, 2);
    }

    /**
     * Formatted hours
     */
    public function getFormattedHoursAttribute(): string
    {
        $hours = floor($this->total_hours);
        $minutes = round(($this->total_hours - $hours) * 60);
        return sprintf('%d:%02d', $hours, $minutes);
    }
}
