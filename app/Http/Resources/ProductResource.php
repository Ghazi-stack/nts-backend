<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
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
        return [
              'id'              => $this->id,
            'name'            => $this->name,
            'slug'            => $this->slug,
            'sku'             => $this->sku,
            'productType'     => $this->product_type,
            'price'           => $this->price,
            'cost'            => $this->cost,
            'msrp'            => $this->msrp,
            'salePrice'       => $this->sale_price,
            'brand'           => $this->brand?->name,
           'categories'      => $this->categories->map(function ($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'hierarchy' => $this->buildCategoryHierarchy($category),
                ];
            }),
            'condition'       => $this->condition?->name,
            'isFeatured'      => $this->is_featured,
            'trackInventory'  => $this->track_inventory,
            'currentStock'    => $this->current_stock,
            'lowStock'        => $this->low_stock,
            'sortOrder'       => $this->sort_order,
            'searchKeywords'  => $this->search_keywords,
            'taxClass'        => $this->tax_class,
            'taxProviderTaxCode' => $this->tax_provider_tax_code,
            'dimensions' => [
                'weight' => $this->weight,
                'width'  => $this->width,
                'height' => $this->height,
                'depth'  => $this->depth,
            ],
            'content'         => new ProductContentResource($this->whenLoaded('content')),
            'identifiers'     => new ProductIdentifierResource($this->whenLoaded('identifiers')),
            'images'          => ProductImageResource::collection($this->whenLoaded('images')),
            'attributes'      => ProductAttributeResource::collection($this->whenLoaded('attributes')),
            'createdAt'       => $this->created_at,
            'updatedAt'       => $this->updated_at,
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
