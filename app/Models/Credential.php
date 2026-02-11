<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Credential extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'project_id',
        'title',
        'type',
        'username',
        'password',
        'url',
        'metadata',
        'note',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'username' => 'encrypted',
            'metadata' => 'array',
        ];
    }

    /**
     * Configure activity logging options.
     * Note: Password and username are not logged for security.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'type', 'url'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => "Credential '{$this->title}' has been {$eventName}");
    }

    /**
     * Get the project that owns the credential.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the share links for this credential.
     */
    public function shareLinks(): HasMany
    {
        return $this->hasMany(CredentialShareLink::class);
    }

    /**
     * Get active (valid) share links count.
     */
    public function activeShareLinksCount(): int
    {
        return $this->shareLinks()
            ->where('expires_at', '>', now())
            ->whereColumn('view_count', '<', 'max_views')
            ->count();
    }
}
