<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TodoAttachment extends Model
{
    protected $fillable = [
        'todo_id',
        'file_path',
        'file_name',
        'file_size',
        'mime_type',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    public function todo(): BelongsTo
    {
        return $this->belongsTo(Todo::class);
    }
}
