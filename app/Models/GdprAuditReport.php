<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GdprAuditReport extends Model
{
    protected $fillable = [
        'project_id',
        'audit_data',
    ];

    protected $casts = [
        'audit_data' => 'array',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
