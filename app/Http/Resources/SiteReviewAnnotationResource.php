<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteReviewAnnotationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'review_id'     => $this->review_id,
            'parent_id'     => $this->parent_id,
            'todo_id'       => $this->todo_id,
            'x_percent'     => $this->x_percent,
            'y_percent'     => $this->y_percent,
            'scroll_y'      => $this->scroll_y,
            'comment'       => $this->comment,
            'author_type'   => $this->author_type,
            'author_name'   => $this->author_name ?? $this->creator?->name,
            'author_email'  => $this->author_email ?? $this->creator?->email,
            'screenshot_url' => $this->screenshot_path
                ? asset('storage/' . $this->screenshot_path)
                : null,
            'resolved'      => $this->isResolved(),
            'resolved_at'   => $this->resolved_at,
            'resolved_by'   => $this->resolver?->name,
            'replies'       => SiteReviewAnnotationResource::collection($this->whenLoaded('replies')),
            'created_at'    => $this->created_at,
        ];
    }
}
