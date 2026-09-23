<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One "pause for download" of the block_archives .htaccess rule.
 *
 * Written BEFORE the plugin is called (write-ahead), so a pause whose response
 * got lost still has a row the hardening:resume-expired backstop can find.
 * A row is open while both resumed_at and failed_at are null.
 */
class ProjectHardeningPause extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'user_id',
        'paused_until',
        'resumed_at',
        'failed_at',
        'overdue_notified_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'paused_until' => 'datetime',
            'resumed_at' => 'datetime',
            'failed_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Rows that are neither resumed nor given up on.
     */
    public function scopeOpen($query)
    {
        return $query->whereNull('resumed_at')->whereNull('failed_at');
    }
}
