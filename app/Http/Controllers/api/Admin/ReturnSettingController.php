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
                'returnsEnabled'               => 'nullable|boolean',
                'returnInstructions'           => 'nullable|string',
                'returnReasons'                => 'nullable|array',
                'returnReasons.*'              => 'nullable|string|max:255',
                'returnActions'                => 'nullable|array',
                'returnActions.*'              => 'nullable|string|max:255',
                'useDefaultReasonsActions'     => 'nullable|boolean',
                'allowStoreCredits'            => 'nullable|boolean',
                'notifyNewReturn'              => 'nullable|boolean',
                'notifyCustomerConfirmation'   => 'nullable|boolean',
                'notifyStatusChange'           => 'nullable|boolean',
            ]);

            $data = [
                'returns_enabled'               => $request->boolean('returnsEnabled'),
                'return_instructions'           => $request->returnInstructions,
                'return_reasons'                => $request->returnReasons,
                'return_actions'                => $request->returnActions,
                'use_default_reasons_actions'   => $request->boolean('useDefaultReasonsActions'),
                'allow_store_credits'           => $request->boolean('allowStoreCredits'),
                'notify_new_return'             => $request->boolean('notifyNewReturn'),
                'notify_customer_confirmation'  => $request->boolean('notifyCustomerConfirmation'),
                'notify_status_change'          => $request->boolean('notifyStatusChange'),
            ];

            // Sirf wohi fields save karo jo request mein aayi hain
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
