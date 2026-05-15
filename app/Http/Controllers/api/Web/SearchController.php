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
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store ID not found in header.'
            ], 400);
        }

        $keyword          = trim($request->get('q') ?? $request->get('search') ?? $request->get('keyword', ''));

        $brandIds         = $request->get('brands') ? (array)$request->get('brands') : [];

        $categoryIds = [];
        if ($request->has('categories')) {
            $catInput = $request->get('categories');
            if (is_string($catInput)) {
                $categoryIds = array_map('trim', explode(',', $catInput));
            } else {
                $categoryIds = (array) $catInput;
            }
        }
        $categoryIds = array_filter(array_map('intval', $categoryIds));

        $searchSubcats    = $request->boolean('search_subcategories', true);
        $featured         = $request->get('featured');
        $freeShipping     = $request->get('free_shipping');
        $priceFrom        = $request->get('price_from');
        $priceTo          = $request->get('price_to');
        $sortBy           = strtoupper($request->get('sortBy', 'RELEVANCE'));

        $perPage = (int)$request->get('perPage', 20);
        $page    = (int)$request->get('page', 1);

        if (empty($keyword) && empty($brandIds) && empty($categoryIds)) {
            return response()->json([
                'status' => true,
                'data' => [
                    'products'   => ['count' => 0, 'items' => [], 'pagination' => null],
                    'categories' => ['count' => 0, 'items' => []],
                    'brands'     => ['count' => 0, 'items' => []],
                ]
            ]);
        }

        // ====================== PRODUCTS ======================
        $productsQuery = Product::with([
            'brand:id,name',
            'categories:id,name',           // Important
            'images:id,product_id,path,is_primary',
            'seo'
        ])
            ->where('store_id', $storeId)
            ->where('visible_on_storefront', 1);

        if (!empty($keyword)) {
            $productsQuery->where(function ($q) use ($keyword) {
                $q->where('name', 'LIKE', "%{$keyword}%")
                    ->orWhere('sku', 'LIKE', "%{$keyword}%")
                    ->orWhere('search_keywords', 'LIKE', "%{$keyword}%")
                    ->orWhereHas('brand', fn($b) => $b->where('name', 'LIKE', "%{$keyword}%"));
            });
        }

        if (count($brandIds) > 0) $productsQuery->whereIn('brand_id', $brandIds);
        if (count($categoryIds) > 0) {
            if ($searchSubcats) {
                $allCatIds = $this->getAllCategoryIds($categoryIds);
                $productsQuery->whereHas('categories', fn($q) => $q->whereIn('category_id', $allCatIds));
            } else {
                $productsQuery->whereHas('categories', fn($q) => $q->whereIn('category_id', $categoryIds));
            }
        }

        if ($featured === 'only_featured') $productsQuery->where('is_featured', 1);
        if ($featured === 'only_non_featured') $productsQuery->where('is_featured', 0);
        if ($freeShipping === 'only_free') $productsQuery->where('free_shipping', 1);
        if ($freeShipping === 'only_paid') $productsQuery->where('free_shipping', 0);

        if ($priceFrom) $productsQuery->where('price', '>=', (float)$priceFrom);
        if ($priceTo) $productsQuery->where('price', '<=', (float)$priceTo);

        // Sorting
        switch ($sortBy) {
            case 'FEATURED':
                $productsQuery->where('is_featured', 1)->orderBy('id', 'desc');
                break;
            case 'NEWEST':
            case 'NEWEST ITEMS':
                $productsQuery->orderBy('created_at', 'desc');
                break;
            case 'BEST SELLING':
                $productsQuery->orderBy('sold_count', 'desc')->orderBy('id', 'desc');
                break;
            case 'A TO Z':
                $productsQuery->orderBy('name', 'asc');
                break;
            case 'Z TO A':
                $productsQuery->orderBy('name', 'desc');
                break;
            case 'PRICE ASC':
            case 'PRICE ASCENDING':
                $productsQuery->orderBy('price', 'asc');
                break;
            case 'PRICE DESC':
            case 'PRICE DESCENDING':
                $productsQuery->orderBy('price', 'desc');
                break;
            default:
                if (!empty($keyword)) {
                    $productsQuery->orderByRaw("CASE WHEN name LIKE ? THEN 1 WHEN name LIKE ? THEN 2 ELSE 3 END", ["{$keyword}%", "%{$keyword}%"]);
                }
                $productsQuery->orderBy('id', 'desc');
                break;
        }

        $totalProducts = $productsQuery->count();
        $products = $productsQuery->paginate($perPage, ['*'], 'page', $page);

        // ====================== RELATED CATEGORIES & BRANDS (From Products) ======================
        $this->visited = [];

        // Products se related unique category aur brand IDs
        $productBrandIds = $products->pluck('brand_id')->unique()->values();
        $productCategoryIds = $products->pluck('categories.*.id')->flatten()->unique()->values();

        // ====================== CATEGORIES ======================
        $categoriesQuery = Category::with(['childrenRecursive'])
            ->withCount('products')
            ->where('store_id', $storeId)
            ->where('is_visible', true)
            ->whereIn('id', $productCategoryIds);   // ← Important Change

        $categories = $categoriesQuery->get();
        $formattedCategories = $categories->map(fn($cat) => $this->formatCategorySafe($cat))
            ->filter()->values();

        // ====================== BRANDS ======================
        $brands = Brand::withCount('products')
            ->where('store_id', $storeId)
            ->whereIn('id', $productBrandIds)
            ->get()
            ->unique('name')
            ->values();

        return response()->json([
            'status' => true,
            'data' => [
                'products' => [
                    'count'       => $totalProducts,
                    'items'       => ProductListResource::collection($products),
                    'pagination'  => [
                        'total'       => $totalProducts,
                        'perPage'     => $perPage,
                        'currentPage' => $products->currentPage(),
                        'lastPage'    => $products->lastPage(),
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

    // ================== Helper Functions ==================
    private function getAllCategoryIds(array $categoryIds): array
    {
        if (empty($categoryIds)) return [];
        $allIds = $categoryIds;

        $children = Category::whereIn('parent_id', $categoryIds)
            ->pluck('id')
            ->toArray();

        return array_unique(array_merge($allIds, $children));
    }

    private function formatCategorySafe($category)
    {
        if (isset($this->visited[$category->id])) return null;
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
            'direct_products' => $category->products_count ?? 0,
            'subcategories' => $category->childrenRecursive
                ->map(fn($child) => $this->formatCategorySafe($child))
                ->filter()
                ->values(),
        ];
    }
}
