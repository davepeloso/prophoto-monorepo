<?php

namespace ProPhoto\Gallery\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ShareResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'gallery_id' => $this->gallery_id,
            'share_token' => $this->share_token,
            'share_url' => route('api.shares.show', ['token' => $this->share_token]),
            'has_password' => !empty($this->password),
            'expires_at' => $this->expires_at?->toISOString(),
            'max_views' => $this->max_views,
            'view_count' => $this->view_count,
            'allow_downloads' => $this->allow_downloads,
            'allow_comments' => $this->allow_comments,
            'settings' => $this->settings,
            'is_valid' => $this->isValid(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),

            // Relationships
            'gallery' => new GalleryResource($this->whenLoaded('gallery')),
            'created_by' => $this->whenLoaded('createdBy', function () {
                return [
                    'id' => $this->createdBy->id,
                    'name' => $this->createdBy->name,
                ];
            }),
        ];
    }
}
