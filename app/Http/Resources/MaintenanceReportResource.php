<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Maintenance Report Resource
 * 
 * Transforms MaintenanceReport model for API responses.
 */
class MaintenanceReportResource extends JsonResource
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
            'project_id' => $this->project_id,
            'user_id' => $this->user_id,
            'report_date' => $this->report_date,
            'type' => $this->type, // 'monthly', 'weekly', 'ad-hoc'
            'summary' => $this->summary,
            
            // Arrays stored as JSON
            'tasks_completed' => $this->tasks_completed ?? [],
            'updates_performed' => $this->updates_performed ?? [],
            'issues_found' => $this->issues_found ?? [],
            'issues_resolved' => $this->issues_resolved ?? [],
            
            'notes' => $this->notes,
            'time_spent_minutes' => $this->time_spent_minutes,
            'time_spent_formatted' => $this->time_spent_formatted,
            
            // Author
            'user' => new UserResource($this->whenLoaded('user')),
            
            // Project reference (conditionally loaded)
            'project' => new ProjectResource($this->whenLoaded('project')),
            
            // PDF download URL
            'pdf_url' => route('api.v1.maintenance-reports.pdf', $this->id),
            
            // Timestamps
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
