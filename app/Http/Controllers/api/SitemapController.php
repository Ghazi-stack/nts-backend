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
                return $this->categoriesXml($request);
            case 'brands':
                return $this->brandsXml($request);
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

                $totalProductPages = (int) ceil($totalProducts / self::PRODUCTS_PER_PAGE);

                $sitemaps = '';

                $sitemaps .= "  <sitemap>\n    <loc>{$siteUrl}/sitemap/pages</loc>\n  </sitemap>\n";

                for ($i = 1; $i <= $totalProductPages; $i++) {
                    $sitemaps .= "  <sitemap>\n    <loc>{$siteUrl}/sitemap/products/{$i}</loc>\n  </sitemap>\n";
                }

                $sitemaps .= "  <sitemap>\n    <loc>{$siteUrl}/sitemap/categories</loc>\n  </sitemap>\n";
                $sitemaps .= "  <sitemap>\n    <loc>{$siteUrl}/sitemap/brands</loc>\n  </sitemap>\n";

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

    // ===================== PRODUCTS XML - Store 10 ke liye Slug, Baaki ke liye SKU =====================
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
                    ->select('sku', 'name', 'slug')   // slug aur name dono le rahe hain
                    ->orderBy('id')
                    ->offset(($page - 1) * self::PRODUCTS_PER_PAGE)
                    ->limit(self::PRODUCTS_PER_PAGE)
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

    // ===================== BUILD PRODUCT URL =====================
    private function buildProductUrl($product, int $storeId, string $siteUrl): string
    {
        if ($storeId == 10) {
            // Store ID 10 ke liye → Slug / Title use karo (jaise aap chahte ho)
            $slug = $product->slug ?? Str::slug($product->name ?? $product->sku);
            return "{$siteUrl}/{$slug}/";
        } else {
            // Baaki stores ke liye → SKU use karo (jaise abhi chal raha hai)
            return "{$siteUrl}/{$product->sku}/";
        }
    }

    // ===================== Baaki Functions (Same as before) =====================
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
            'contact-us'
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

    private function categoriesXml(Request $request)
    {
        try {
            $storeId = $this->getStoreId();
            if (!$storeId) return $this->xmlError('Store not selected.');

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

    private function brandsXml(Request $request)
    {
        try {
            $storeId = $this->getStoreId();
            if (!$storeId) return $this->xmlError('Store not selected.');

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

    // ===================== HELPERS =====================
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
