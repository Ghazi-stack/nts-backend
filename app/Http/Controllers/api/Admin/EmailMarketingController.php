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
                return response()->json(['status'=>false,'message'=>'Store not selected or token invalid.'],400);
            }

            $settings = EmailMarketingSetting::firstOrCreate(
                ['store_id'=>$storeId],
                [
                    'allow_newsletter_subscriptions'=>true,
                    'check_newsletter_box_by_default'=>false,
                    'show_newsletter_summary'=>false,
                    'extra'=>null,
                ]
            );

            return response()->json([
                'status'=>true,
                'message'=>'Email marketing settings fetched.',
                'data'=> new EmailMarketingSettingResource($settings),
            ]);
        } catch (Exception $e) {
            return response()->json(['status'=>false,'message'=>'Something went wrong.','error'=>$e->getMessage()],500);
        }
    }

    public function update(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json(['status'=>false,'message'=>'Store not selected or token invalid.'],400);
            }

            $data = $request->validate([
                'allowNewsletterSubscriptions' => 'nullable|boolean',
                'checkNewsletterBoxByDefault'  => 'nullable|boolean',
                'showNewsletterSummary'        => 'nullable|boolean',
                'extra'                        => 'nullable|array',
            ]);

            $settings = EmailMarketingSetting::firstOrCreate(['store_id'=>$storeId]);

            $settings->update([
                'allow_newsletter_subscriptions' => $data['allowNewsletterSubscriptions'],
                'check_newsletter_box_by_default'=> $data['checkNewsletterBoxByDefault'],
                'show_newsletter_summary'        => $data['showNewsletterSummary'],
                'extra'                          => $data['extra'] ?? null,
            ]);

            return response()->json([
                'status'=>true,
                'message'=>'Email marketing settings updated.',
                'data'=> new EmailMarketingSettingResource($settings->fresh()),
            ]);
        } catch (Exception $e) {
            return response()->json(['status'=>false,'message'=>'Something went wrong.','error'=>$e->getMessage()],500);
        }
    }
}
