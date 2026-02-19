<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Library Resource Model
 * 
 * Represents globally shared resources that can be linked to multiple projects.
 * Supports two types:
 *   - 'file': Uploaded files (dev guides, security checklists, etc.)
 *   - 'link': External URLs (Loom videos, documentation, etc.)
 */
class LibraryResource extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'type',
        'category',
        'url',
        'file_path',
        'file_name',
        'file_size',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    /**
     * Get the user who created this library resource.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the projects that use this library resource.
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_library_resource')
            ->withTimestamps();
    }

    /**
     * Get the todos that reference this library resource.
     */
    public function todos(): BelongsToMany
    {
        return $this->belongsToMany(Todo::class, 'todo_library_resource')
            ->withTimestamps();
    }

    /**
     * Check if this is a link-type resource.
     */
    public function isLink(): bool
    {
        return $this->type === 'link';
    }

    /**
     * Check if this is a file-type resource.
     */
    public function isFile(): bool
    {
        return $this->type === 'file';
    }

    /**
     * Get formatted file size.
     */
    public function getFormattedFileSizeAttribute(): string
    {
        $bytes = $this->file_size ?? 0;
        
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        
        return $bytes . ' B';
    }
}

