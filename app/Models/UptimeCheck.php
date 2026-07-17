<?php

namespace App\Models;

use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uptime Check Model
 *
 * Stores individual uptime check results for historical tracking.
 *
 * @property int $id
 * @property int $project_id
 * @property string $status up|down|error|confirming
 * @property int|null $http_status
 * @property int|null $response_time_ms
 * @property string|null $error_message
 * @property \Carbon\Carbon $checked_at
 */
class UptimeCheck extends Model
{
    use MassPrunable;

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
     * Prune check history past the retention window (see uptime.retention_days).
     */
    public function prunable()
    {
        $days = (int) config('uptime.retention_days', 90);

        return static::where('checked_at', '<', now()->subDays($days));
    }

    /**
     * Get uptime stats for a project (count, up, down, percentage).
     *
     * uptime_percentage and avg_response_time are null when there is no
     * completed check in the window — "no data" is not the same as 0% uptime.
     */
    public static function getStats(int $projectId, int $days = 30): array
    {
        // Exclude 'confirming' — it's an in-progress state, not a completed check result.
        $aggregates = static::forProject($projectId)
            ->lastDays($days)
            ->where('status', '!=', 'confirming')
            ->selectRaw("COUNT(*) as total")
            ->selectRaw("SUM(CASE WHEN status = 'up' THEN 1 ELSE 0 END) as up")
            ->selectRaw("SUM(CASE WHEN status IN ('down', 'error') THEN 1 ELSE 0 END) as down")
            ->selectRaw("AVG(response_time_ms) as avg_response_time")
            ->first();

        $total = (int) $aggregates->total;
        $up = (int) $aggregates->up;

        return [
            'total_checks' => $total,
            'up_count' => $up,
            'down_count' => (int) $aggregates->down,
            'uptime_percentage' => $total > 0 ? round(($up / $total) * 100, 2) : null,
            'avg_response_time' => $aggregates->avg_response_time !== null
                ? (float) $aggregates->avg_response_time
                : null,
        ];
    }
}
