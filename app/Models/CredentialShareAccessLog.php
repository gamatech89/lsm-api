<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CredentialShareAccessLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'share_link_id',
        'ip_address',
        'user_agent',
        'country',
        'password_correct',
        'accessed_at',
    ];

    protected function casts(): array
    {
        return [
            'accessed_at' => 'datetime',
            'password_correct' => 'boolean',
        ];
    }

    public function shareLink(): BelongsTo
    {
        return $this->belongsTo(CredentialShareLink::class, 'share_link_id');
    }
}
