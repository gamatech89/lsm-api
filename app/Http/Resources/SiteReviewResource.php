<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'project_id'       => $this->project_id,
            'title'            => $this->title,
            'url'              => $this->url,
            'status'           => $this->status,
            'share_url'        => $this->share_token
                ? config('app.frontend_url') . '/review/share/' . $this->share_token
                : null,
            'share_has_password' => ! empty($this->share_password),
            'share_expires_at' => $this->share_expires_at,
            'share_active'     => $this->isShareValid(),
            'annotation_count' => $this->whenLoaded('pins', fn() => $this->pins->count()),
            'pins'             => SiteReviewAnnotationResource::collection($this->whenLoaded('pins')),
            'creator'          => $this->creator?->name,
            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,
        ];
    }
}
