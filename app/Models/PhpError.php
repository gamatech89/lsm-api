<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhpError extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'type',
        'message',
        'file',
        'line',
        'error_hash',
        'count',
        'first_seen_at',
        'last_seen_at',
        'wordpress_version',
        'php_version',
        'plugin_slug',
        'theme_slug',
        'is_resolved',
        'resolved_at',
        'resolved_by',
    ];

    protected $casts = [
        'line' => 'integer',
        'count' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'is_resolved' => 'boolean',
        'resolved_at' => 'datetime',
    ];

    /**
     * Get the project this error belongs to.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the user who resolved this error.
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Generate error hash for grouping similar errors.
     */
    public static function generateHash(string $type, string $message, ?string $file, ?int $line): string
    {
        // Normalize the message by removing dynamic parts like timestamps or IDs
        $normalizedMessage = preg_replace('/\d+/', 'X', $message);
        
        return hash('sha256', implode('|', [$type, $normalizedMessage, $file ?? '', $line ?? 0]));
    }

    /**
     * Log or update a PHP error.
     * If a similar error exists (same hash), increment the count.
     */
    public static function logError(
        int $projectId,
        string $type,
        string $message,
        ?string $file = null,
        ?int $line = null,
        array $metadata = []
    ): self {
        $hash = self::generateHash($type, $message, $file, $line);
        
        $error = self::updateOrCreate(
            [
                'project_id' => $projectId,
                'error_hash' => $hash,
            ],
            [
                'type' => $type,
                'message' => $message,
                'file' => $file,
                'line' => $line,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'wordpress_version' => $metadata['wordpress_version'] ?? null,
                'php_version' => $metadata['php_version'] ?? null,
                'plugin_slug' => $metadata['plugin_slug'] ?? null,
                'theme_slug' => $metadata['theme_slug'] ?? null,
            ]
        );

        // If existing error, update last_seen and increment count
        if ($error->wasRecentlyCreated === false) {
            $error->increment('count');
            $error->update(['last_seen_at' => now()]);
        }

        return $error;
    }

    /**
     * Mark error as resolved.
     */
    public function markResolved(int $userId): void
    {
        $this->update([
            'is_resolved' => true,
            'resolved_at' => now(),
            'resolved_by' => $userId,
        ]);
    }

    /**
     * Scope to filter by project.
     */
    public function scopeForProject($query, $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    /**
     * Scope to filter by type.
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to get unresolved errors.
     */
    public function scopeUnresolved($query)
    {
        return $query->where('is_resolved', false);
    }

    /**
     * Scope to order by most recent.
     */
    public function scopeRecent($query)
    {
        return $query->orderByDesc('last_seen_at');
    }
}
