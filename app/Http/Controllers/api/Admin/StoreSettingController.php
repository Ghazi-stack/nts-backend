<?php

namespace App\Http\Controllers\api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\StoreSetting;
use Exception;

class StoreSettingController extends Controller
{
    private function getStoreId()
    {
        $storeId = current_store_id();

        if (!$storeId) {
            abort(response()->json([
                'status' => false,
                'message' => 'Store not selected or token invalid.'
            ], 400));
        }

        return $storeId;
    }

    public function store(Request $request)
    {
        try {
            // ✅ store_id from token / header
            $storeId = $this->getStoreId();

            $data = [
                'website'         => $request->input('website'),
                'display'         => $request->input('display'),
                'dateTimezone'    => $request->input('dateTimezone'),
                'miscellaneous'   => $request->input('miscellaneous'),
                'search'          => $request->input('search'),
                'securityPrivacy' => $request->input('securityPrivacy'),
                'urlStructure'    => $request->input('urlStructure'),
            ];

            // ✅ create if not exists, otherwise update
            $setting = StoreSetting::firstOrCreate(
                ['store_id' => $storeId]
            );

            // ❗ jo object nahi aya → NULL update ho jayega
            $setting->update($data);

            return response()->json([
                'status'  => true,
                'message' => 'Settings saved successfully',
                'data'    => $setting->fresh()
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong',
                'error'   => $e->getMessage()
            ], 500);
        }
    }


    public function index()
    {
        try {
            $storeId = $this->getStoreId();

            $settings = StoreSetting::where('store_id', $storeId)
                ->orderBy('id', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'data'   => $settings
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function getWebsiteSeo()
    {
        try {
            $storeId = $this->getStoreId();

            $setting = StoreSetting::where('store_id', $storeId)
                ->orderBy('id', 'desc')
                ->first();

            if (!$setting) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store setting not found.'
                ], 404);
            }

            $website = is_string($setting->website) ? json_decode($setting->website, true) : $setting->website;

            return response()->json([
                'status' => true,
                'data' => [
                    'homePageTitle'   => $website['homePageTitle'] ?? null,
                    'metaKeywords'    => $website['metaKeywords'] ?? null,
                    'metaDescription' => $website['metaDescription'] ?? null,
                    'wwwRedirect'     => $website['wwwRedirect'] ?? null,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
