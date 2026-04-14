<?php

namespace App\Http\Controllers\api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\UrlSetting;
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
}
