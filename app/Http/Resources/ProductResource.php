<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Http\Resources\BrandResource;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductImageResource;
use App\Http\Resources\ProductContentResource;
use App\Http\Resources\ProductAttributeResource;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\ProductIdentifierResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $price = $this->price ?? 0;
        $msrp = $this->msrp ?? 0;
        $cost = $this->cost_price ?? 0;

        // Calculated fields
        $savings = ($msrp > $price) ? $msrp - $price : 0;
        $savingPercent = ($msrp > 0 && $savings > 0)
            ? round(($savings / $msrp) * 100, 2)
            : 0;

        $profit = ($price > $cost) ? $price - $cost : 0;
        $profitMargin = ($cost > 0 && $profit > 0)
            ? round(($profit / $cost) * 100, 2)
            : 0;
       
        return [
           'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'price' => $this->price,
            'costPrice' => $this->cost_price,
            'salePrice' => $this->sale_price,
            'fixedShippingCost' => $this->fixed_shipping_cost,
            'productType' => $this->product_type,
            'optionSet' => $this->option_set,
            'optionSetAlign' => $this->option_set_align,
            'allowPurchase' => $this->allow_purchase,
            'stopProcessingRules' => $this->stop_processing_rules,
            'taxClass' => $this->tax_class,
            'taxProviderCode' => $this->tax_provider_code,
            
            'brand' => new BrandResource($this->whenLoaded('brand')),
            'category' => new CategoryResource($this->whenLoaded('category')),
            'conditionId' => $this->condition_id,
            
            'weight' => $this->dimensions['weight'] ?? null,
            'dimensions' => [
                'width' => $this->dimensions['width'] ?? null,
                'height' => $this->dimensions['height'] ?? null,
                'depth' => $this->dimensions['depth'] ?? null,
            ],
        
            'trackInventory' => $this->track_inventory,
            'currentStock' => $this->current_stock,
            'lowStock' => $this->low_stock,
            'isFeatured' => $this->is_featured,
            'isVisible' => $this->visible_on_storefront,
            'showCondition' => $this->show_condition,
            'sortOrder' => $this->sort_order,
            'searchKeywords' => $this->search_keywords,
            'customFields' => $this->custom_fields,
            'store_id' => $this->store_id,
            'images' => ProductImageResource::collection($this->whenLoaded('images')),
            'content' => new ProductContentResource($this->whenLoaded('content')),
            'identifiers' => new ProductIdentifierResource($this->whenLoaded('identifiers')),
            'seo' => new ProductSeoResource($this->whenLoaded('seo')),
        
            'categoryHierarchy' => $this->category ? $this->buildCategoryHierarchy($this->category) : null,
             // New calculated fields
            'savingAmount' => $savings,
            'savingPercent' => $savingPercent,
            'profit' => $profit,
            'profitMargin' => $profitMargin,
        
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];         
        
            
    }


      private function buildCategoryHierarchy($category)
    {
        $data = [];
        while ($category) {
            $data[] = [
                'id' => $category->id,
                'name' => $category->name
            ];
            $category = $category->parent;
        }
        return array_reverse($data); // top to bottom
    }

}
