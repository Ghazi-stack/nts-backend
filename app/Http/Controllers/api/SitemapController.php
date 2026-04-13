<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SitemapController extends Controller
{
    private const PRODUCTS_PER_PAGE = 1000;
    private const CACHE_TTL = 3600;

    // ─────────────────────────────────────────────
    // GET /api/sitemap/info
    // ─────────────────────────────────────────────
    public function info(Request $request)
    {
        try {
            $storeId = $this->getStoreId();
            if (!$storeId) return $this->storeError();

            $cacheKey = "sitemap_info_store_{$storeId}";

            $data = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($storeId) {

                $totalProducts = Product::where('store_id', $storeId)
                    ->where('visible_on_storefront', 1)
                    ->count();

                $totalCategories = Category::where('store_id', $storeId)->count();

                $totalBrands = Brand::where('store_id', $storeId)
                    ->distinct('name')
                    ->count('name');

                return [
                    'totalProducts'      => $totalProducts,
                    'perPage'            => self::PRODUCTS_PER_PAGE,
                    'totalProductPages'  => (int) ceil($totalProducts / self::PRODUCTS_PER_PAGE),
                    'totalCategories'    => $totalCategories,
                    'totalBrands'        => $totalBrands,
                ];
            });

            return response()->json(['status' => true, 'data' => $data]);
        } catch (\Exception $e) {
            Log::error('Sitemap info error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve sitemap info.',
                'debug' => $e->getMessage(),
            ], 500);
        }
    }

    // ─────────────────────────────────────────────
    // GET /api/sitemap/pages
    // ─────────────────────────────────────────────
    public function pages(Request $request)
    {
        return response()->json([
            'status' => true,
            'data'   => [
                ['slug' => '',                      'priority' => '1.0', 'changefreq' => 'daily'],
                ['slug' => 'disclaimer',            'priority' => '0.3', 'changefreq' => 'yearly'],
                ['slug' => 'terms-conditions',      'priority' => '0.3', 'changefreq' => 'yearly'],
                ['slug' => 'privacy-policy',        'priority' => '0.3', 'changefreq' => 'yearly'],
                ['slug' => 'shipping-policy',       'priority' => '0.3', 'changefreq' => 'yearly'],
                ['slug' => 'about-us',              'priority' => '0.5', 'changefreq' => 'monthly'],
                ['slug' => 'blog',                  'priority' => '0.6', 'changefreq' => 'weekly'],
                ['slug' => 'warranty',              'priority' => '0.3', 'changefreq' => 'yearly'],
                ['slug' => 'return-refund-policy',  'priority' => '0.3', 'changefreq' => 'yearly'],
                ['slug' => 'contact-us',            'priority' => '0.5', 'changefreq' => 'monthly'],
            ],
        ]);
    }

    // ─────────────────────────────────────────────
    // GET /api/sitemap/products?page=1&per_page=1000
    // ─────────────────────────────────────────────
    public function products(Request $request)
    {
        try {
            $storeId = $this->getStoreId();
            if (!$storeId) return $this->storeError();

            $page    = max(1, (int) $request->query('page', 1));
            $perPage = min(5000, max(1, (int) $request->query('per_page', self::PRODUCTS_PER_PAGE)));

            $cacheKey = "sitemap_products_store_{$storeId}_page_{$page}_pp_{$perPage}";

            $result = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($storeId, $page, $perPage) {

                $paginated = Product::where('store_id', $storeId)
                    ->where('visible_on_storefront', 1)
                    ->select('slug', 'updated_at')
                    ->orderBy('id')
                    ->paginate($perPage, ['*'], 'page', $page);

                return [
                    'data' => $paginated->items(),
                    'pagination' => [
                        'currentPage' => $paginated->currentPage(),
                        'totalPages'  => $paginated->lastPage(),
                        'perPage'     => $paginated->perPage(),
                        'total'       => $paginated->total(),
                    ],
                ];
            });

            return response()->json(['status' => true, ...$result]);
        } catch (\Exception $e) {
            Log::error('Sitemap products error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve products for sitemap.',
                'debug' => $e->getMessage(),
            ], 500);
        }
    }

    // ─────────────────────────────────────────────
    // GET /api/sitemap/categories
    // ─────────────────────────────────────────────
    public function categories(Request $request)
    {
        try {
            $storeId = $this->getStoreId();
            if (!$storeId) return $this->storeError();

            $cacheKey = "sitemap_categories_store_{$storeId}";

            $data = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($storeId) {

                $categories = Category::where('store_id', $storeId)
                    ->select('id', 'slug', 'parent_id', 'updated_at')
                    ->get();

                $categoryMap = $categories->keyBy('id');

                return $categories->map(function ($cat) use ($categoryMap) {
                    return [
                        'slug'       => $this->buildCategoryPath($cat, $categoryMap),
                        'updated_at' => $cat->updated_at,
                    ];
                })->values()->toArray();
            });

            return response()->json([
                'status' => true,
                'data'   => $data,
                'total'  => count($data),
            ]);
        } catch (\Exception $e) {
            Log::error('Sitemap categories error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve categories for sitemap.',
                'debug' => $e->getMessage(),
            ], 500);
        }
    }

    // ─────────────────────────────────────────────
    // GET /api/sitemap/brands
    // ─────────────────────────────────────────────
    // FIXED: Brand table mein 'slug' column NAHI hai
    // Str::slug(name) se generate karte hain
    // groupBy NAHI use karte (MySQL strict mode issue)
    // ─────────────────────────────────────────────
    public function brands(Request $request)
    {
        try {
            $storeId = $this->getStoreId();
            if (!$storeId) return $this->storeError();

            $cacheKey = "sitemap_brands_store_{$storeId}";

            $data = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($storeId) {

                $brands = Brand::where('store_id', $storeId)
                    ->select('name', 'updated_at')
                    ->orderBy('name')
                    ->get();

                return $brands->unique('name')
                    ->map(function ($brand) {
                        return [
                            'slug'       => Str::slug($brand->name),
                            'name'       => $brand->name,
                            'updated_at' => $brand->updated_at,
                        ];
                    })
                    ->values()
                    ->toArray();
            });

            return response()->json([
                'status' => true,
                'data'   => $data,
                'total'  => count($data),
            ]);
        } catch (\Exception $e) {
            Log::error('Sitemap brands error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve brands for sitemap.',
                'debug' => $e->getMessage(),
            ], 500);
        }
    }

    // ─────────────────────────────────────────────
    // POST /api/sitemap/clear-cache
    // ─────────────────────────────────────────────
    public function clearCache(Request $request)
    {
        try {
            $storeId = $this->getStoreId();
            if (!$storeId) return $this->storeError();

            Cache::forget("sitemap_info_store_{$storeId}");
            Cache::forget("sitemap_categories_store_{$storeId}");
            Cache::forget("sitemap_brands_store_{$storeId}");

            for ($i = 1; $i <= 300; $i++) {
                Cache::forget("sitemap_products_store_{$storeId}_page_{$i}_pp_" . self::PRODUCTS_PER_PAGE);
            }

            return response()->json([
                'status'  => true,
                'message' => 'Sitemap cache cleared successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to clear sitemap cache.',
            ], 500);
        }
    }

    // ═══════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════

    private function getStoreId(): ?int
    {
        if (function_exists('current_store_id')) {
            return current_store_id();
        }
        return (int) request()->header('store-id') ?: null;
    }

    private function storeError()
    {
        return response()->json([
            'status'  => false,
            'message' => 'Store not selected or token invalid.',
        ], 400);
    }

    private function buildCategoryPath($category, $categoryMap, $depth = 0): string
    {
        if ($depth > 5) return $category->slug;

        if ($category->parent_id && isset($categoryMap[$category->parent_id])) {
            $parentPath = $this->buildCategoryPath(
                $categoryMap[$category->parent_id],
                $categoryMap,
                $depth + 1
            );
            return $parentPath . '/' . $category->slug;
        }

        return $category->slug;
    }
}
