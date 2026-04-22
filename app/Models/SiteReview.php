<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SiteReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'created_by',
        'title',
        'url',
        'status',
        'share_token',
        'share_password',
        'share_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'share_expires_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function annotations(): HasMany
    {
        return $this->hasMany(SiteReviewAnnotation::class, 'review_id');
    }

    // Only root-level pins (not replies)
    public function pins(): HasMany
    {
        return $this->hasMany(SiteReviewAnnotation::class, 'review_id')->whereNull('parent_id');
    }

    public function generateShareToken(): string
    {
        $token = Str::random(40);
        $this->update(['share_token' => $token]);
        return $token;
    }

    public function isShareValid(): bool
    {
        if (! $this->share_token) {
            return false;
        }
        if ($this->share_expires_at && $this->share_expires_at->isPast()) {
            return false;
        }
        return true;
    }

    public function isSharePasswordCorrect(?string $password): bool
    {
        if (! $this->share_password) {
            return true;
        }
        return $this->share_password === $password;
    }
}
