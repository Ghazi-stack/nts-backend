<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use App\Models\Brand;
use App\Models\UrlSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SitemapController extends Controller
{
    private const PRODUCTS_PER_PAGE = 1000;
    private const CACHE_TTL = 3600;

    private function siteUrl(): string
    {
        return 'https://server-blink.vercel.app';
    }

    // ─────────────────────────────────────────────
    // MAIN ENTRY POINT
    // GET /api/sitemap/xmlsitemap
    // ─────────────────────────────────────────────
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
                return $this->categoriesXml($request);
            case 'brands':
                return $this->brandsXml($request);
            default:
                return $this->sitemapIndex($request);
        }
    }

    // ─────────────────────────────────────────────
    // SITEMAP INDEX - Ab Clean URLs (xmlsitemap remove kiya)
    // ─────────────────────────────────────────────
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

                $totalProductPages = (int) ceil($totalProducts / self::PRODUCTS_PER_PAGE);

                $sitemaps = '';

                // Clean URLs (xmlsitemap?type=... remove kiya)
                $sitemaps .= "  <sitemap>\n    <loc>{$siteUrl}/sitemap/pages</loc>\n  </sitemap>\n";

                for ($i = 1; $i <= $totalProductPages; $i++) {
                    $sitemaps .= "  <sitemap>\n    <loc>{$siteUrl}/sitemap/products/{$i}</loc>\n  </sitemap>\n";
                }

                $sitemaps .= "  <sitemap>\n    <loc>{$siteUrl}/sitemap/categories</loc>\n  </sitemap>\n";
                $sitemaps .= "  <sitemap>\n    <loc>{$siteUrl}/sitemap/brands</loc>\n  </sitemap>\n";

                return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                    . '<sitemapindex xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/siteindex.xsd">' . "\n"
                    . $sitemaps
                    . '</sitemapindex>';
            });

            return $this->xmlResponse($xml);
        } catch (\Exception $e) {
            Log::error('Sitemap index error: ' . $e->getMessage());
            return $this->xmlError($e->getMessage());
        }
    }

    // ─────────────────────────────────────────────
    // PAGES XML
    // ─────────────────────────────────────────────
    private function pagesXml(Request $request)
    {
        $siteUrl = $this->siteUrl();

        $pages = [
            '',
            'disclaimer',
            'terms-conditions',
            'privacy-policy',
            'shipping-policy',
            'about-us',
            'blog',
            'warranty',
            'return-refund-policy',
            'contact-us',
        ];

        $urls = '';
        foreach ($pages as $page) {
            $loc = $page ? "{$siteUrl}/{$page}/" : "{$siteUrl}/";
            $urls .= "  <url>\n    <loc>{$loc}</loc>\n  </url>\n";
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
            . $urls
            . '</urlset>';

        return $this->xmlResponse($xml);
    }

    // ─────────────────────────────────────────────
    // PRODUCTS XML - Dynamic according to URL Settings
    // ─────────────────────────────────────────────
    private function productsXml(Request $request, int $page)
    {
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
                    ->select('id', 'sku', 'name', 'slug')
                    ->orderBy('id')
                    ->offset(($page - 1) * self::PRODUCTS_PER_PAGE)
                    ->limit(self::PRODUCTS_PER_PAGE)
                    ->get();

                // Get URL Setting
                $urlSetting = UrlSetting::where('store_id', $storeId)
                    ->where('entity_type', 'product')
                    ->first();

                $formatType = $urlSetting?->format_type ?? 'title';
                $customFormat = $urlSetting?->custom_format;

                $urls = '';
                foreach ($products as $product) {
                    $productUrl = $this->buildProductUrl($product, $formatType, $customFormat, $siteUrl);
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

    // ─────────────────────────────────────────────
    // CATEGORIES XML
    // ─────────────────────────────────────────────
    private function categoriesXml(Request $request)
    {
        try {
            $storeId = $this->getStoreId();
            if (!$storeId) {
                return $this->xmlError('Store not selected.');
            }

            $siteUrl = $this->siteUrl();
            $cacheKey = "xmlsitemap_categories_store_{$storeId}";

            $xml = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($storeId, $siteUrl) {

                $categories = Category::where('store_id', $storeId)
                    ->select('id', 'slug', 'parent_id')
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

    // ─────────────────────────────────────────────
    // BRANDS XML
    // ─────────────────────────────────────────────
    private function brandsXml(Request $request)
    {
        try {
            $storeId = $this->getStoreId();
            if (!$storeId) {
                return $this->xmlError('Store not selected.');
            }

            $siteUrl = $this->siteUrl();
            $cacheKey = "xmlsitemap_brands_store_{$storeId}";

            $xml = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($storeId, $siteUrl) {

                $brands = Brand::where('store_id', $storeId)
                    ->select('name')
                    ->orderBy('name')
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

    // ─────────────────────────────────────────────
    // CLEAR CACHE
    // ─────────────────────────────────────────────
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

            return response()->json([
                'status'  => true,
                'message' => 'Sitemap cache cleared successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to clear sitemap cache.',
                'debug'   => $e->getMessage(),
            ], 500);
        }
    }

    // ═══════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════

    private function xmlResponse(string $xml)
    {
        return response($xml, 200, [
            'Content-Type'  => 'text/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    private function xmlError(string $message = '')
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<!-- Error: ' . htmlspecialchars($message) . ' -->' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
            . '</urlset>';

        return response($xml, 200, ['Content-Type' => 'text/xml; charset=UTF-8']);
    }

    private function getStoreId(): ?int
    {
        if (function_exists('current_store_id')) {
            $id = current_store_id();
            if ($id) return $id;
        }

        $headerId = (int) request()->header('store-id');
        if ($headerId) return $headerId;

        $queryId = (int) request()->query('store_id');
        if ($queryId) return $queryId;

        return 10; // default
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

    /**
     * Build Product URL according to URL Settings
     */
    private function buildProductUrl($product, string $formatType, ?string $customFormat, string $siteUrl): string
    {
        $slug = $product->slug ?? Str::slug($product->name ?? $product->sku);

        switch ($formatType) {
            case 'title':
                return "{$siteUrl}/{$slug}/";

            case 'products':
                return "{$siteUrl}/products/{$slug}/";

            case 'category':
                return "{$siteUrl}/category/{$slug}/";   // Agar real category slug chahiye to batao

            case 'custom':
                if ($customFormat) {
                    $url = str_replace(['%productname%', '%sku%'], [$slug, $product->sku], $customFormat);
                    return rtrim($siteUrl, '/') . $url;
                }
                return "{$siteUrl}/{$slug}/";

            default:
                return "{$siteUrl}/{$slug}/";
        }
    }
}
