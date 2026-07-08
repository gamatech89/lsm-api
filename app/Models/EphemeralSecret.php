<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class EphemeralSecret extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'token', 'created_by', 'title', 'payload',
        'access_password', 'expires_at', 'viewed_at', 'last_viewed_ip',
    ];

    protected $hidden = ['payload', 'access_password'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'access_password' => 'hashed',
            'expires_at' => 'datetime',
            'viewed_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isBurned(): bool
    {
        return $this->viewed_at !== null;
    }

    public function isAvailable(): bool
    {
        return ! $this->isExpired() && ! $this->isBurned();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        // Title only — the secret payload is never logged.
        return LogOptions::defaults()->logOnly(['title'])->dontSubmitEmptyLogs();
    }
}
