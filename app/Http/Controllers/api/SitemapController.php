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
use Carbon\Carbon;

class SitemapController extends Controller
{
    private const ITEMS_PER_PAGE = 10000;
    private const CACHE_TTL = 3600;

    private function siteUrl(): string
    {
        $storeId = $this->getStoreId();

        $storeUrls = [
            4  => 'https://new-town-spares.vercel.app',
            10 => 'https://server-blink.vercel.app',
        ];

        return $storeUrls[$storeId] ?? 'https://server-blink.vercel.app';
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

    // ===================== SITEMAP INDEX (Fixed & Optimized) =====================
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
                $now = Carbon::now()->toW3cString();

                // Pages
                $sitemaps .= $this->makeSitemapEntry($siteUrl, 'pages', 1, $now);

                // Brands
                $brandsLastmod = $this->getBrandsLastmod($storeId) ?? $now;
                $sitemaps .= $this->makeSitemapEntry($siteUrl, 'brands', 1, $brandsLastmod);

                // Categories
                $categoriesLastmod = $this->getCategoriesLastmod($storeId) ?? $now;
                $sitemaps .= $this->makeSitemapEntry($siteUrl, 'categories', 1, $categoriesLastmod);

                // Products - Optimized (sirf overall latest updated_at use kar rahe hain sab pages ke liye)
                $overallProductsLastmod = $this->getProductsOverallLastmod($storeId) ?? $now;

                for ($i = 1; $i <= $totalProductPages; $i++) {
                    $sitemaps .= $this->makeSitemapEntry($siteUrl, 'products', $i, $overallProductsLastmod);
                }

                return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                    . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
                    . $sitemaps
                    . '</sitemapindex>';
            });

            return $this->xmlResponse($xml);
        } catch (\Exception $e) {
            Log::error('Sitemap index error: ' . $e->getMessage() . ' | Line: ' . $e->getLine());
            return $this->xmlError($e->getMessage());
        }
    }

    private function makeSitemapEntry(string $siteUrl, string $type, int $page, string $lastmod): string
    {
        return "  <sitemap>\n" .
            "    <loc>{$siteUrl}/xmlsitemap?type={$type}&amp;page={$page}</loc>\n" .
            "    <lastmod>{$lastmod}</lastmod>\n" .
            "  </sitemap>\n";
    }

    // ===================== Optimized Lastmod Helpers =====================
    private function getBrandsLastmod(int $storeId)
    {
        return Brand::where('store_id', $storeId)->max('updated_at')?->toW3cString();
    }

    private function getCategoriesLastmod(int $storeId)
    {
        return Category::where('store_id', $storeId)->max('updated_at')?->toW3cString();
    }

    private function getProductsOverallLastmod(int $storeId)
    {
        return Product::where('store_id', $storeId)
            ->where('visible_on_storefront', 1)
            ->max('updated_at')
            ?->toW3cString();
    }

    private function getPagesLastmod()
    {
        return Carbon::now()->toW3cString();
    }

    // ===================== REST OF YOUR CODE (Same as before) =====================

    public function pagesXml(Request $request)
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

    public function productsXml(Request $request, $page = 1)
    {
        $page = (int) $page;

        try {
            $storeId = $this->getStoreId();
            if (!$storeId) return $this->xmlError('Store not selected.');

            $siteUrl = $this->siteUrl();
            $cacheKey = "xmlsitemap_products_store_{$storeId}_page_{$page}";

            $xml = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($storeId, $page, $siteUrl) {

                $products = Product::where('store_id', $storeId)
                    ->where('visible_on_storefront', 1)
                    ->select('sku', 'name', 'slug', 'created_at', 'updated_at')
                    ->orderBy('id')
                    ->offset(($page - 1) * self::ITEMS_PER_PAGE)
                    ->limit(self::ITEMS_PER_PAGE)
                    ->get();

                $urls = '';
                foreach ($products as $product) {
                    $productUrl = $this->buildProductUrl($product, $storeId, $siteUrl);
                    $lastmod = ($product->updated_at ?? $product->created_at)?->toW3cString();
                    $lastmodTag = $lastmod ? "\n    <lastmod>{$lastmod}</lastmod>" : '';
                    $urls .= "  <url>\n    <loc>{$productUrl}</loc>{$lastmodTag}\n  </url>\n";
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

    // categoriesXml, brandsXml, clearCache, buildProductUrl, xmlResponse, xmlError, 
    // getStoreId, buildCategoryPath  --- Sab same rakhe hain (copy paste kar lo purane code se)

    public function categoriesXml(Request $request, $page = 1)
    { /* ... your original code ... */
    }
    public function brandsXml(Request $request, $page = 1)
    { /* ... your original code ... */
    }
    public function clearCache(Request $request)
    { /* ... your original code ... */
    }

    private function buildProductUrl($product, int $storeId, string $siteUrl): string
    {
        if ($storeId == 10) {
            $slug = $product->slug ?? Str::slug($product->name ?? $product->sku);
            return "{$siteUrl}/{$slug}/";
        } else {
            return "{$siteUrl}/{$product->sku}/";
        }
    }

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
            . '<!-- Error: ' . htmlspecialchars($message) . ' -->' . "\n"
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
