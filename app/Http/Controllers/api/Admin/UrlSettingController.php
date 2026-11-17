<?php

namespace App\Http\Controllers\api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\BulkCreateProductRedirects;
use App\Jobs\BulkCreateCategoryRedirects;
use App\Jobs\BulkCreateWebpageRedirects;
use Illuminate\Http\Request;
use App\Models\UrlSetting;
use App\Models\Redirect;
use App\Models\ProductSeo;
use App\Models\Product;
use App\Models\Category;
use App\Models\RedirectActivityLog;
use App\Models\WebPage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class UrlSettingController extends Controller
{
    private function getStoreId()
    {
        $storeId = current_store_id();

        if (!$storeId) {
            abort(400, 'Store not selected or token invalid.');
        }

        return $storeId;
    }

    private function getDomain()
    {
        return 'https://hardware-parts.mybigcommerce.com';
    }

    /**
     * ===================== PRODUCT URL SETTINGS =====================
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
            'message' => 'Product URL settings updated successfully',
            'data'    => $setting
        ]);
    }

    /**
     * ===================== CATEGORY URL SETTINGS =====================
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
            'message' => 'Category URL settings updated successfully',
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
            'message' => 'Web Page URL settings updated successfully',
            'data'    => $setting
        ]);
    }

    /**
     * Current settings dekhne ke liye
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
     * ===================== BULK UPDATE PRODUCT =====================
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

        Cache::put($progressKey, [
            'current' => 0,
            'total'   => $total,
            'created' => 0,
            'status'  => 'queued',
        ], 7200);

        dispatch(new BulkCreateProductRedirects(
            $storeId,
            $progressKey,
            $setting->toArray(),
            config('database.connections.tenant.database'),
            $this->getDomain(),
            'dynamic'
        ))->onQueue('imports');

        return response()->json([
            'status'       => true,
            'message'      => 'Redirect creation started.',
            'progress_key' => $progressKey,
            'total'        => $total,
        ]);
    }

    public function bulkUpdateCategoryUrls(Request $request)
    {
        $storeId = $this->getStoreId();

        $validated = $request->validate([
            'updateAll'       => 'required|boolean',
            'createRedirects' => 'required|boolean',
        ]);

        if (!$validated['createRedirects']) {
            return response()->json(['status' => true, 'message' => 'Redirects creation skipped.']);
        }

        $setting = UrlSetting::where('store_id', $storeId)->where('entity_type', 'category')->first();
        if (!$setting) {
            return response()->json(['status' => false, 'message' => 'Category URL setting not found.'], 400);
        }

        $total = Category::where('store_id', $storeId)->count();
        if ($total === 0) {
            return response()->json(['status' => true, 'message' => 'No categories found.', 'created' => 0]);
        }

        $progressKey = 'url_redirect_cat_' . Str::uuid();

        Cache::put($progressKey, [
            'current' => 0,
            'total'   => $total,
            'created' => 0,
            'status'  => 'queued',
        ], 7200);

        dispatch(new BulkCreateCategoryRedirects(
            $storeId,
            $progressKey,
            $setting->toArray(),
            config('database.connections.tenant.database'),
            $this->getDomain(),
            'dynamic'
        ))->onQueue('imports');

        return response()->json([
            'status'       => true,
            'message'      => 'Category redirect creation started.',
            'progress_key' => $progressKey,
            'total'        => $total,
        ]);
    }

    public function bulkUpdateWebpageUrls(Request $request)
    {
        $storeId = $this->getStoreId();

        $validated = $request->validate([
            'updateAll'       => 'required|boolean',
            'createRedirects' => 'required|boolean',
        ]);

        if (!$validated['createRedirects']) {
            return response()->json(['status' => true, 'message' => 'Redirects creation skipped.']);
        }

        $setting = UrlSetting::where('store_id', $storeId)->where('entity_type', 'webpage')->first();
        if (!$setting) {
            return response()->json(['status' => false, 'message' => 'Web Page URL setting not found.'], 400);
        }

        $total = WebPage::where('store_id', $storeId)->count();
        if ($total === 0) {
            return response()->json(['status' => true, 'message' => 'No web pages found.', 'created' => 0]);
        }

        $progressKey = 'url_redirect_page_' . Str::uuid();

        Cache::put($progressKey, [
            'current' => 0,
            'total'   => $total,
            'created' => 0,
            'status'  => 'queued',
        ], 7200);

        dispatch(new BulkCreateWebpageRedirects(
            $storeId,
            $progressKey,
            $setting->toArray(),
            config('database.connections.tenant.database'),
            $this->getDomain(),
            'dynamic'
        ))->onQueue('imports');

        return response()->json([
            'status'       => true,
            'message'      => 'Web Page redirect creation started.',
            'progress_key' => $progressKey,
            'total'        => $total,
        ]);
    }

    public function bulkUpdateProgress($progressKey)
    {
        // SSE headers
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Nginx ke liye

        // Output buffering band karo
        if (ob_get_level()) ob_end_clean();

        $maxAttempts = 600; // 10 minutes max (1 sec interval)
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $progress = Cache::get($progressKey, [
                'current' => 0,
                'total'   => 0,
                'created' => 0,
                'status'  => 'unknown',
            ]);

            // SSE format mein bhejo
            echo "data: " . json_encode($progress) . "\n\n";
            ob_flush();
            flush();

            // Agar completed ya failed ho to band kar do
            if (in_array($progress['status'] ?? '', ['completed', 'failed', 'unknown'])) {
                break;
            }

            $attempt++;
            sleep(1); // 1 second wait
        }

        // Connection close
        echo "data: " . json_encode(['status' => 'timeout']) . "\n\n";
        ob_flush();
        flush();
        exit;
    }

    // ===================== REDIRECTS CRUD =====================

    public function redirectsSettingGetData(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.',
                ], 400);
            }

            $perPage = $request->query('perPage', 50);
            $search  = trim((string) $request->query('search', ''));

            $query = Redirect::where('store_id', $storeId);

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('old_url', 'like', "%{$search}%")
                        ->orWhere('new_url', 'like', "%{$search}%")
                        ->orWhere('entity_type', 'like', "%{$search}%")
                        ->orWhere('redirect_type', 'like', "%{$search}%");
                });
            }

            $total = $query->count();
            $redirects = $query->latest()->paginate($perPage);

            $data = $redirects->getCollection()->map(function ($redirect) {
                return [
                    'id'           => $redirect->id,
                    'storeId'      => $redirect->store_id,
                    'productId'    => $redirect->product_id,
                    'entityId'     => $redirect->entity_id,
                    'entityType'   => $redirect->entity_type,
                    'redirectType' => $redirect->redirect_type ?? 'dynamic',
                    'domain'       => $redirect->domain ?? $this->getDomain(),
                    'oldUrl'       => $redirect->old_url,
                    'newUrl'       => $redirect->new_url,
                    'isActive'     => (bool) $redirect->is_active,
                    'createdAt'    => $redirect->created_at,
                    'updatedAt'    => $redirect->updated_at,
                ];
            });

            return response()->json([
                'status'      => true,
                'message'     => 'Redirects retrieved successfully',
                'total'       => $total,
                'count'       => $redirects->count(),
                'perPage'     => $redirects->perPage(),
                'currentPage' => $redirects->currentPage(),
                'totalPages'  => $redirects->lastPage(),
                'data'        => $data,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching redirects: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve redirects',
            ], 500);
        }
    }

    public function storeRedirect(Request $request)
    {
        try {
            $storeId = $this->getStoreId();

            $validated = $request->validate([
                'old_url'       => 'required|string|max:255',
                'redirect_type' => 'required|in:manual,dynamic',
                'new_url'       => 'required_if:redirect_type,manual|nullable|string|max:500',
                'entity_type'   => 'required_if:redirect_type,dynamic|nullable|in:product,brand,category,page,blog_post,webpage',
                'entity_id'     => 'required_if:redirect_type,dynamic|nullable|integer',
                'is_active'     => 'sometimes|boolean',
            ]);

            if (Redirect::where('store_id', $storeId)->where('old_url', $validated['old_url'])->exists()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Old URL already exists for this store.',
                ], 422);
            }

            $newUrl = $validated['new_url'] ?? null;

            if ($validated['redirect_type'] === 'dynamic') {
                $newUrl = $this->generateDynamicUrl($validated['entity_type'], $validated['entity_id'], $storeId);
                if (!$newUrl) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Selected item not found or URL could not be generated.',
                    ], 404);
                }
            }

            $redirect = Redirect::create([
                'store_id'      => $storeId,
                'product_id'    => ($validated['entity_type'] ?? null) === 'product' ? ($validated['entity_id'] ?? null) : null,
                'entity_id'     => $validated['entity_id'] ?? null,
                'entity_type'   => $validated['entity_type'] ?? ($validated['redirect_type'] === 'manual' ? 'manual' : 'unknown'),
                'redirect_type' => $validated['redirect_type'],
                'domain'        => $this->getDomain(),
                'old_url'       => $validated['old_url'],
                'new_url'       => $newUrl,
                'is_active'     => $validated['is_active'] ?? true,
            ]);

            return response()->json([
                'status'  => true,
                'message' => 'Redirect created successfully',
                'data'    => $redirect,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Create Redirect Error: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to create redirect',
            ], 500);
        }
    }

    public function showRedirect($id)
    {
        try {
            $storeId = $this->getStoreId();
            $redirect = Redirect::where('store_id', $storeId)->find($id);

            if (!$redirect) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Redirect not found',
                ], 404);
            }

            return response()->json([
                'status' => true,
                'data'   => [
                    'id'           => $redirect->id,
                    'storeId'      => $redirect->store_id,
                    'productId'    => $redirect->product_id,
                    'entityId'     => $redirect->entity_id,
                    'entityType'   => $redirect->entity_type,
                    'redirectType' => $redirect->redirect_type,
                    'domain'       => $redirect->domain,
                    'oldUrl'       => $redirect->old_url,
                    'newUrl'       => $redirect->new_url,
                    'isActive'     => (bool) $redirect->is_active,
                    'createdAt'    => $redirect->created_at,
                    'updatedAt'    => $redirect->updated_at,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to fetch redirect',
            ], 500);
        }
    }

    public function updateRedirect(Request $request, $id)
    {
        try {
            $storeId = $this->getStoreId();
            $redirect = Redirect::where('store_id', $storeId)->find($id);

            if (!$redirect) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Redirect not found',
                ], 404);
            }

            $validated = $request->validate([
                'old_url'       => 'sometimes|required|string|max:255',
                'redirect_type' => 'sometimes|required|in:manual,dynamic',
                'new_url'       => 'nullable|string|max:500',
                'entity_type'   => 'nullable|in:product,brand,category,page,blog_post,webpage',
                'entity_id'     => 'nullable|integer',
                'is_active'     => 'sometimes|boolean',
            ]);

            if (isset($validated['old_url']) && $validated['old_url'] !== $redirect->old_url) {
                if (Redirect::where('store_id', $storeId)->where('old_url', $validated['old_url'])->where('id', '!=', $id)->exists()) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Old URL already exists for this store.',
                    ], 422);
                }
            }

            $redirectType = $validated['redirect_type'] ?? $redirect->redirect_type;
            $newUrl = $validated['new_url'] ?? $redirect->new_url;

            if ($redirectType === 'dynamic' && isset($validated['entity_type'], $validated['entity_id'])) {
                $generated = $this->generateDynamicUrl($validated['entity_type'], $validated['entity_id'], $storeId);
                if ($generated) {
                    $newUrl = $generated;
                }
            }

            $redirect->update([
                'old_url'       => $validated['old_url'] ?? $redirect->old_url,
                'redirect_type' => $redirectType,
                'entity_type'   => $validated['entity_type'] ?? $redirect->entity_type,
                'entity_id'     => $validated['entity_id'] ?? $redirect->entity_id,
                'product_id'    => (($validated['entity_type'] ?? $redirect->entity_type) === 'product')
                    ? ($validated['entity_id'] ?? $redirect->entity_id)
                    : $redirect->product_id,
                'domain'        => $this->getDomain(),
                'new_url'       => $newUrl,
                'is_active'     => $validated['is_active'] ?? $redirect->is_active,
            ]);

            return response()->json([
                'status'  => true,
                'message' => 'Redirect updated successfully',
                'data'    => $redirect->fresh(),
            ]);
        } catch (\Exception $e) {
            Log::error('Update Redirect Error: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to update redirect',
            ], 500);
        }
    }

    public function destroyRedirect($id)
    {
        try {
            $storeId = $this->getStoreId();
            $redirect = Redirect::where('store_id', $storeId)->find($id);

            if (!$redirect) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Redirect not found',
                ], 404);
            }

            $redirect->delete();

            return response()->json([
                'status'  => true,
                'message' => 'Redirect deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to delete redirect',
            ], 500);
        }
    }

    private function generateDynamicUrl(string $entityType, int $entityId, int $storeId): ?string
    {
        switch ($entityType) {
            case 'product':
                $product = Product::where('id', $entityId)
                    ->where('store_id', $storeId)
                    ->first();

                if (!$product) return null;

                // UrlSetting se format lo
                $setting = UrlSetting::where('store_id', $storeId)
                    ->where('entity_type', 'product')
                    ->first();

                if (!$setting) {
                    // Fallback to ProductSeo
                    $seo = ProductSeo::where('product_id', $entityId)->first();
                    return $seo?->product_url ? '/' . ltrim($seo->product_url, '/') : null;
                }

                return $this->generateProductUrlFromSetting($product, $setting);

            case 'category':
                $category = Category::where('id', $entityId)->where('store_id', $storeId)->first();
                if (!$category) return null;

                $setting = UrlSetting::where('store_id', $storeId)
                    ->where('entity_type', 'category')
                    ->first();

                return $setting
                    ? $this->generateCategoryUrlFromSetting($category, $setting)
                    : ('/' . ltrim($category->slug ?? '', '/') . '/');

            case 'webpage':
            case 'page':
                $page = WebPage::where('id', $entityId)->where('store_id', $storeId)->first();
                if (!$page) return null;

                $setting = UrlSetting::where('store_id', $storeId)
                    ->where('entity_type', 'webpage')
                    ->first();

                return $setting
                    ? $this->generateWebpageUrlFromSetting($page, $setting)
                    : ($page->page_url ?? ('/' . Str::slug($page->page_name) . '/'));

            default:
                return null;
        }
    }

    private function generateProductUrlFromSetting($product, $setting): string
    {
        $format = $setting->format_type;
        $custom = $setting->custom_format;
        $titleSlug = Str::slug($product->name ?? '');
        $sku = $product->sku ?? '';

        $categorySlug = 'uncategorized';
        if ($product->category_id) {
            $category = Category::find($product->category_id);
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
                if (empty($custom)) return '/' . $titleSlug . '/';
                $url = str_replace(
                    ['%title%', '%sku%', '%id%', '%categoryname%', '%parent%'],
                    [$titleSlug, $sku, $product->id, $categorySlug, $categorySlug],
                    $custom
                );
                if (!str_starts_with($url, '/')) $url = '/' . $url;
                if (!str_ends_with($url, '/') && !str_contains($url, '.')) $url .= '/';
                return $url;
            default:
                return '/' . $titleSlug . '/';
        }
    }

    private function generateCategoryUrlFromSetting($category, $setting): string
    {
        $format = $setting->format_type;
        $custom = $setting->custom_format;
        $categorySlug = $category->slug ?? Str::slug($category->name ?? '');

        $parentSlug = '';
        if ($category->parent_id) {
            $parent = Category::find($category->parent_id);
            if ($parent) {
                $parentSlug = $parent->slug ?? Str::slug($parent->name ?? '');
            }
        }

        switch ($format) {
            case 'seo_optimized_short':
                return '/' . $categorySlug . '/';
            case 'seo_optimized_long':
                return '/categories/' . $categorySlug . '.html';
            case 'custom':
                if (empty($custom)) return '/' . $categorySlug . '/';
                $url = str_replace(
                    ['%categoryname%', '%parent%', '%id%'],
                    [$categorySlug, $parentSlug, $category->id],
                    $custom
                );
                if (!str_starts_with($url, '/')) $url = '/' . $url;
                if (!str_ends_with($url, '/') && !str_contains($url, '.')) $url .= '/';
                return $url;
            default:
                return '/' . $categorySlug . '/';
        }
    }

    private function generateWebpageUrlFromSetting($page, $setting): string
    {
        $format = $setting->format_type;
        $custom = $setting->custom_format;
        $pageSlug = Str::slug($page->page_name ?? '');

        switch ($format) {
            case 'seo_optimized_short':
                return '/' . $pageSlug . '/';
            case 'seo_optimized_long':
                return '/pages/' . $pageSlug . '.html';
            case 'custom':
                if (empty($custom)) return '/' . $pageSlug . '/';
                $url = str_replace(
                    ['%title%', '%id%'],
                    [$pageSlug, $page->id],
                    $custom
                );
                if (!str_starts_with($url, '/')) $url = '/' . $url;
                if (!str_ends_with($url, '/') && !str_contains($url, '.')) $url .= '/';
                return $url;
            default:
                return '/' . $pageSlug . '/';
        }
    }

    public function importRedirects(Request $request)
    {
        try {
            $storeId = $this->getStoreId();

            $request->validate([
                'file' => 'required|file|mimes:csv,txt|max:10240',
            ]);

            $file = $request->file('file');
            $handle = fopen($file->getRealPath(), 'r');

            fgetcsv($handle); // skip header

            $imported = 0;
            $skipped  = 0;
            $errors   = [];

            while (($row = fgetcsv($handle)) !== false) {
                $domain    = trim($row[0] ?? '');
                $oldPath   = trim($row[1] ?? '');
                $manualUrl = trim($row[2] ?? '');
                $dynType   = trim($row[3] ?? '');
                $dynId     = trim($row[4] ?? '');

                if (empty($oldPath)) {
                    $skipped++;
                    continue;
                }

                if (!str_starts_with($oldPath, '/')) {
                    $oldPath = '/' . $oldPath;
                }

                if (Redirect::where('store_id', $storeId)->where('old_url', $oldPath)->exists()) {
                    $skipped++;
                    $errors[] = "Already exists: {$oldPath}";
                    continue;
                }

                $redirectType = 'manual';
                $newUrl       = $manualUrl;
                $entityType   = 'manual';
                $entityId     = null;
                $productId    = null;

                if (!empty($dynType) && !empty($dynId)) {
                    $redirectType = 'dynamic';
                    $entityType   = strtolower($dynType);
                    $entityId     = (int) $dynId;
                    $productId    = ($entityType === 'product') ? $entityId : null;

                    $newUrl = $this->generateDynamicUrl($entityType, $entityId, $storeId);

                    if (!$newUrl) {
                        $skipped++;
                        $errors[] = "Dynamic target not found: {$oldPath} (Type: {$dynType}, ID: {$dynId})";
                        continue;
                    }
                }

                if ($redirectType === 'manual') {
                    if (empty($newUrl)) {
                        $skipped++;
                        $errors[] = "No destination for: {$oldPath}";
                        continue;
                    }
                    if (!str_starts_with($newUrl, '/')) {
                        $newUrl = '/' . $newUrl;
                    }
                }

                Redirect::create([
                    'store_id'      => $storeId,
                    'product_id'    => $productId,
                    'entity_id'     => $entityId,
                    'entity_type'   => $entityType,
                    'redirect_type' => $redirectType,
                    'domain'        => $domain ?: $this->getDomain(),
                    'old_url'       => $oldPath,
                    'new_url'       => $newUrl,
                    'is_active'     => true,
                ]);

                $imported++;
            }

            fclose($handle);

            // ========== ACTIVITY LOG ==========
            $log = RedirectActivityLog::create([
                'store_id'      => $storeId,
                'type'          => 'import',
                'total_items'   => $imported + $skipped,
                'successful'    => $imported,
                'errors'        => $skipped,
                'status'        => 'complete',
                'file_name'     => $file->getClientOriginalName(),
                'error_message' => !empty($errors) ? implode("\n", array_slice($errors, 0, 20)) : null,
            ]);

            return response()->json([
                'status'   => true,
                'message'  => 'Import completed successfully',
                'imported' => $imported,
                'skipped'  => $skipped,
                'errors'   => $errors,
                'log_id'   => $log->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Redirect Import Error: ' . $e->getMessage());

            RedirectActivityLog::create([
                'store_id'      => $this->getStoreId(),
                'type'          => 'import',
                'status'        => 'failed',
                'errors'        => 1,
                'error_message' => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Import failed',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function exportRedirects(Request $request)
    {
        try {
            $storeId = $this->getStoreId();
            $includeDynamicUrl = $request->boolean('export_dynamic_urls', false);

            $redirects = Redirect::where('store_id', $storeId)->orderBy('id')->get();

            if ($redirects->isEmpty()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'No redirects found to export.',
                ], 404);
            }

            $csv = $this->generateRedirectsCsv($redirects, $includeDynamicUrl);

            $fileName = 'Redirect-Export-' . Str::random(10) . '-' . now()->format('YmdHis') . '.csv';
            $s3Path   = "redirect_exports/{$storeId}/{$fileName}";

            Storage::disk('s3')->put($s3Path, $csv, 'public');
            $downloadUrl = Storage::disk('s3')->url($s3Path);

            // ========== ACTIVITY LOG ==========
            $log = RedirectActivityLog::create([
                'store_id'     => $storeId,
                'type'         => 'export',
                'total_items'  => $redirects->count(),
                'successful'   => $redirects->count(),
                'errors'       => 0,
                'status'       => 'complete',
                'file_name'    => $fileName,
                'download_url' => $downloadUrl,
                's3_path'      => $s3Path,
            ]);

            return response()->json([
                'status'       => true,
                'message'      => 'Export completed successfully',
                'total'        => $redirects->count(),
                'file_name'    => $fileName,
                'download_url' => $downloadUrl,
                's3_path'      => $s3Path,
                'log_id'       => $log->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Redirect Export Error: ' . $e->getMessage());

            RedirectActivityLog::create([
                'store_id'      => $this->getStoreId(),
                'type'          => 'export',
                'status'        => 'failed',
                'errors'        => 1,
                'error_message' => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Export failed',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function redirectActivityLogs(Request $request)
    {
        $storeId = $this->getStoreId();
        $perPage = $request->query('perPage', 10);

        $logs = RedirectActivityLog::where('store_id', $storeId)
            ->latest()
            ->paginate($perPage);

        $data = $logs->getCollection()->map(function ($log) {
            return [
                'id'           => $log->id,
                'dateFinished' => $log->created_at->format('m/d/Y, g:i:s A'),
                'type'         => ucfirst($log->type),
                'totalItems'   => $log->total_items,
                'successful'   => $log->successful,
                'errors'       => $log->errors,
                'status'       => strtoupper($log->status),
                'fileName'     => $log->file_name,
                'downloadUrl'  => $log->download_url,
            ];
        });

        return response()->json([
            'status'      => true,
            'total'       => $logs->total(),
            'perPage'     => $logs->perPage(),
            'currentPage' => $logs->currentPage(),
            'totalPages'  => $logs->lastPage(),
            'data'        => $data,
        ]);
    }

    public function redirectActivityLogDetail($id)
    {
        $storeId = $this->getStoreId();

        $log = RedirectActivityLog::where('store_id', $storeId)->find($id);

        if (!$log) {
            return response()->json(['status' => false, 'message' => 'Log not found'], 404);
        }

        return response()->json([
            'status' => true,
            'data'   => [
                'id'           => $log->id,
                'dateFinished' => $log->created_at->format('m/d/Y, g:i:s A'),
                'type'         => ucfirst($log->type),
                'totalItems'   => $log->total_items,
                'successful'   => $log->successful,
                'errors'       => $log->errors,
                'status'       => strtoupper($log->status),
                'fileName'     => $log->file_name,
                'downloadUrl'  => $log->download_url,
                'errorMessage' => $log->error_message,
            ]
        ]);
    }
}
