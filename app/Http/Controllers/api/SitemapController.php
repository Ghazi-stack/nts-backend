<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SitemapController extends Controller
{
    /**
     * =============================================
     * SITEMAP CONTROLLER — ServerBlink
     * =============================================
     *
     * Endpoints:
     *   GET /api/sitemap/info          → Total pages count for sitemap index
     *   GET /api/sitemap/pages         → Static pages list
     *   GET /api/sitemap/products      → Paginated product slugs
     *   GET /api/sitemap/categories    → All category slugs (with hierarchy)
     *   GET /api/sitemap/brands        → All brand slugs
     *
     * Usage:
     *   Next.js frontend calls these endpoints to dynamically
     *   generate XML sitemaps matching the original serverblink.com format.
     *
     * Caching:
     *   All responses are cached for 1 hour (3600 seconds).
     *   Cache is keyed per store_id.
     */

    // ─── Products per sitemap page (matches original site ~1000/page) ───
    private const PRODUCTS_PER_SITEMAP_PAGE = 1000;

    // ─── Cache TTL in seconds (1 hour) ──────────────────────────────────
    private const CACHE_TTL = 3600;

    /**
     * =============================================
     * 1. SITEMAP INFO
     * =============================================
     * Returns total product count and how many sitemap
     * pages are needed (for the sitemap index file).
     *
     * GET /api/sitemap/info
     *
     * Response:
     * {
     *   "status": true,
     *   "data": {
     *     "totalProducts": 18500,
     *     "perPage": 1000,
     *     "totalProductPages": 19,
     *     "totalCategories": 150,
     *     "totalBrands": 85
     *   }
     * }
     */
    public function info(Request $request)
    {
        try {
            $storeId = $this->getStoreId();
            if (!$storeId) {
                return $this->storeError();
            }

            $cacheKey = "sitemap_info_store_{$storeId}";

            $data = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($storeId) {

                $totalProducts = Product::where('store_id', $storeId)
                    ->where('visible_on_storefront', 1)
                    ->count();

                $totalCategories = Category::where('store_id', $storeId)
                    ->where('is_visible', true)
                    ->count();

                $totalBrands = Brand::where('store_id', $storeId)
                    ->distinct('name')
                    ->count('name');

                return [
                    'totalProducts'     => $totalProducts,
                    'perPage'           => self::PRODUCTS_PER_SITEMAP_PAGE,
                    'totalProductPages' => (int) ceil($totalProducts / self::PRODUCTS_PER_SITEMAP_PAGE),
                    'totalCategories'   => $totalCategories,
                    'totalBrands'       => $totalBrands,
                ];
            });

            return response()->json([
                'status' => true,
                'data'   => $data,
            ]);

        } catch (\Exception $e) {
            Log::error('Sitemap info error: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve sitemap info.',
            ], 500);
        }
    }

    /**
     * =============================================
     * 2. STATIC PAGES
     * =============================================
     * Returns a fixed list of static pages for the
     * pages sitemap. These are hardcoded since they
     * don't change frequently.
     *
     * GET /api/sitemap/pages
     *
     * Response:
     * {
     *   "status": true,
     *   "data": [
     *     { "slug": "", "priority": "1.0", "changefreq": "daily" },
     *     { "slug": "about-us", "priority": "0.5", "changefreq": "monthly" },
     *     ...
     *   ]
     * }
     */
    public function pages(Request $request)
    {
        // ── Static pages matching original serverblink.com sitemap ──
        $pages = [
            ['slug' => '',                      'priority' => '1.0', 'changefreq' => 'daily'],
            ['slug' => 'disclaimer',            'priority' => '0.3', 'changefreq' => 'yearly'],
            ['slug' => 'terms-conditions',      'priority' => '0.3', 'changefreq' => 'yearly'],
            ['slug' => 'privacy-policy',        'priority' => '0.3', 'changefreq' => 'yearly'],
            ['slug' => 'shipping-policy',       'priority' => '0.3', 'changefreq' => 'yearly'],
            ['slug' => 'about-us',              'priority' => '0.5', 'changefreq' => 'monthly'],
            ['slug' => 'blog',                  'priority' => '0.6', 'changefreq' => 'weekly'],
            ['slug' => 'warranty',              'priority' => '0.3', 'changefreq' => 'yearly'],
            ['slug' => 'return-refund-policy',  'priority' => '0.3', 'changefreq' => 'yearly'],
            ['slug' => 'contact',               'priority' => '0.5', 'changefreq' => 'monthly'],
        ];

        return response()->json([
            'status' => true,
            'data'   => $pages,
        ]);
    }

    /**
     * =============================================
     * 3. PRODUCTS (Paginated)
     * =============================================
     * Returns product slugs for a specific sitemap page.
     * Each page contains up to 1000 products.
     *
     * GET /api/sitemap/products?page=1
     * GET /api/sitemap/products?page=2&per_page=500
     *
     * Query Params:
     *   page     → Page number (default: 1)
     *   per_page → Products per page (default: 1000, max: 5000)
     *
     * Response:
     * {
     *   "status": true,
     *   "data": [
     *     { "slug": "dell-160gb-7200rpm-sata-hard-drive", "updated_at": "2026-01-15T..." },
     *     ...
     *   ],
     *   "pagination": {
     *     "currentPage": 1,
     *     "totalPages": 37,
     *     "perPage": 1000,
     *     "total": 36500
     *   }
     * }
     */
    public function products(Request $request)
    {
        try {
            $storeId = $this->getStoreId();
            if (!$storeId) {
                return $this->storeError();
            }

            $page    = max(1, (int) $request->query('page', 1));
            $perPage = min(5000, max(1, (int) $request->query('per_page', self::PRODUCTS_PER_SITEMAP_PAGE)));

            $cacheKey = "sitemap_products_store_{$storeId}_page_{$page}_pp_{$perPage}";

            $result = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($storeId, $page, $perPage) {

                $query = Product::where('store_id', $storeId)
                    ->where('visible_on_storefront', 1)
                    ->select('slug', 'updated_at')
                    ->orderBy('id');

                $paginated = $query->paginate($perPage, ['*'], 'page', $page);

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

            return response()->json([
                'status' => true,
                ...$result,
            ]);

        } catch (\Exception $e) {
            Log::error('Sitemap products error: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve products for sitemap.',
            ], 500);
        }
    }

    /**
     * =============================================
     * 4. CATEGORIES
     * =============================================
     * Returns all visible categories with their full
     * slug path (matching the original URL structure).
     *
     * GET /api/sitemap/categories
     *
     * Response:
     * {
     *   "status": true,
     *   "data": [
     *     { "slug": "accessories/cables/scsi-cables", "updated_at": "..." },
     *     { "slug": "storage-devices/raid-controllers", "updated_at": "..." },
     *     ...
     *   ]
     * }
     */
    public function categories(Request $request)
    {
        try {
            $storeId = $this->getStoreId();
            if (!$storeId) {
                return $this->storeError();
            }

            $cacheKey = "sitemap_categories_store_{$storeId}";

            $data = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($storeId) {

                $categories = Category::where('store_id', $storeId)
                    ->where('is_visible', true)
                    ->select('id', 'slug', 'parent_id', 'updated_at')
                    ->get();

                // ── Build full slug paths (parent/child/grandchild) ──
                $categoryMap = $categories->keyBy('id');

                return $categories->map(function ($cat) use ($categoryMap) {
                    $fullSlug = $this->buildCategoryPath($cat, $categoryMap);

                    return [
                        'slug'       => $fullSlug,
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
                'status'  => false,
                'message' => 'Failed to retrieve categories for sitemap.',
            ], 500);
        }
    }

    /**
     * =============================================
     * 5. BRANDS
     * =============================================
     * Returns all unique brand names as slugs.
     *
     * GET /api/sitemap/brands
     *
     * Response:
     * {
     *   "status": true,
     *   "data": [
     *     { "slug": "dell", "name": "Dell", "updated_at": "..." },
     *     { "slug": "hp", "name": "HP", "updated_at": "..." },
     *     ...
     *   ]
     * }
     */
    public function brands(Request $request)
    {
        try {
            $storeId = $this->getStoreId();
            if (!$storeId) {
                return $this->storeError();
            }

            $cacheKey = "sitemap_brands_store_{$storeId}";

            $data = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($storeId) {

                return Brand::where('store_id', $storeId)
                    ->select('name', 'slug', 'updated_at')
                    ->groupBy('name', 'slug', 'updated_at')
                    ->orderBy('name')
                    ->get()
                    ->unique('name')
                    ->map(function ($brand) {
                        return [
                            'slug'       => $brand->slug ?? \Illuminate\Support\Str::slug($brand->name),
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
                'status'  => false,
                'message' => 'Failed to retrieve brands for sitemap.',
            ], 500);
        }
    }

    /**
     * =============================================
     * 6. CLEAR SITEMAP CACHE
     * =============================================
     * Manually clear all sitemap caches.
     * Useful after bulk product imports or category updates.
     *
     * POST /api/sitemap/clear-cache
     *
     * Response:
     * { "status": true, "message": "Sitemap cache cleared." }
     */
    public function clearCache(Request $request)
    {
        try {
            $storeId = $this->getStoreId();
            if (!$storeId) {
                return $this->storeError();
            }

            // ── Clear all sitemap-related cache keys ──
            Cache::forget("sitemap_info_store_{$storeId}");
            Cache::forget("sitemap_categories_store_{$storeId}");
            Cache::forget("sitemap_brands_store_{$storeId}");

            // ── Clear product page caches (up to 50 pages) ──
            for ($i = 1; $i <= 50; $i++) {
                Cache::forget("sitemap_products_store_{$storeId}_page_{$i}_pp_" . self::PRODUCTS_PER_SITEMAP_PAGE);
            }

            return response()->json([
                'status'  => true,
                'message' => 'Sitemap cache cleared successfully.',
            ]);

        } catch (\Exception $e) {
            Log::error('Sitemap cache clear error: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to clear sitemap cache.',
            ], 500);
        }
    }

    // ═══════════════════════════════════════════════
    // PRIVATE HELPERS
    // ═══════════════════════════════════════════════

    /**
     * Get current store ID from header/helper.
     */
    private function getStoreId(): ?int
    {
        // ── Uses your existing current_store_id() helper ──
        if (function_exists('current_store_id')) {
            return current_store_id();
        }

        // ── Fallback: read from request header ──
        return (int) request()->header('store-id') ?: null;
    }

    /**
     * Standard store error response.
     */
    private function storeError()
    {
        return response()->json([
            'status'  => false,
            'message' => 'Store not selected or token invalid.',
        ], 400);
    }

    /**
     * Build full category path: parent/child/grandchild
     * Example: "accessories/cables/scsi-cables"
     */
    private function buildCategoryPath($category, $categoryMap, $depth = 0): string
    {
        // ── Safety: prevent infinite loops (max 5 levels deep) ──
        if ($depth > 5) {
            return $category->slug;
        }

        // ── If has parent, recursively build path ──
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
