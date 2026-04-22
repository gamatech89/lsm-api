<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteReviewAnnotation extends Model
{
    use HasFactory;

    protected $fillable = [
        'review_id',
        'parent_id',
        'created_by',
        'todo_id',
        'x_percent',
        'y_percent',
        'scroll_y',
        'comment',
        'author_type',
        'author_name',
        'author_email',
        'screenshot_path',
        'resolved_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'x_percent'   => 'float',
            'y_percent'   => 'float',
            'scroll_y'    => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(SiteReview::class, 'review_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(SiteReviewAnnotation::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(SiteReviewAnnotation::class, 'parent_id')->orderBy('created_at');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function todo(): BelongsTo
    {
        return $this->belongsTo(Todo::class);
    }

    public function isPin(): bool
    {
        return $this->parent_id === null;
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }
}
