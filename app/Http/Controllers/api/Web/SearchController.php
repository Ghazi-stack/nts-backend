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

        // ================== Store ID (Optional bana diya) ==================
        $storeId = current_store_id();   // agar null bhi aaye toh proceed karega

        // ====================== PRODUCTS ======================
        $productsQuery = Product::with([
            'brand:id,name',
            'categories:id,name',
            'images:id,product_id,path,is_primary',
            'seo'
        ])
            ->where('visible_on_storefront', 1);

        // Agar store_id mila toh filter karo, warna sab stores se dhoondo
        if ($storeId) {
            $productsQuery->where('store_id', $storeId);
        }

        $products = $productsQuery->where(function ($q) use ($query) {
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
                'visible_on_storefront'
            ])
            ->limit(15)
            ->get();

        // ====================== CATEGORIES ======================
        $this->visited = [];

        $categoriesQuery = Category::with(['childrenRecursive'])
            ->withCount('products')
            ->where('is_visible', true);

        if ($storeId) {
            $categoriesQuery->where('store_id', $storeId);
        }

        $categories = $categoriesQuery->where(function ($q) use ($query) {
            $q->where('name', 'LIKE', "%{$query}%")
                ->orWhere('seo_search_keywords', 'LIKE', "%{$query}%");
        })
            ->whereNull('parent_id')
            ->get();

        $formattedCategories = $categories->map(function ($cat) {
            return $this->formatCategorySafe($cat);
        })->filter()->values();

        // ====================== BRANDS ======================
        $brandsQuery = Brand::withCount('products')
            ->where('name', 'LIKE', "%{$query}%");

        if ($storeId) {
            $brandsQuery->where('store_id', $storeId);
        }

        $brands = $brandsQuery->select([
            'id',
            'name',
            'logo',
            'page_title',
            'meta_keywords',
            'meta_description',
            'search_keywords',
            'brand_url'
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

            'direct_products'  => $category->products_count ?? 0,
            'total_products'   => $category->products_count + ($category->subtree_products_count ?? 0),

            'subcategories' => $category->childrenRecursive
                ->map(fn($child) => $this->formatCategorySafe($child))
                ->filter()
                ->values(),
        ];
    }
}
