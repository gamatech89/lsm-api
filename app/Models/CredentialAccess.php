<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CredentialAccess extends Model
{
    protected $fillable = ['credential_id', 'user_id', 'granted_by'];

    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
