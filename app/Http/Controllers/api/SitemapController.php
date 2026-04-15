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
    private const ITEMS_PER_PAGE = 20;   // ← Yeh change kiya hai (20 items per page)
    private const CACHE_TTL = 3600;

    private function siteUrl(): string
    {
        return 'https://server-blink.vercel.app';
    }

    // ===================== MAIN ENTRY POINT =====================
    public function index(Request $request)
    {
        $type = $request->query('type');
        $page = max(1, (int) $request->query('page', 1));

        if (!$type) {
            return $this->sitemapIndex($request);
        }

        switch ($type) {
            case 'pages':
                return $this->pagesXml($request);
            case 'products':
                return $this->productsXml($request, $page);
            case 'categories':
                return $this->categoriesXml($request, $page);
            case 'brands':
                return $this->brandsXml($request, $page);
            default:
                return $this->sitemapIndex($request);
        }
    }

    // ===================== SITEMAP INDEX =====================
    private function sitemapIndex(Request $request)
    {
        try {
            $storeId = $this->getStoreId();
            if (!$storeId) {
                return $this->xmlError('Store not selected.');
            }

            $siteUrl = $this->siteUrl();
            $cacheKey = "xmlsitemap_index_store_{$storeId}";

            $xml = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($storeId, $siteUrl) {

                $totalProducts = Product::where('store_id', $storeId)
                    ->where('visible_on_storefront', 1)
                    ->count();

                $totalProductPages = (int) ceil($totalProducts / self::ITEMS_PER_PAGE);

                $sitemaps = '';

                $sitemaps .= "  <sitemap>\n    <loc>{$siteUrl}/sitemap/pages</loc>\n  </sitemap>\n";

                for ($i = 1; $i <= $totalProductPages; $i++) {
                    $sitemaps .= "  <sitemap>\n    <loc>{$siteUrl}/sitemap/products/{$i}</loc>\n  </sitemap>\n";
                }

                $sitemaps .= "  <sitemap>\n    <loc>{$siteUrl}/sitemap/categories/1</loc>\n  </sitemap>\n";
                $sitemaps .= "  <sitemap>\n    <loc>{$siteUrl}/sitemap/brands/1</loc>\n  </sitemap>\n";

                return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                    . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
                    . $sitemaps
                    . '</sitemapindex>';
            });

            return $this->xmlResponse($xml);
        } catch (\Exception $e) {
            Log::error('Sitemap index error: ' . $e->getMessage());
            return $this->xmlError($e->getMessage());
        }
    }

    // ===================== PRODUCTS XML (20 per page) =====================
    public function productsXml(Request $request, $page = 1)
    {
        $page = (int) $page;

        try {
            $storeId = $this->getStoreId();
            if (!$storeId) {
                return $this->xmlError('Store not selected.');
            }

            $siteUrl = $this->siteUrl();
            $cacheKey = "xmlsitemap_products_store_{$storeId}_page_{$page}";

            $xml = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($storeId, $page, $siteUrl) {

                $products = Product::where('store_id', $storeId)
                    ->where('visible_on_storefront', 1)
                    ->select('sku', 'name', 'slug')
                    ->orderBy('id')
                    ->offset(($page - 1) * self::ITEMS_PER_PAGE)
                    ->limit(self::ITEMS_PER_PAGE)
                    ->get();

                $urls = '';
                foreach ($products as $product) {
                    $productUrl = $this->buildProductUrl($product, $storeId, $siteUrl);
                    $urls .= "  <url>\n    <loc>{$productUrl}</loc>\n  </url>\n";
                }

                return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                    . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
                    . $urls
                    . '</urlset>';
            });

            return $this->xmlResponse($xml);
        } catch (\Exception $e) {
            Log::error('Sitemap products error: ' . $e->getMessage());
            return $this->xmlError($e->getMessage());
        }
    }

    // ===================== CATEGORIES XML (20 per page) =====================
    public function categoriesXml(Request $request, $page = 1)
    {
        $page = (int) $page;

        try {
            $storeId = $this->getStoreId();
            if (!$storeId) return $this->xmlError('Store not selected.');

            $siteUrl = $this->siteUrl();
            $cacheKey = "xmlsitemap_categories_store_{$storeId}_page_{$page}";

            $xml = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($storeId, $page, $siteUrl) {

                $categories = Category::where('store_id', $storeId)
                    ->select('id', 'slug', 'parent_id')
                    ->orderBy('id')
                    ->offset(($page - 1) * self::ITEMS_PER_PAGE)
                    ->limit(self::ITEMS_PER_PAGE)
                    ->get();

                $categoryMap = $categories->keyBy('id');
                $urls = '';

                foreach ($categories as $cat) {
                    $fullSlug = $this->buildCategoryPath($cat, $categoryMap);
                    $urls .= "  <url>\n    <loc>{$siteUrl}/category/{$fullSlug}/</loc>\n  </url>\n";
                }

                return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                    . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
                    . $urls
                    . '</urlset>';
            });

            return $this->xmlResponse($xml);
        } catch (\Exception $e) {
            Log::error('Sitemap categories error: ' . $e->getMessage());
            return $this->xmlError($e->getMessage());
        }
    }

    // ===================== BRANDS XML (20 per page) =====================
    public function brandsXml(Request $request, $page = 1)
    {
        $page = (int) $page;

        try {
            $storeId = $this->getStoreId();
            if (!$storeId) return $this->xmlError('Store not selected.');

            $siteUrl = $this->siteUrl();
            $cacheKey = "xmlsitemap_brands_store_{$storeId}_page_{$page}";

            $xml = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($storeId, $page, $siteUrl) {

                $brands = Brand::where('store_id', $storeId)
                    ->select('name')
                    ->orderBy('name')
                    ->offset(($page - 1) * self::ITEMS_PER_PAGE)
                    ->limit(self::ITEMS_PER_PAGE)
                    ->get()
                    ->unique('name');

                $urls = '';
                foreach ($brands as $brand) {
                    $slug = Str::slug($brand->name);
                    if ($slug) {
                        $urls .= "  <url>\n    <loc>{$siteUrl}/brand/{$slug}/</loc>\n  </url>\n";
                    }
                }

                return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                    . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
                    . $urls
                    . '</urlset>';
            });

            return $this->xmlResponse($xml);
        } catch (\Exception $e) {
            Log::error('Sitemap brands error: ' . $e->getMessage());
            return $this->xmlError($e->getMessage());
        }
    }

    // ===================== CLEAR CACHE =====================
    public function clearCache(Request $request)
    {
        try {
            $storeId = $this->getStoreId();

            Cache::forget("xmlsitemap_index_store_{$storeId}");
            Cache::forget("xmlsitemap_categories_store_{$storeId}");
            Cache::forget("xmlsitemap_brands_store_{$storeId}");

            for ($i = 1; $i <= 300; $i++) {
                Cache::forget("xmlsitemap_products_store_{$storeId}_page_{$i}");
            }

            return response()->json(['status' => true, 'message' => 'Sitemap cache cleared successfully.']);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Failed to clear cache.'], 500);
        }
    }

    // ===================== BUILD PRODUCT URL =====================
    private function buildProductUrl($product, int $storeId, string $siteUrl): string
    {
        if ($storeId == 10) {
            $slug = $product->slug ?? Str::slug($product->name ?? $product->sku);
            return "{$siteUrl}/{$slug}/";
        } else {
            return "{$siteUrl}/{$product->sku}/";
        }
    }

    // ===================== HELPERS =====================
    private function xmlResponse(string $xml)
    {
        return response($xml, 200, [
            'Content-Type' => 'text/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    private function xmlError(string $message = '')
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<!-- Error: ' . htmlspecialchars($message) . ' -->\n'
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';

        return response($xml, 200, ['Content-Type' => 'text/xml; charset=UTF-8']);
    }

    private function getStoreId(): int
    {
        if (function_exists('current_store_id')) {
            $id = current_store_id();
            if ($id) return $id;
        }

        return (int) request()->header('store-id')
            ?: (int) request()->query('store_id')
            ?: 10;
    }

    private function buildCategoryPath($category, $categoryMap, $depth = 0): string
    {
        if ($depth > 5) return $category->slug;

        if ($category->parent_id && isset($categoryMap[$category->parent_id])) {
            $parentPath = $this->buildCategoryPath($categoryMap[$category->parent_id], $categoryMap, $depth + 1);
            return $parentPath . '/' . $category->slug;
        }

        return $category->slug;
    }
}
