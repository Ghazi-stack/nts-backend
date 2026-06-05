<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PopularProductResource extends JsonResource
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

            // Only primary image
            'image' => ProductImageResource::collection($this->whenLoaded('images')),
            'purchasabilityStatus' => $this->purchasability_status,
            'minPurchaseQuantity' => $this->min_purchase_quantity,
            'maxPurchaseQuantity' => $this->max_purchase_quantity,
            'pageTitle' => $this->seo->page_title ?? null,
            'productUrl' => $this->seo->product_url ?? null,
            'metaDescription' => $this->seo->meta_description ?? null,
        ];
    }
}
