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

        if (empty($query) || strlen($query) < 1) {
            return response()->json([
                'status' => true,
                'data' => [
                    'products' => ['count' => 0, 'items' => [], 'pagination' => null],
                    'categories' => ['count' => 0, 'items' => []],
                    'brands' => ['count' => 0, 'items' => []],
                ]
            ]);
        }

        $storeId = current_store_id();
        $perPage = (int) $request->get('perPage', 20);
        $page = (int) $request->get('page', 1);

        // ====================== PRODUCTS (With Pagination) ======================
        $productsQuery = Product::with(['brand:id,name', 'categories:id,name', 'images:id,product_id,path,is_primary', 'seo'])
            ->where('visible_on_storefront', 1)
            ->when($storeId, fn($q) => $q->where('store_id', $storeId))
            ->where(function ($q) use ($query) {
                $q->where('name', 'LIKE', "%{$query}%")
                    ->orWhere('sku', 'LIKE', "%{$query}%")
                    ->orWhere('search_keywords', 'LIKE', "%{$query}%")
                    ->orWhereHas('brand', fn($b) => $b->where('name', 'LIKE', "%{$query}%"));
            });

        $totalProducts = $productsQuery->count();
        $products = $productsQuery->paginate($perPage, ['*'], 'page', $page);

        // ====================== CATEGORIES ======================
        $this->visited = [];

        $categories = Category::with(['childrenRecursive'])
            ->withCount('products')
            ->when($storeId, fn($q) => $q->where('store_id', $storeId))
            ->where('is_visible', true)
            ->where(function ($q) use ($query) {
                $q->where('name', 'LIKE', "%{$query}%")
                    ->orWhere('seo_search_keywords', 'LIKE', "%{$query}%")
                    ->orWhere('description', 'LIKE', "%{$query}%");
            })
            ->get();

        $formattedCategories = $categories->map(fn($cat) => $this->formatCategorySafe($cat))
            ->filter()
            ->values();

        // ====================== BRANDS (Duplicate Fixed) ======================
        $brands = Brand::withCount('products')
            ->when($storeId, fn($q) => $q->where('store_id', $storeId))
            ->where('name', 'LIKE', "%{$query}%")
            ->get()
            ->unique('name')           // Duplicate HP remove
            ->values();

        return response()->json([
            'status' => true,
            'data' => [
                'products' => [
                    'count' => $totalProducts,
                    'items' => ProductListResource::collection($products),
                    'pagination' => [
                        'total'        => $totalProducts,
                        'perPage'      => $perPage,
                        'currentPage'  => $products->currentPage(),
                        'lastPage'     => $products->lastPage(),
                        'totalPages'   => $products->lastPage(),
                    ]
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
        if (isset($this->visited[$category->id])) return null;
        $this->visited[$category->id] = true;

        return [
            'id'                => $category->id,
            'name'              => $category->name,
            'slug'              => $category->slug,
            'description'       => $category->description,
            'is_visible'        => $category->is_visible,
            'parent_id'         => $category->parent_id,

            'seo' => [
                'page_title'       => $category->seo_home_title,
                'meta_keywords'    => $category->seo_meta_keywords,
                'meta_description' => $category->seo_meta_description,
                'search_keywords'  => $category->seo_search_keywords,
            ],

            'direct_products' => $category->products_count ?? 0,
            'subcategories'   => $category->childrenRecursive
                ->map(fn($child) => $this->formatCategorySafe($child))
                ->filter()
                ->values(),
        ];
    }
}
