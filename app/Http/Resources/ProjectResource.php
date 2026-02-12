<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Project Resource
 * 
 * Transforms Project model for API responses.
 * Includes conditional loading of relationships and computed counts.
 */
class ProjectResource extends JsonResource
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
            'url' => $this->url,
            'domain' => $this->domain,
            'client_email' => $this->client_email,
            'notes' => $this->notes,
            
            // Status fields
            'health_status' => $this->health_status,
            'security_status' => $this->security_status,
            
            // External identifiers
            'project_external_id' => $this->project_external_id,
            'maintenance_id' => $this->maintenance_id,
            
            // Hosting info
            'hosting_provider' => $this->hosting_provider,
            'hosting_url' => $this->hosting_url,
            'ssh_access' => $this->ssh_access,
            
            // External links
            'drive_link' => $this->drive_link,
            'trello_link' => $this->trello_link,
            
            // Health monitoring data
            'response_time_ms' => $this->response_time_ms,
            'last_health_check_at' => $this->last_health_check_at?->toISOString(),
            'last_health_details' => $this->last_health_details,
            'ssl_status' => $this->ssl_status,
            'ssl_expires_at' => $this->ssl_expires_at?->toISOString(),
            'domain_expires_at' => $this->domain_expires_at?->toISOString(),
            'domain_registrar' => $this->domain_registrar,
            'wp_version' => $this->wp_version,
            'php_version' => $this->php_version,
            'outdated_plugins_count' => $this->outdated_plugins_count,
            
            // RMB Plugin integration
            'health_check_secret' => $this->health_check_secret,
            
            // Relationships (conditionally loaded)
            'manager_id' => $this->manager_id,
            'manager' => new UserResource($this->whenLoaded('manager')),
            'managers' => UserResource::collection($this->whenLoaded('managers')),
            'developer_id' => $this->developer_id,
            'developer' => new UserResource($this->whenLoaded('developer')),
            'developers' => UserResource::collection($this->whenLoaded('developers')),
            
            // Nested resources (conditionally loaded)
            'credentials' => CredentialResource::collection($this->whenLoaded('credentials')),
            'todos' => TodoResource::collection($this->whenLoaded('todos')),
            'resources' => ResourceResource::collection($this->whenLoaded('resources')),
            'library_resources' => LibraryResourceResource::collection($this->whenLoaded('libraryResources')),
            'maintenance_reports' => MaintenanceReportResource::collection($this->whenLoaded('maintenanceReports')),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            
            // Counts (when loaded via withCount)
            'credentials_count' => $this->whenCounted('credentials'),
            'todos_count' => $this->whenCounted('todos'),
            'pending_todos_count' => $this->when(
                isset($this->pending_todos_count),
                fn() => $this->pending_todos_count
            ),
            'resources_count' => $this->whenCounted('resources'),
            'maintenance_reports_count' => $this->whenCounted('maintenanceReports'),
            
            // Computed fields
            'highest_todo_priority' => $this->when(
                isset($this->highest_todo_priority),
                $this->highest_todo_priority
            ),
            
            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
