<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Http\Resources\ProductResource;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class CategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $directProducts = $this->products->count();
        $inSubcategories = $this->calculateSubCategoryProducts($this);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'is_visible' => $this->is_visible,
            'parent_id' => $this->parent_id,

              // NEW fields (no imageUrl accessor, as requested)
            'image' => $this->image ? Storage::disk('s3')->url($this->image) : null,
            'seoHomeTitle'       => $this->seo_home_title,
            'seoMetakeywords'    => $this->seo_meta_keywords,
            'seoMetaDescription' => $this->seo_meta_description,
            'seoSearchKeywords'  => $this->seo_search_keywords,
            'templateLayout'     => $this->template_layout,
            'sortOrder'          => $this->sort_order,
            'defaultProductSort' => $this->default_product_sort,

            // ✅ Product counts
            'direct_products' => $directProducts,
            'in_subcategories' => $inSubcategories,
            'total_products' => $directProducts + $inSubcategories,

            // ✅ Load subcategories if available
            'subcategories' => CategoryResource::collection($this->whenLoaded('childrenRecursive')),
            // 'products'      => ProductResource::collection($this->whenLoaded('products')),
        ];
    }

    /**
     * Recursive function to calculate subcategory product counts
     */
    private function calculateSubCategoryProducts($category)
    {
        $count = 0;

        foreach ($category->childrenRecursive as $child) {
            $count += $child->products->count();
            $count += $this->calculateSubCategoryProducts($child);
        }

        return $count;
    }
}
