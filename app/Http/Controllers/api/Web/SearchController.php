<?php

namespace App\Http\Controllers\api\Web;

use App\Models\Product;
use App\Models\Category;
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductListResource;
use App\Http\Resources\BrandResource;

class SearchController extends Controller
{
    private $visited = [];

    public function globalSearch(Request $request): JsonResponse
    {
        $query = trim($request->get('q') ?? $request->get('search') ?? '');

        if (empty($query) || strlen($query) < 2) {
            return response()->json([
                'status' => true,
                'data' => [
                    'products'   => ['count' => 0, 'items' => []],
                    'categories' => ['count' => 0, 'items' => []],
                    'brands'     => ['count' => 0, 'items' => []],
                ]
            ]);
        }

        $storeId = current_store_id();

        // ====================== PRODUCTS ======================
        $products = Product::with([
            'brand:id,name',
            'categories:id,name',
            'images:id,product_id,path,is_primary',
            'seo'
        ])
            ->where('store_id', $storeId)
            ->where('visible_on_storefront', 1)
            ->where(function ($q) use ($query) {
                $q->where('name', 'LIKE', "%{$query}%")
                    ->orWhere('sku', 'LIKE', "%{$query}%")
                    ->orWhere('search_keywords', 'LIKE', "%{$query}%")
                    ->orWhereHas('brand', fn($b) => $b->where('name', 'LIKE', "%{$query}%"));
            })
            ->select([
                'id',
                'name',
                'slug',
                'sku',
                'price',
                'msrp',
                'brand_id',
                'current_stock',
                'is_featured',
                'visible_on_storefront',
                'store_id'
            ])
            ->limit(15)                    // Fast response ke liye limit
            ->get();

        // ====================== CATEGORIES ======================
        $this->visited = []; // Reset for safety

        $categories = Category::with(['childrenRecursive'])
            ->withCount('products')
            ->where('store_id', $storeId)
            ->where('is_visible', true)
            ->where(function ($q) use ($query) {
                $q->where('name', 'LIKE', "%{$query}%")
                    ->orWhere('seo_search_keywords', 'LIKE', "%{$query}%");
            })
            ->whereNull('parent_id')           // Top level categories
            ->get();

        $formattedCategories = $categories->map(function ($cat) {
            return $this->formatCategorySafe($cat);
        })->filter()->values();

        // ====================== BRANDS ======================
        $brands = Brand::where('store_id', $storeId)
            ->where('name', 'LIKE', "%{$query}%")
            ->withCount('products')
            ->select([
                'id',
                'name',
                'logo',
                'page_title',
                'meta_keywords',
                'meta_description',
                'search_keywords',
                'brand_url',
                'template_layout'
            ])
            ->limit(10)
            ->get();

        return response()->json([
            'status' => true,
            'data' => [
                'products' => [
                    'count' => $products->count(),
                    'items' => ProductListResource::collection($products)
                ],
                'categories' => [
                    'count' => $formattedCategories->count(),
                    'items' => $formattedCategories
                ],
                'brands' => [
                    'count' => $brands->count(),
                    'items' => BrandResource::collection($brands)
                ]
            ]
        ]);
    }

    /**
     * Same format as your existing category API
     */
    private function formatCategorySafe($category)
    {
        if (isset($this->visited[$category->id])) {
            return null;
        }
        $this->visited[$category->id] = true;

        return [
            'id'              => $category->id,
            'name'            => $category->name,
            'slug'            => $category->slug,
            'description'     => $category->description,
            'is_visible'      => $category->is_visible,
            'parent_id'       => $category->parent_id,

            'seo' => [
                'page_title'       => $category->seo_home_title,
                'meta_keywords'    => $category->seo_meta_keywords,
                'meta_description' => $category->seo_meta_description,
                'search_keywords'  => $category->seo_search_keywords,
            ],

            'direct_products'   => $category->products_count ?? 0,
            'total_products'    => $category->products_count + ($category->subtree_products_count ?? 0),
            'in_subcategories'  => $category->subtree_products_count ?? 0,

            'subcategories' => $category->childrenRecursive
                ->map(fn($child) => $this->formatCategorySafe($child))
                ->filter()
                ->values(),
        ];
    }
}
