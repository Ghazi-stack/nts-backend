<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'slug'        => $this->slug,
            'category'    => $this->category->name ?? null,
            'brand'       => $this->brand->name ?? null,
            'price'       => $this->price,
            'stock'       => $this->stock,
            'description' => $this->description,
            'image'       => $this->image ? asset($this->image) : null,
            'category_id' => $this->category_id,
            'brand_id'    => $this->brand_id,
            'created_at'  => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at'  => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
