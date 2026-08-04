<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The safe projection of a personal access token.
 *
 * Deliberately hand-listed rather than built from the model's attributes: the
 * row holds a hashed token value, and an accidental ->toArray() would ship it.
 *
 * @mixin \Laravel\Sanctum\PersonalAccessToken
 */
class IntegrationTokenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'scopes' => $this->abilities ?? [],
            'created_at' => $this->created_at,
            'expires_at' => $this->expires_at,
            'last_used_at' => $this->last_used_at,
            'last_used_ip' => $this->last_used_ip,
            'created_from_ip' => $this->created_from_ip,
            'is_expired' => $this->expires_at !== null && $this->expires_at->isPast(),
        ];
    }
}
