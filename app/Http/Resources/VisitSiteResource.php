<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Http\Resources\StoreResource;
use Illuminate\Http\Resources\Json\JsonResource;

class VisitSiteResource extends JsonResource
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
            'visit_date' => $this->visit_date,
            'count' => $this->count,
            'store_id' => $this->store_id,
            'created_at' => $this->created_at ? $this->created_at->toDateString() : null,
            'updated_at' => $this->updated_at ? $this->updated_at->toDateString() : null,

            // Optional: Include store info if loaded
            'store' => new StoreResource($this->whenLoaded('store')),
        ];
    }
}
