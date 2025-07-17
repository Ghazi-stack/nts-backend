<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Http\Resources\CategoryResource;
use Illuminate\Http\Resources\Json\JsonResource;

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

            // ✅ Product counts
            'direct_products' => $directProducts,
            'in_subcategories' => $inSubcategories,
            'total_products' => $directProducts + $inSubcategories,

            // ✅ Load subcategories if available
            'subcategories' => CategoryResource::collection($this->whenLoaded('childrenRecursive')),
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
