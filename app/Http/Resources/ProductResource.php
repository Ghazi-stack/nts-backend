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
            'quantity' => $this->quantity,
            'msrp' => $this->msrp,
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

            // IDs (matching payload)
            'brandId' => (int) $this->brand_id,
            'categoryIds' => $this->whenLoaded('categories', fn () => $this->categories->pluck('id')),

            // Relationship data (optional for frontend)
            'brand' => new BrandResource($this->whenLoaded('brand')),
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),

            // Dimensions
            'dimensions' => [
                'weight' => $this->dimensions['weight'] ?? null,
                'width' => $this->dimensions['width'] ?? null,
                'height' => $this->dimensions['height'] ?? null,
                'depth' => $this->dimensions['depth'] ?? null,
            ],

            // Inventory & visibility
            'trackInventory' => $this->track_inventory,
            'currentStock' => $this->current_stock,
            'lowStock' => $this->low_stock,
            'isFeatured' => $this->is_featured,
            'isVisible' => $this->visible_on_storefront,
            'showCondition' => $this->show_condition,
            'sortOrder' => $this->sort_order,
            'searchKeywords' => $this->search_keywords,
            'customFields' => $this->custom_fields,

            // Meta
            'storeId' => $this->store_id,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,

            // Content & Related
            'image' => ProductImageResource::collection($this->whenLoaded('images')),
            'description' => optional($this->content)->description,
            'warranty' => optional($this->content)->warranty,
            'availabilityText' => optional($this->content)->availability_text,
            'templateLayout' => optional($this->content)->template_layout,
            // SEO fields flattened
            'pageTitle' => optional($this->seo)->page_title,
            'productUrl' => optional($this->seo)->product_url,
            'metaDescription' => optional($this->seo)->meta_description,
            'mpn' => optional($this->identifiers)->mpn,
            'upc' => optional($this->identifiers)->upc,
            'gtin' => optional($this->identifiers)->gtin,
            'bpn' => optional($this->identifiers)->bpn,
            

            // Hierarchy
            'categoryHierarchy' => $this->whenLoaded('categories', function () {
                $primaryCategory = $this->categories->first();
                return $primaryCategory ? $this->buildCategoryHierarchy($primaryCategory) : null;
            }),

            // Calculated values
            'savingAmount' => $savings,
            'savingPercent' => $savingPercent,
            'profit' => $profit,
            'profitMargin' => $profitMargin,

            // Purchase limits
            'purchasabilityStatus' => $this->purchasability_status,
            'minPurchaseQuantity' => $this->min_purchase_quantity,
            'maxPurchaseQuantity' => $this->max_purchase_quantity,
            'relatedProductsEnabled' => $this->related_products_enabled,
        ];
    }

    private function buildCategoryHierarchy($category)
    {
        $data = [];
        while ($category) {
            $data[] = [
                'id' => $category->id,
                'name' => $category->name,
            ];
            $category = $category->parent;
        }

        return array_reverse($data);
    }
}
