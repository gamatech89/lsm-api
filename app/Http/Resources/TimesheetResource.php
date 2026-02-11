<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TimesheetResource extends JsonResource
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
            'user_id' => $this->user_id,
            'week_number' => $this->week_number,
            'year' => $this->year,
            'week_start' => $this->week_start?->format('Y-m-d'),
            'week_end' => $this->week_end?->format('Y-m-d'),
            'week_label' => $this->week_label,
            'status' => $this->status,
            'total_minutes' => $this->total_minutes,
            'total_billable_minutes' => $this->total_billable_minutes,
            'formatted_total' => $this->formatted_total,
            'submitted_at' => $this->submitted_at?->toISOString(),
            'approved_at' => $this->approved_at?->toISOString(),
            'rejection_reason' => $this->rejection_reason,
            'notes' => $this->notes,
            
            // Relationships
            'user' => $this->whenLoaded('user', fn() => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'hourly_rate' => $this->user->hourly_rate ?? 0,
            ]),
            'approver' => $this->whenLoaded('approver', fn() => $this->approver ? [
                'id' => $this->approver->id,
                'name' => $this->approver->name,
            ] : null),
            'entries' => TimeEntryResource::collection($this->whenLoaded('entries')),
            'entries_count' => $this->whenCounted('entries'),
            
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
