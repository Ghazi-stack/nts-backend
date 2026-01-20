<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

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
            'path'      => $this->path,
            'url'       => $this->url ?? Storage::disk('s3')->url($this->path),
            'isPrimary' => $this->is_primary,
            'store_id'  => $this->store_id,
            'altText'   => $this->alt_text,
            'description' => $this->description,

        ];
    }
}
