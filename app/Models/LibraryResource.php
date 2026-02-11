<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Library Resource Model
 * 
 * Represents globally shared files that can be linked to multiple projects.
 * Examples: Dev guides, security checklists, onboarding documents.
 */
class LibraryResource extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'category',
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
