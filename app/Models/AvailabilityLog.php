<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AvailabilityLog extends Model
{
    protected $fillable = ['user_id', 'set_by_user_id', 'status', 'start_date', 'end_date', 'note'];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function setByUser()
    {
        return $this->belongsTo(User::class, 'set_by_user_id');
    }

    /**
     * Scope to get only active (current/future) availability logs.
     */
    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->where('end_date', '>=', now()->toDateString())
              ->orWhereNull('end_date');
        })->where('start_date', '<=', now()->toDateString());
    }

    /**
     * Check if this log is currently active.
     */
    public function getIsActiveAttribute(): bool
    {
        $now = now()->toDateString();
        return $this->start_date <= $now && ($this->end_date === null || $this->end_date >= $now);
    }
}
