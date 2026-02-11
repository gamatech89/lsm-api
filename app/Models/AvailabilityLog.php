<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AvailabilityLog extends Model
{
    protected $fillable = ['user_id', 'status', 'start_date', 'end_date', 'note'];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
