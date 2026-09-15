<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlogPostResource extends JsonResource
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
            'storeId' => $this->store_id,
            'title' => $this->title,
            'slug' => $this->slug,
            'body' => $this->body,
            'author' => $this->author,
            'tags' => $this->tags,
            'thumbnailImage' => $this->thumbnail_image,
            'thumbnail' => $this->thumbnail_url,
            'postUrl' => $this->post_url,
            'metaDescription' => $this->meta_description,
            'createdAt' => $this->created_at?->toDateString(),
            'updatedAt' => $this->updated_at?->toDateString(),
            'isDraft' => $this->is_draft,
        ];
    }
}
