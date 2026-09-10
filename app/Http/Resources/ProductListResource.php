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
            'quantity'   => $this->quantity,
            'msrp'       => $this->msrp,
            'brand'      => new BrandResource($this->whenLoaded('brand')),

            // Only minimal data from categories
            'categoryIds' => $this->whenLoaded('categories', fn () =>
                $this->categories->map(fn ($cat) => [
                'id'   => $cat->id,
                'name' => $cat->name
            ])
            ),

            'dimensions' => [
                'weight' => $dimensions['weight'] ?? 0,
                'width'  => $dimensions['width'] ?? 0,
                'height' => $dimensions['height'] ?? 0,
                'depth'  => $dimensions['depth'] ?? 0,
            ],
            // Only primary image
            'image' => ProductImageResource::collection($this->whenLoaded('images')),

            // Stock & visibility    

            'salePrice' => $this->sale_price,
             'costPrice' => $this->cost_price,
            'freeShipping' => $this->free_shipping,
            'trackInventory' => $this->track_inventory,
            'lowStock' => $this->low_stock,
            'currentStock' => $this->current_stock,
            'Price' => $this->price,
            'allowPurchase' => $this->allow_purchase,
        
            'callPricing' => $this->call_pricing,
            'isFeatured'   => $this->is_featured,
            'isVisible'    => $this->visible_on_storefront,
            'pageTitle' => optional($this->seo)->page_title,
            'productUrl' => optional($this->seo)->product_url,
            'metaDescription' => optional($this->seo)->meta_description,
        ];
    }
}