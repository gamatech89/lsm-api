<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Resource extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'project_id',
        'title',
        'type',
        'url',
        'file_path',
        'file_name',
        'file_size',
        'notes',
        'is_quick_action',
    ];

    /**
     * Get the project that owns the resource.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
