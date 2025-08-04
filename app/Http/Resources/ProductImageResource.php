<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductImageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
       return [
            'path'      => asset($this->path),
            'isPrimary' => $this->is_primary,
            'store_id' => $this->store_id, // Ensure store_id is included.
            'altText'     => $this->alt_text,
            'description' => $this->description,

        ];
    }
}
