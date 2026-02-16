<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * User Resource
 * 
 * Transforms User model for API responses.
 * Used in auth responses and as a nested resource in projects, teams, etc.
 */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'is_admin' => (bool) $this->is_admin,
            'hourly_rate' => $this->hourly_rate,
            'billing_company_name' => $this->billing_company_name,
            'billing_address' => $this->billing_address,
            'billing_tax_id' => $this->billing_tax_id,
            'invoice_prefix' => $this->invoice_prefix,
            'email_verified_at' => $this->email_verified_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'tags' => $this->whenLoaded('tags', fn() => TagResource::collection($this->tags)),
            'managed_projects_count' => $this->when(isset($this->managed_projects_count), $this->managed_projects_count),
            'assigned_projects_count' => $this->when(isset($this->assigned_projects_count), $this->assigned_projects_count),
        ];
    }
}
