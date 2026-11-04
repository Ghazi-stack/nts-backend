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
use App\Models\WebPage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
            config('database.connections.tenant.database')
        ))->onQueue('imports');

        return response()->json([
            'status'       => true,
            'message'      => 'Redirect creation started.',
            'progress_key' => $progressKey,
            'total'        => $total,
        ]);
    }

    /**
     * ===================== BULK UPDATE CATEGORY =====================
     */
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
            config('database.connections.tenant.database')
        ))->onQueue('imports');

        return response()->json([
            'status'       => true,
            'message'      => 'Category redirect creation started.',
            'progress_key' => $progressKey,
            'total'        => $total,
        ]);
    }

    /**
     * ===================== BULK UPDATE WEB PAGE =====================
     */
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
            config('database.connections.tenant.database')
        ))->onQueue('imports');

        return response()->json([
            'status'       => true,
            'message'      => 'Web Page redirect creation started.',
            'progress_key' => $progressKey,
            'total'        => $total,
        ]);
    }

    /**
     * Progress check (common for all)
     */
    public function bulkUpdateProgress($progressKey)
    {
        $progress = Cache::get($progressKey, [
            'current' => 0,
            'total'   => 0,
            'created' => 0,
            'status'  => 'unknown',
        ]);

        return response()->json($progress);
    }

    //redirects setting api
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
                      ->orWhere('entity_type', 'like', "%{$search}%");
                });
            }

            $total = $query->count();

            $redirects = $query->latest()
                ->paginate($perPage);

            $data = $redirects->getCollection()->map(function ($redirect) {
                return [
                    'id'          => $redirect->id,
                    'storeId'     => $redirect->store_id,
                    'productId'   => $redirect->product_id,
                    'entityType'  => $redirect->entity_type,
                    'oldUrl'      => $redirect->old_url,
                    'newUrl'      => $redirect->new_url,
                    'isActive'    => (bool) $redirect->is_active,
                    'createdAt'   => $redirect->created_at,
                    'updatedAt'   => $redirect->updated_at,
                ];
            });

            return response()->json([
                'status'     => true,
                'message'    => 'Redirects retrieved successfully',
                'total'      => $total,
                'count'      => $redirects->count(),
                'perPage'    => $redirects->perPage(),
                'currentPage'=> $redirects->currentPage(),
                'totalPages' => $redirects->lastPage(),
                'data'       => $data,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching redirects: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve redirects',
            ], 500);
        }
    }
}
