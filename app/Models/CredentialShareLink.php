<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CredentialShareLink extends Model
{
    protected $fillable = [
        'credential_id',
        'created_by',
        'token',
        'access_password',
        'expires_at',
        'max_views',
        'view_count',
        'show_username',
        'show_password',
        'show_url',
        'recipient_email',
        'note',
        'last_viewed_at',
        'last_viewed_ip',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_viewed_at' => 'datetime',
            'show_username' => 'boolean',
            'show_password' => 'boolean',
            'show_url' => 'boolean',
            'access_password' => 'hashed',
        ];
    }

    /**
     * Generate a new share link for a credential.
     */
    public static function createForCredential(
        int $credentialId,
        int $userId,
        array $options = []
    ): self {
        return self::create([
            'credential_id' => $credentialId,
            'created_by' => $userId,
            'token' => Str::random(64),
            'access_password' => $options['password'] ?? null,
            'expires_at' => $options['expires_at'] ?? now()->addHours(24),
            'max_views' => $options['max_views'] ?? 1,
            'show_username' => $options['show_username'] ?? true,
            'show_password' => $options['show_password'] ?? true,
            'show_url' => $options['show_url'] ?? true,
            'recipient_email' => $options['recipient_email'] ?? null,
            'note' => $options['note'] ?? null,
        ]);
    }

    /**
     * Check if the share link is still valid.
     */
    public function isValid(): bool
    {
        if ($this->expires_at->isPast()) {
            return false;
        }

        if ($this->view_count >= $this->max_views) {
            return false;
        }

        return true;
    }

    /**
     * Check if the share link has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if view limit has been reached.
     */
    public function hasReachedViewLimit(): bool
    {
        return $this->view_count >= $this->max_views;
    }

    /**
     * Record an access attempt.
     */
    public function recordAccess(string $ip, ?string $userAgent = null, bool $passwordCorrect = true): void
    {
        $this->accessLogs()->create([
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'password_correct' => $passwordCorrect,
            'accessed_at' => now(),
        ]);

        if ($passwordCorrect) {
            $this->increment('view_count');
            $this->update([
                'last_viewed_at' => now(),
                'last_viewed_ip' => $ip,
            ]);
        }
    }

    /**
     * Get the full share URL.
     */
    public function getShareUrl(): string
    {
        return url("/share/credential/{$this->token}");
    }

    /**
     * Get time remaining before expiry.
     */
    public function getTimeRemaining(): string
    {
        if ($this->isExpired()) {
            return 'Expired';
        }

        return $this->expires_at->diffForHumans();
    }

    /**
     * Relationships
     */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(CredentialShareAccessLog::class, 'share_link_id');
    }
}
