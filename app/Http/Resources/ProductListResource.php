<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProductListResource extends JsonResource
{
    public function toArray($request)
    {

        $dimensions = [];
        if (is_array($this->dimensions)) {
            $dimensions = $this->dimensions;
        } elseif ($this->dimensions) {
            $decoded = json_decode($this->dimensions, true);
            if (is_array($decoded)) {
                $dimensions = $decoded;
            }
        }

        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'sku'        => $this->sku,
            'price'      => $this->price,
            'quantity'   => $this->quantity,
            'msrp'       => $this->msrp,
            'brand'      => new BrandResource($this->whenLoaded('brand')),

            // Only minimal data from categories
            'categoryIds' => $this->whenLoaded(
                'categories',
                fn() =>
                $this->categories->map(fn($cat) => [
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
            'fixedShippingCost' => $this->fixed_shipping_cost,
            'trackInventory' => $this->track_inventory,
            'lowStock' => $this->low_stock,
            'minPurchaseQuantity' => $this->min_purchase_quantity,
            'maxPurchaseQuantity' => $this->max_purchase_quantity,
            'purchasabilityStatus' => $this->purchasability_status,
            'currentStock' => $this->current_stock,
            'Price' => $this->price,
            'allowPurchase' => $this->allow_purchase,
            'hasDuplicate' => $this->is_duplicate,
            'isRedirect' => $this->is_redirect,
            'parentId' => $this->parent_id,

            'callPricing' => $this->call_pricing,
            'isFeatured'   => $this->is_featured,
            'isVisible'    => $this->visible_on_storefront,
            'pageTitle' => optional($this->seo)->page_title,
            'productUrl' => optional($this->seo)->product_url,
            'metaDescription' => optional($this->seo)->meta_description,
        ];
    }
}
