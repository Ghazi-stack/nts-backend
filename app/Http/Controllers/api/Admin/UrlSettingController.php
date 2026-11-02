<?php

namespace App\Http\Controllers\api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\BulkCreateProductRedirects;
use Illuminate\Http\Request;
use App\Models\UrlSetting;
use App\Models\Redirect;
use App\Models\ProductSeo;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UrlSettingController extends Controller
{
    private function getStoreId()
    {
        $storeId = current_store_id();   // aapka existing helper function

        if (!$storeId) {
            abort(400, 'Store not selected or token invalid.');
        }

        return $storeId;
    }

    /**
     * ===================== PRODUCT URL SETTINGS =====================
     * BigCommerce ke exact 4 options
     */
    public function updateProduct(Request $request)
    {
        $storeId = $this->getStoreId();

        $validated = $request->validate([
            'format_type' => ['required', Rule::in([
                'seo_optimized_short',
                'seo_optimized_long',
                'seo_optimized_category',
                'custom'
            ])],
            'custom_format' => 'required_if:format_type,custom|nullable|string|max:500',
        ]);

        $setting = UrlSetting::updateOrCreate(
            ['store_id' => $storeId, 'entity_type' => 'product'],
            [
                'format_type'   => $validated['format_type'],
                'custom_format' => $validated['format_type'] === 'custom'
                    ? $validated['custom_format']
                    : null,
            ]
        );

        return response()->json([
            'status'  => true,
            'message' => 'Product URL settings updated successfully (BigCommerce style)',
            'data'    => $setting
        ]);
    }

    /**
     * ===================== CATEGORY URL SETTINGS =====================
     * BigCommerce ke exact 3 options
     */
    public function updateCategory(Request $request)
    {
        $storeId = $this->getStoreId();

        $validated = $request->validate([
            'format_type' => ['required', Rule::in([
                'seo_optimized_short',
                'seo_optimized_long',
                'custom'
            ])],
            'custom_format' => 'required_if:format_type,custom|nullable|string|max:500',
        ]);

        $setting = UrlSetting::updateOrCreate(
            ['store_id' => $storeId, 'entity_type' => 'category'],
            [
                'format_type'   => $validated['format_type'],
                'custom_format' => $validated['format_type'] === 'custom'
                    ? $validated['custom_format']
                    : null,
            ]
        );

        return response()->json([
            'status'  => true,
            'message' => 'Category URL settings updated successfully (BigCommerce style)',
            'data'    => $setting
        ]);
    }

    /**
     * ===================== WEB PAGE URL SETTINGS =====================
     */
    public function updateWebpage(Request $request)
    {
        $storeId = $this->getStoreId();

        $validated = $request->validate([
            'format_type' => ['required', Rule::in([
                'seo_optimized_short',
                'seo_optimized_long',
                'custom'
            ])],
            'custom_format' => 'required_if:format_type,custom|nullable|string|max:500',
        ]);

        $setting = UrlSetting::updateOrCreate(
            ['store_id' => $storeId, 'entity_type' => 'webpage'],
            [
                'format_type'   => $validated['format_type'],
                'custom_format' => $validated['format_type'] === 'custom'
                    ? $validated['custom_format']
                    : null,
            ]
        );

        return response()->json([
            'status'  => true,
            'message' => 'Web Page URL settings updated successfully (BigCommerce style)',
            'data'    => $setting
        ]);
    }

    /**
     * Optional: Current settings dekhne ke liye (frontend ke liye bohot useful)
     */
    public function show(string $entity)
    {
        $storeId = $this->getStoreId();

        if (!in_array($entity, ['product', 'category', 'webpage'])) {
            return response()->json(['status' => false, 'message' => 'Invalid entity'], 400);
        }

        $setting = UrlSetting::where('store_id', $storeId)
            ->where('entity_type', $entity)
            ->first();

        return response()->json([
            'status' => true,
            'data'   => $setting ?? [
                'format_type'   => 'seo_optimized_short',
                'custom_format' => null
            ]
        ]);
    }

    /**
     * Bulk Update Product URLs + Create Redirects
     * Yeh method sirf "Update Product URLs..." button se call hoga
     * product_seo.product_url ko kabhi update nahi karega
     */
    public function bulkUpdateProductUrls(Request $request)
    {
        $storeId = $this->getStoreId();

        $validated = $request->validate([
            'updateAll'       => 'required|boolean',
            'createRedirects' => 'required|boolean',
        ]);

        if (!$validated['createRedirects']) {
            return response()->json(['status' => true, 'message' => 'Redirects creation skipped.']);
        }

        $setting = UrlSetting::where('store_id', $storeId)->where('entity_type', 'product')->first();

        if (!$setting) {
            return response()->json(['status' => false, 'message' => 'Product URL setting not found.'], 400);
        }

        $total = Product::where('store_id', $storeId)->count();

        if ($total === 0) {
            return response()->json(['status' => true, 'message' => 'No products found.', 'created' => 0]);
        }

        $progressKey = 'url_redirect_' . Str::uuid();

        // Tenant database name le lo
        $tenantDatabase = config('database.connections.tenant.database');

        Cache::put($progressKey, [
            'current' => 0,
            'total' => $total,
            'created' => 0,
            'status' => 'queued',
        ], 7200);

        // Temporary: Synchronous test
        $job = new BulkCreateProductRedirects(
            $storeId,
            $progressKey,
            $setting->toArray(),
            config('database.connections.tenant.database')
        );
        $job->handle();

        return response()->json([
            'status' => true,
            'message' => 'Redirect creation started.',
            'progress_key' => $progressKey,
            'total' => $total,
        ]);
    }

    public function bulkUpdateProgress($progressKey)
    {
        $progress = Cache::get($progressKey, [
            'current' => 0,
            'total' => 0,
            'created' => 0,
            'status' => 'unknown',
        ]);

        return response()->json($progress);
    }

    /**
     * Product ke liye naya URL generate karo
     * Product model ke fields use karta hai: name, sku, category_id
     */
    private function generateProductUrl($product, $setting): string
    {
        $format = $setting->format_type;
        $custom = $setting->custom_format;

        // Title se slug
        $titleSlug = Str::slug($product->name ?? '');

        // SKU
        $sku = $product->sku ?? '';

        // Category slug
        $categorySlug = 'uncategorized';
        if ($product->category_id) {
            $category = \App\Models\Category::find($product->category_id);
            if ($category) {
                $categorySlug = $category->slug ?? Str::slug($category->name);
            }
        }

        switch ($format) {

            case 'seo_optimized_short':
                return '/' . $titleSlug . '/';

            case 'seo_optimized_long':
                return '/products/' . $titleSlug . '.html';

            case 'seo_optimized_category':
                return '/' . $categorySlug . '/' . $titleSlug . '/';

            case 'custom':
                if (empty($custom)) {
                    return '/' . $titleSlug . '/';
                }

                $url = $custom;

                // Placeholders replace
                $url = str_replace('%title%', $titleSlug, $url);
                $url = str_replace('%sku%', $sku, $url);
                $url = str_replace('%id%', $product->id, $url);
                $url = str_replace('%categoryname%', $categorySlug, $url);
                $url = str_replace('%parent%', $categorySlug, $url);

                if (!str_starts_with($url, '/')) {
                    $url = '/' . $url;
                }

                if (!str_ends_with($url, '/') && !str_contains($url, '.')) {
                    $url .= '/';
                }

                return $url;

            default:
                return '/' . $titleSlug . '/';
        }
    }

    /**
     * Bulk Update Category URLs + Create Redirects
     * category ka existing URL change nahi karega
     */
    public function bulkUpdateCategoryUrls(Request $request)
    {
        $storeId = $this->getStoreId();

        $validated = $request->validate([
            'updateAll'       => 'required|boolean',
            'createRedirects' => 'required|boolean',
        ]);

        if (!$validated['createRedirects']) {
            return response()->json([
                'status'  => true,
                'message' => 'Redirects creation skipped.'
            ]);
        }

        $setting = UrlSetting::where('store_id', $storeId)
            ->where('entity_type', 'category')
            ->first();

        if (!$setting) {
            return response()->json([
                'status'  => false,
                'message' => 'Category URL setting not found.'
            ], 400);
        }

        $categories = \App\Models\Category::where('store_id', $storeId)->get();
        $created = 0;

        foreach ($categories as $category) {

            // Old URL (category ka current slug based URL)
            $oldUrl = '/' . ($category->slug ?? Str::slug($category->name)) . '/';

            // New URL generate karo
            $newUrl = $this->generateCategoryUrl($category, $setting);

            if (rtrim($oldUrl, '/') === rtrim($newUrl, '/')) {
                continue;
            }

            Redirect::updateOrCreate(
                [
                    'store_id' => $storeId,
                    'old_url'  => $oldUrl,
                ],
                [
                    'product_id'  => null,
                    'entity_type' => 'category',
                    'new_url'     => $newUrl,
                    'is_active'   => true,
                ]
            );

            $created++;
        }

        return response()->json([
            'status'  => true,
            'message' => "Category redirects created: {$created}",
            'created' => $created,
        ]);
    }

    /**
     * Category URL generate karo
     */
    private function generateCategoryUrl($category, $setting): string
    {
        $format = $setting->format_type;
        $custom = $setting->custom_format;

        $categorySlug = $category->slug ?? Str::slug($category->name ?? '');

        // Parent category slug (agar hai)
        $parentSlug = '';
        if ($category->parent_id) {
            $parent = \App\Models\Category::find($category->parent_id);
            $parentSlug = $parent->slug ?? Str::slug($parent->name ?? '');
        }

        switch ($format) {

            case 'seo_optimized_short':
                return '/' . $categorySlug . '/';

            case 'seo_optimized_long':
                return '/categories/' . $categorySlug . '.html';

            case 'custom':
                if (empty($custom)) {
                    return '/' . $categorySlug . '/';
                }

                $url = $custom;
                $url = str_replace('%categoryname%', $categorySlug, $url);
                $url = str_replace('%parent%', $parentSlug, $url);
                $url = str_replace('%id%', $category->id, $url);

                if (!str_starts_with($url, '/')) {
                    $url = '/' . $url;
                }

                if (!str_ends_with($url, '/') && !str_contains($url, '.')) {
                    $url .= '/';
                }

                return $url;

            default:
                return '/' . $categorySlug . '/';
        }
    }

    /**
     * Bulk Update Web Page URLs + Create Redirects
     */
    public function bulkUpdateWebpageUrls(Request $request)
    {
        $storeId = $this->getStoreId();

        $validated = $request->validate([
            'updateAll'       => 'required|boolean',
            'createRedirects' => 'required|boolean',
        ]);

        if (!$validated['createRedirects']) {
            return response()->json([
                'status'  => true,
                'message' => 'Redirects creation skipped.'
            ]);
        }

        $setting = UrlSetting::where('store_id', $storeId)
            ->where('entity_type', 'webpage')
            ->first();

        if (!$setting) {
            return response()->json([
                'status'  => false,
                'message' => 'Web Page URL setting not found.'
            ], 400);
        }

        $pages = \App\Models\WebPage::where('store_id', $storeId)->get();
        $created = 0;

        foreach ($pages as $page) {

            // Old URL (page_url field se)
            $oldUrl = $page->page_url ?? ('/' . Str::slug($page->page_name) . '/');

            // Ensure leading slash
            if (!str_starts_with($oldUrl, '/')) {
                $oldUrl = '/' . $oldUrl;
            }

            $newUrl = $this->generateWebpageUrl($page, $setting);

            if (rtrim($oldUrl, '/') === rtrim($newUrl, '/')) {
                continue;
            }

            Redirect::updateOrCreate(
                [
                    'store_id' => $storeId,
                    'old_url'  => $oldUrl,
                ],
                [
                    'product_id'  => null,
                    'entity_type' => 'webpage',
                    'new_url'     => $newUrl,
                    'is_active'   => true,
                ]
            );

            $created++;
        }

        return response()->json([
            'status'  => true,
            'message' => "Web Page redirects created: {$created}",
            'created' => $created,
        ]);
    }

    /**
     * Web Page URL generate karo
     */
    private function generateWebpageUrl($page, $setting): string
    {
        $format = $setting->format_type;
        $custom = $setting->custom_format;

        // page_name se slug
        $pageSlug = Str::slug($page->page_name ?? '');

        switch ($format) {

            case 'seo_optimized_short':
                return '/' . $pageSlug . '/';

            case 'seo_optimized_long':
                return '/pages/' . $pageSlug . '.html';

            case 'custom':
                if (empty($custom)) {
                    return '/' . $pageSlug . '/';
                }

                $url = $custom;
                $url = str_replace('%title%', $pageSlug, $url);
                $url = str_replace('%id%', $page->id, $url);

                if (!str_starts_with($url, '/')) {
                    $url = '/' . $url;
                }

                if (!str_ends_with($url, '/') && !str_contains($url, '.')) {
                    $url .= '/';
                }

                return $url;

            default:
                return '/' . $pageSlug . '/';
        }
    }
}
