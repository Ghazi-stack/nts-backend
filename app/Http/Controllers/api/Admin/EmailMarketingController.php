<?php

namespace App\Http\Controllers\api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmailMarketingSettingResource;
use App\Models\EmailMarketingSetting;
use Illuminate\Http\Request;
use Exception;

class EmailMarketingController extends Controller
{
    public function show(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json(['status' => false, 'message' => 'Store not selected or token invalid.'], 400);
            }

            $settings = EmailMarketingSetting::firstOrCreate(
                ['store_id' => $storeId],
                [
                    'allowNewsletterSubscriptions' => true,
                    'checkNewsletterBoxByDefault' => false,
                    'newsletterSummaryText' => null,
                    'showNewsletterSummary' => false,
                ]
            );

            $subscriptionsCount = \App\Models\Newsletter::query()
                ->where('store_id', $storeId)
                ->count();

            $data = (new EmailMarketingSettingResource($settings))->toArray($request);
            $data['subscriptionsCount'] = $subscriptionsCount;

            return response()->json([
                'status' => true,
                'message' => 'Email marketing settings fetched.',
                'data' => $data,
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => false, 'message' => 'Something went wrong.', 'error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json(['status' => false, 'message' => 'Store not selected or token invalid.'], 400);
            }

            $data = $request->validate([
                'allowNewsletterSubscriptions' => 'nullable|boolean',
                'checkNewsletterBoxByDefault'  => 'nullable|boolean',
                'newsletterSummaryText'        => 'nullable|string|max:255',
                'showNewsletterSummary'        => 'nullable|boolean',
                // 'extra'                        => 'nullable|array',
            ]);

            $settings = EmailMarketingSetting::firstOrCreate(['store_id' => $storeId]);

            $settings->update([
                'allowNewsletterSubscriptions' => $data['allowNewsletterSubscriptions'],
                'checkNewsletterBoxByDefault'  => $data['checkNewsletterBoxByDefault'],
                'newsletterSummaryText'        => $data['newsletterSummaryText'],
                'showNewsletterSummary'        => $data['showNewsletterSummary'],
                // 'extra'                          => $data['extra'] ?? null,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Email marketing settings updated.',
                'data' => new EmailMarketingSettingResource($settings->fresh()),
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => false, 'message' => 'Something went wrong.', 'error' => $e->getMessage()], 500);
        }
    }
}
