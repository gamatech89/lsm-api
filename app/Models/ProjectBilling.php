<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectBilling extends Model
{
    use HasFactory;

    protected $table = 'project_billing';

    protected $fillable = [
        'project_id',
        'billing_type',
        'hourly_rate',
        'fixed_price',
        'monthly_retainer',
        'currency',
        'estimated_hours',
        'billing_notes',
    ];

    protected $casts = [
        'hourly_rate' => 'decimal:2',
        'fixed_price' => 'decimal:2',
        'monthly_retainer' => 'decimal:2',
    ];

    /**
     * Billing type constants
     */
    const TYPE_HOURLY = 'hourly';
    const TYPE_FIXED = 'fixed';
    const TYPE_MONTHLY = 'monthly';

    /**
     * Relationships
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Calculate cost for given minutes
     */
    public function calculateCost(int $minutes): float
    {
        switch ($this->billing_type) {
            case self::TYPE_HOURLY:
                return round(($minutes / 60) * $this->hourly_rate, 2);
            case self::TYPE_FIXED:
                return $this->fixed_price ?? 0;
            case self::TYPE_MONTHLY:
                return $this->monthly_retainer ?? 0;
            default:
                return 0;
        }
    }

    /**
     * Get formatted rate
     */
    public function getFormattedRateAttribute(): string
    {
        $symbol = $this->currency === 'EUR' ? '€' : $this->currency;

        switch ($this->billing_type) {
            case self::TYPE_HOURLY:
                return "{$symbol}{$this->hourly_rate}/hr";
            case self::TYPE_FIXED:
                return "{$symbol}{$this->fixed_price} (fixed)";
            case self::TYPE_MONTHLY:
                return "{$symbol}{$this->monthly_retainer}/mo";
            default:
                return '-';
        }
    }
}
