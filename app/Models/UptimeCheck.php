<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uptime Check Model
 * 
 * Stores individual uptime check results for historical tracking.
 * 
 * @property int $id
 * @property int $project_id
 * @property string $status up|down|error|timeout
 * @property int|null $http_status
 * @property int|null $response_time_ms
 * @property string|null $error_message
 * @property \Carbon\Carbon $checked_at
 */
class UptimeCheck extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'project_id',
        'status',
        'http_status',
        'response_time_ms',
        'error_message',
        'checked_at',
    ];

    protected $casts = [
        'checked_at' => 'datetime',
    ];

    /**
     * Relationship: Belongs to a project.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Check if this was a successful (up) status.
     */
    public function isUp(): bool
    {
        return $this->status === 'up';
    }

    /**
     * Scope: Filter by project.
     */
    public function scopeForProject($query, int $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    /**
     * Scope: Filter by last N days.
     */
    public function scopeLastDays($query, int $days)
    {
        return $query->where('checked_at', '>=', now()->subDays($days));
    }

    /**
     * Calculate uptime percentage for a project over the last N days.
     */
    public static function calculateUptime(int $projectId, int $days = 30): float
    {
        $checks = static::forProject($projectId)
            ->lastDays($days)
            ->get();

        if ($checks->isEmpty()) {
            return 0;
        }

        $upCount = $checks->where('status', 'up')->count();
        $totalCount = $checks->whereNotIn('status', ['confirming'])->count();

        return round(($upCount / $totalCount) * 100, 2);
    }

    /**
     * Get uptime stats for a project (count, up, down, percentage).
     */
    public static function getStats(int $projectId, int $days = 30): array
    {
        $checks = static::forProject($projectId)
            ->lastDays($days)
            ->get();

        // Exclude 'confirming' — it's an in-progress state, not a completed check result.
        $total = $checks->whereNotIn('status', ['confirming'])->count();
        $up = $checks->where('status', 'up')->count();
        $redirect = $checks->where('status', 'redirect')->count();
        $down = $checks->whereIn('status', ['down', 'error', 'timeout'])->count();

        // Redirects and down status both count against uptime
        $successfulChecks = $up;
        $uptimePercentage = $total > 0 ? round(($successfulChecks / $total) * 100, 2) : 0;

        return [
            'total_checks' => $total,
            'up_count' => $up,
            'redirect_count' => $redirect,
            'down_count' => $down,
            'uptime_percentage' => $uptimePercentage,
            'avg_response_time' => $checks->whereNotNull('response_time_ms')->avg('response_time_ms') ?? 0,
        ];
    }
}
