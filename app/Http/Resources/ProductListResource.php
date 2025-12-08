<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProductListResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'sku'        => $this->sku,
            'price'      => $this->price,
            'msrp'       => $this->msrp,
            'brand'      => new BrandResource($this->whenLoaded('brand')),

            // Only minimal data from categories
            'categoryIds' => $this->whenLoaded('categories', fn () =>
                $this->categories->map(fn ($cat) => [
                'id'   => $cat->id,
                'name' => $cat->name
            ])
            ),

            // Only primary image
            'image' => ProductImageResource::collection($this->whenLoaded('images')),

            // Stock & visibility
            'currentStock' => $this->current_stock,
            'isFeatured'   => $this->is_featured,
            'isVisible'    => $this->visible_on_storefront,
        ];
    }
}