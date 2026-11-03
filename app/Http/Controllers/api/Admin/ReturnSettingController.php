<?php

namespace App\Http\Controllers\api\Admin;

use Illuminate\Http\Request;
use App\Models\ReturnSetting;
use App\Http\Resources\ReturnSettingResource;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ReturnSettingController extends Controller
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

            $setting = ReturnSetting::where('store_id', $storeId)->first();

            return response()->json([
                'status'  => true,
                'message' => 'Return settings retrieved successfully',
                'data'    => $setting ? new ReturnSettingResource($setting) : null,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching return settings: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve return settings',
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
                'returns_enabled'               => 'nullable|boolean',
                'return_instructions'           => 'nullable|string',
                'return_reasons'                => 'nullable|array',
                'return_reasons.*'              => 'nullable|string|max:255',
                'return_actions'                => 'nullable|array',
                'return_actions.*'              => 'nullable|string|max:255',
                'use_default_reasons_actions'   => 'nullable|boolean',
                'allow_store_credits'           => 'nullable|boolean',
                'notify_new_return'             => 'nullable|boolean',
                'notify_customer_confirmation'  => 'nullable|boolean',
                'notify_status_change'          => 'nullable|boolean',
            ]);

            $data = [
                'returns_enabled'               => $request->boolean('returns_enabled'),
                'return_instructions'           => $request->return_instructions,
                'return_reasons'                => $request->return_reasons,
                'return_actions'                => $request->return_actions,
                'use_default_reasons_actions'   => $request->boolean('use_default_reasons_actions'),
                'allow_store_credits'           => $request->boolean('allow_store_credits'),
                'notify_new_return'             => $request->boolean('notify_new_return'),
                'notify_customer_confirmation'  => $request->boolean('notify_customer_confirmation'),
                'notify_status_change'          => $request->boolean('notify_status_change'),
            ];

            // Sirf wohi fields save karo jo request mein aayi hain (baaki null rahegi)
            $data = array_filter($data, fn($value) => !is_null($value), ARRAY_FILTER_USE_BOTH);

            $setting = ReturnSetting::updateOrCreate(
                ['store_id' => $storeId],
                $data
            );

            return response()->json([
                'status'  => true,
                'message' => $setting->wasRecentlyCreated
                    ? 'Return settings created successfully'
                    : 'Return settings updated successfully',
                'data'    => new ReturnSettingResource($setting),
            ], $setting->wasRecentlyCreated ? 201 : 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error saving return settings: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to save return settings',
            ], 500);
        }
    }
}
