<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource Resource (File/Link attachments)
 * 
 * Transforms Resource model for API responses.
 */
class ResourceResource extends JsonResource
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
            'title' => $this->title,
            'type' => $this->type, // 'link' or 'file'
            'url' => $this->url,
            'file_path' => $this->file_path,
            'file_name' => $this->file_name,
            'file_size' => $this->file_size,
            'notes' => $this->notes,
            'is_quick_action' => (bool) $this->is_quick_action,
            
            // Download URL for files
            'download_url' => $this->when(
                $this->type === 'file' && $this->file_path,
                fn() => route('api.v1.resources.download', $this->id)
            ),
            
            // Timestamps
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
