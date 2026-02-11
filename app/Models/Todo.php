<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Todo extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'project_id',
        'assignee_id',
        'title',
        'description',
        'priority',
        'status',
        'due_date',
        'file_path',
        'file_name',
        'estimated_minutes',
    ];

    protected $appends = ['completed'];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * Get the completed attribute.
     */
    public function getCompletedAttribute(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if todo is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
