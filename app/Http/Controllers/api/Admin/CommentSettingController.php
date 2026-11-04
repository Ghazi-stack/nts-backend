<?php

namespace App\Http\Controllers\api\Admin;

use App\Models\CommentSetting;
use App\Http\Resources\CommentSettingResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CommentSettingController extends Controller
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

            $setting = CommentSetting::where('store_id', $storeId)->first();

            return response()->json([
                'status'  => true,
                'message' => 'Comment settings retrieved successfully',
                'data'    => $setting ? new CommentSettingResource($setting) : null,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching comment settings: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve comment settings',
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
                'commentSystem'                 => 'nullable|string|in:Built-in',
                'enableProductReviews'          => 'nullable|boolean',
                'onlyAcceptFromPastCustomers'   => 'nullable|boolean',
            ]);

            $data = [
                'comment_system'                    => $request->commentSystem ?? 'Built-in',
                'enable_product_reviews'            => $request->boolean('enableProductReviews'),
                'only_accept_from_past_customers'   => $request->boolean('onlyAcceptFromPastCustomers'),
            ];

            // Sirf wohi fields save karo jo request mein aayi hain
            $data = array_filter($data, fn($value) => !is_null($value), ARRAY_FILTER_USE_BOTH);

            $setting = CommentSetting::updateOrCreate(
                ['store_id' => $storeId],
                $data
            );

            return response()->json([
                'status'  => true,
                'message' => $setting->wasRecentlyCreated
                    ? 'Comment settings created successfully'
                    : 'Comment settings updated successfully',
                'data'    => new CommentSettingResource($setting),
            ], $setting->wasRecentlyCreated ? 201 : 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error saving comment settings: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to save comment settings',
            ], 500);
        }
    }
}
