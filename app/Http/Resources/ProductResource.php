<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $price = $this->price ?? 0;
        $msrp = $this->msrp ?? 0;
        $cost = $this->cost_price ?? 0;

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
            'price' => $price,
            'msrp' => $msrp,
            'bestSelling' => $this->sold_count,
            'costPrice' => $cost,
            'salePrice' => $this->sale_price,
            'fixedShippingCost' => $this->fixed_shipping_cost,
            'productType' => $this->product_type,
            'optionSet' => $this->option_set,
            'optionSetAlign' => $this->option_set_align,
            'allowPurchase' => $this->allow_purchase,
            'stopProcessingRules' => $this->stop_processing_rules,
            'taxClass' => $this->tax_class,
            'taxProviderCode' => $this->tax_provider_code,
            'brandId' => (int) $this->brand_id,
            'categoryIds' => $this->whenLoaded('categories', fn () => $this->categories->pluck('id')),
            'brand' => new BrandResource($this->whenLoaded('brand')),
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
            'dimensions' => [
                'weight' => $this->dimensions['weight'] ?? null,
                'width' => $this->dimensions['width'] ?? null,
                'height' => $this->dimensions['height'] ?? null,
                'depth' => $this->dimensions['depth'] ?? null,
            ],
            'quantity' => $this->additional['quantity'] ?? $this->quantity,
            'trackInventory' => $this->track_inventory,
            'currentStock' => $this->current_stock,
            'lowStock' => $this->low_stock,
            'isFeatured' => $this->is_featured,
            'isVisible' => $this->visible_on_storefront,
            'showCondition' => $this->show_condition,
            'sortOrder' => $this->sort_order,
            'searchKeywords' => $this->search_keywords,
            'customFields' => collect($this->custom_fields ?? [])->mapWithKeys(function ($value, $key) {
                return [trim($key, "\"") => is_string($value) ? trim($value, "\"") : $value];
            }),
            'storeId' => $this->store_id,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
            'image' => ProductImageResource::collection($this->whenLoaded('images')),
            'description' => optional($this->content)->description,
            'warranty' => optional($this->content)->warranty,
            'availabilityText' => optional($this->content)->availability_text,
            'templateLayout' => optional($this->content)->template_layout,
            'pageTitle' => optional($this->seo)->page_title,
            'productUrl' => optional($this->seo)->product_url,
            'metaDescription' => optional($this->seo)->meta_description,
            'mpn' => optional($this->identifiers)->mpn,
            'upc' => optional($this->identifiers)->upc,
            'gtin' => optional($this->identifiers)->gtin,
            'bpn' => optional($this->identifiers)->bpn,
            'categoryHierarchy' => $this->whenLoaded('categories', function () {
                $primaryCategory = $this->categories->first();
                return $primaryCategory ? $this->buildCategoryHierarchy($primaryCategory) : null;
            }),
            'savingAmount' => $savings,
            'savingPercent' => $savingPercent,
            'profit' => $profit,
            'profitMargin' => $profitMargin,
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
