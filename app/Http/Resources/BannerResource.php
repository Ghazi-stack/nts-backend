<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Http\Resources\StoreResource;
use Illuminate\Http\Resources\Json\JsonResource;

class BannerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'     => $this->id,
            'title'  => $this->title,
            'image'  => $this->image ? url($this->image) : null, // full URL
            'link'   => $this->link,
            'status' => $this->status,
            'store' => new StoreResource($this->whenLoaded('store')),
        ];
    }
}
