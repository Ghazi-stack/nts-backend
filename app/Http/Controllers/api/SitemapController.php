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
    /**
     * =============================================
     * XML SITEMAP CONTROLLER
     * =============================================
     *
     * Bilkul serverblink.com/xmlsitemap.php jaisa kaam karta hai.
     * Browser mein direct XML dikhata hai.
     *
     * URLs:
     *   /xmlsitemap                              → Sitemap Index
     *   /xmlsitemap?type=pages&page=1            → Static pages
     *   /xmlsitemap?type=products&page=1          → Products page 1
     *   /xmlsitemap?type=products&page=2          → Products page 2
     *   /xmlsitemap?type=categories&page=1        → Categories
     *   /xmlsitemap?type=brands&page=1            → Brands
     *
     * Route (routes/web.php):
     *   Route::get('/xmlsitemap', [XmlSitemapController::class, 'index']);
     *
     * =============================================
     */

    private const PRODUCTS_PER_PAGE = 1000;
    private const CACHE_TTL = 3600; // 1 hour

    // ── Site URL — apna frontend URL yahan daalo ──
    private function siteUrl(): string
    {
        return rtrim(config('app.frontend_url', 'https://server-blink.vercel.app'), '/');
    }

    // ─────────────────────────────────────────────
    // MAIN ENTRY POINT
    // ─────────────────────────────────────────────
    // GET /xmlsitemap
    // GET /xmlsitemap?type=products&page=1
    // ─────────────────────────────────────────────
    public function index(Request $request)
    {
        $type = $request->query('type');
        $page = max(1, (int) $request->query('page', 1));

        // Agar type nahi diya → Sitemap Index return karo
        if (!$type) {
            return $this->sitemapIndex($request);
        }

        // Type ke hisaab se XML return karo
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
    // SITEMAP INDEX
    // ─────────────────────────────────────────────
    // /xmlsitemap (no params)
    //
    // Browser mein dikhega:
    // <sitemapindex>
    //   <sitemap><loc>.../xmlsitemap?type=pages&page=1</loc></sitemap>
    //   <sitemap><loc>.../xmlsitemap?type=products&page=1</loc></sitemap>
    //   <sitemap><loc>.../xmlsitemap?type=products&page=2</loc></sitemap>
    //   ...
    // </sitemapindex>
    // ─────────────────────────────────────────────
    private function sitemapIndex(Request $request)
    {
        try {
            $storeId = $this->getStoreId();
            if (!$storeId) {
                return $this->xmlError('Store not selected.');
            }

            $baseUrl = $this->getBaseUrl($request);

            // Total product pages calculate karo
            $cacheKey = "xmlsitemap_index_store_{$storeId}";

            $xml = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($storeId, $baseUrl) {

                $totalProducts = Product::where('store_id', $storeId)
                    ->where('visible_on_storefront', 1)
                    ->count();

                $totalProductPages = (int) ceil($totalProducts / self::PRODUCTS_PER_PAGE);

                // Build sitemap index XML
                $sitemaps = '';

                // Pages
                $sitemaps .= "  <sitemap>\n    <loc>{$baseUrl}/xmlsitemap?type=pages&amp;page=1</loc>\n  </sitemap>\n";

                // Products (paginated)
                for ($i = 1; $i <= $totalProductPages; $i++) {
                    $sitemaps .= "  <sitemap>\n    <loc>{$baseUrl}/xmlsitemap?type=products&amp;page={$i}</loc>\n  </sitemap>\n";
                }

                // Categories
                $sitemaps .= "  <sitemap>\n    <loc>{$baseUrl}/xmlsitemap?type=categories&amp;page=1</loc>\n  </sitemap>\n";

                // Brands
                $sitemaps .= "  <sitemap>\n    <loc>{$baseUrl}/xmlsitemap?type=brands&amp;page=1</loc>\n  </sitemap>\n";

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
    // /xmlsitemap?type=pages&page=1
    //
    // Browser mein dikhega:
    // <urlset>
    //   <url><loc>https://server-blink.vercel.app/</loc></url>
    //   <url><loc>https://server-blink.vercel.app/about-us/</loc></url>
    //   ...
    // </urlset>
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
    // PRODUCTS XML (Paginated)
    // ─────────────────────────────────────────────
    // /xmlsitemap?type=products&page=1
    // /xmlsitemap?type=products&page=2
    //
    // Har page mein 1000 products.
    // Browser mein dikhega:
    // <urlset>
    //   <url><loc>https://server-blink.vercel.app/t712c-dell-160gb-7200rpm-sata-2-5-inchhard-drive/</loc></url>
    //   ...1000 products (URL from product name/title)...
    // </urlset>
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
                    ->select('name', 'slug', 'sku')
                    ->orderBy('id')
                    ->offset(($page - 1) * self::PRODUCTS_PER_PAGE)
                    ->limit(self::PRODUCTS_PER_PAGE)
                    ->get();

                $urls = '';
                foreach ($products as $product) {
                    // URL product name/title se banao — jaise live site pe hai
                    // Example: "T712C | DELL | 160GB 7200RPM SATA 2.5-INCHHARD DRIVE"
                    //       → "t712c-dell-160gb-7200rpm-sata-2-5-inchhard-drive"
                    $productSlug = Str::slug($product->name);

                    // Agar name se slug empty aaye toh database slug use karo
                    if (!$productSlug) {
                        $productSlug = $product->slug ?? Str::slug($product->sku);
                    }

                    $urls .= "  <url>\n    <loc>{$siteUrl}/{$productSlug}/</loc>\n  </url>\n";
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
    // /xmlsitemap?type=categories&page=1
    //
    // Browser mein dikhega:
    // <urlset>
    //   <url><loc>https://server-blink.vercel.app/category/accessories/cables/</loc></url>
    //   ...
    // </urlset>
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
    // /xmlsitemap?type=brands&page=1
    //
    // Browser mein dikhega:
    // <urlset>
    //   <url><loc>https://server-blink.vercel.app/brand/dell/</loc></url>
    //   <url><loc>https://server-blink.vercel.app/brand/hp/</loc></url>
    //   ...
    // </urlset>
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

    // ═══════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════

    /**
     * XML response with correct headers
     */
    private function xmlResponse(string $xml)
    {
        return response($xml, 200, [
            'Content-Type'  => 'text/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * XML error response (empty urlset)
     */
    private function xmlError(string $message = '')
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<!-- Error: ' . htmlspecialchars($message) . ' -->' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
            . '</urlset>';

        return response($xml, 200, [
            'Content-Type' => 'text/xml; charset=UTF-8',
        ]);
    }

    /**
     * Get store ID
     */
    private function getStoreId(): ?int
    {
        if (function_exists('current_store_id')) {
            $id = current_store_id();
            if ($id) return $id;
        }

        // Fallback: header se
        $headerId = (int) request()->header('store-id');
        if ($headerId) return $headerId;

        // Fallback: query param se (for browser access)
        $queryId = (int) request()->query('store_id');
        if ($queryId) return $queryId;

        // Default store ID — APNA DEFAULT STORE ID YAHAN DAALO
        return 10;
    }

    /**
     * Get base URL for sitemap index links
     */
    private function getBaseUrl(Request $request): string
    {
        // Yeh backend URL return karega
        // jaise: https://backend.sparemicro.com
        return rtrim($request->getSchemeAndHttpHost(), '/');
    }

    /**
     * Build full category path: parent/child/grandchild
     */
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
