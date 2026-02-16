<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityScan extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'scan_type',
        'status',
        'risk_level',
        'threats_found',
        'warnings_found',
        'files_scanned',
        'duration_seconds',
        'results',
        'summary',
        'triggered_by',
        'triggered_by_user_id',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'results' => 'array',
            'summary' => 'array',
            'threats_found' => 'integer',
            'warnings_found' => 'integer',
            'files_scanned' => 'integer',
            'duration_seconds' => 'decimal:2',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Get the project that owns this scan.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the user who triggered this scan (if manual).
     */
    public function triggeredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    /**
     * Scope: only completed scans.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope: scans with threats.
     */
    public function scopeWithThreats($query)
    {
        return $query->where('threats_found', '>', 0);
    }

    /**
     * Check if scan found any threats.
     */
    public function hasThreats(): bool
    {
        return $this->threats_found > 0;
    }

    /**
     * Get the severity label for display.
     */
    public function getSeverityLabelAttribute(): string
    {
        return match ($this->risk_level) {
            'critical' => '🔴 Critical',
            'high' => '🟠 High Risk',
            'medium' => '🟡 Medium Risk',
            'low' => '🟢 Low Risk',
            'clean' => '✅ Clean',
            default => '⚪ Unknown',
        };
    }
}
