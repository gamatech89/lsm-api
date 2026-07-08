<?php

namespace Database\Factories;

use App\Models\EphemeralSecret;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class EphemeralSecretFactory extends Factory
{
    protected $model = EphemeralSecret::class;

    public function definition(): array
    {
        return [
            'token' => Str::random(40),
            'created_by' => null,
            'title' => 'Test secret',
            'payload' => ['password' => 'secret-value'],
            'access_password' => null,
            'expires_at' => now()->addHour(),
            'viewed_at' => null,
            'last_viewed_ip' => null,
        ];
    }
}
