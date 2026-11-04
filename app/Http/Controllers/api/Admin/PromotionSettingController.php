<?php

namespace App\Http\Controllers\api\Admin;

use App\Models\PromotionSetting;
use App\Http\Resources\PromotionSettingResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PromotionSettingController extends Controller
{
    public function getSettings()
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.',
                ], 400);
            }

            $setting = PromotionSetting::where('store_id', $storeId)->first();

            return response()->json([
                'status'  => true,
                'message' => 'Promotion settings retrieved successfully',
                'data'    => $setting ? new PromotionSettingResource($setting) : null,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching promotion settings: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve promotion settings',
            ], 500);
        }
    }

    public function saveSettings(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.',
                ], 400);
            }

            $request->validate([
                'calculateIndependently'        => 'nullable|boolean',
                'calculateCumulatively'         => 'nullable|boolean',
                'numberOfCouponsAllowed'        => 'nullable|integer|min:1|max:100',
                'allowZeroPriceProducts'        => 'nullable|boolean',
                'allowCustomPriceOverrides'     => 'nullable|boolean',
            ]);

            $data = [
                'calculate_independently'       => $request->boolean('calculateIndependently'),
                'calculate_cumulatively'        => $request->boolean('calculateCumulatively'),
                'number_of_coupons_allowed'     => $request->numberOfCouponsAllowed,
                'allow_zero_price_products'     => $request->boolean('allowZeroPriceProducts'),
                'allow_custom_price_overrides'  => $request->boolean('allowCustomPriceOverrides'),
            ];

            // Sirf wohi fields save karo jo request mein aayi hain
            $data = array_filter($data, fn($value) => !is_null($value), ARRAY_FILTER_USE_BOTH);

            // Ensure only one of the two calculation methods is true
            if ($request->has('calculateIndependently') || $request->has('calculateCumulatively')) {
                if ($request->boolean('calculateIndependently')) {
                    $data['calculate_independently'] = true;
                    $data['calculate_cumulatively'] = false;
                } elseif ($request->boolean('calculateCumulatively')) {
                    $data['calculate_independently'] = false;
                    $data['calculate_cumulatively'] = true;
                }
            }

            $setting = PromotionSetting::updateOrCreate(
                ['store_id' => $storeId],
                $data
            );

            return response()->json([
                'status'  => true,
                'message' => $setting->wasRecentlyCreated
                    ? 'Promotion settings created successfully'
                    : 'Promotion settings updated successfully',
                'data'    => new PromotionSettingResource($setting),
            ], $setting->wasRecentlyCreated ? 201 : 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error saving promotion settings: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to save promotion settings',
            ], 500);
        }
    }
}
