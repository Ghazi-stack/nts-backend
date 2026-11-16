<?php

namespace App\Http\Controllers\api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    /**
     * Compare non-negative integer strings without risking PHP integer overflow.
     */
    private function compareOrderNumbers(string $left, string $right): int
    {
        $left = ltrim($left, '0');
        $right = ltrim($right, '0');

        $left = $left === '' ? '0' : $left;
        $right = $right === '' ? '0' : $right;

        if (strlen($left) !== strlen($right)) {
            return strlen($left) <=> strlen($right);
        }

        return strcmp($left, $right) <=> 0;
    }

    public function store(Request $request)
    {
        // Validate the sequence value explicitly so invalid values return 422.
        $request->validate([
            'miscellaneous' => 'sometimes|nullable|array',
            'miscellaneous.startingOrderNumber' => [
                'sometimes',
                'required',
                'regex:/^[0-9]+$/',
            ],
        ]);

        try {
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

            DB::connection('tenant')->transaction(function () use ($storeId, $data, $request) {
                $setting = StoreSetting::where('store_id', $storeId)
                    ->lockForUpdate()
                    ->first();

                if (!$setting) {
                    $setting = new StoreSetting();
                    $setting->store_id = $storeId;
                }

                $incomingMiscellaneous = $request->input('miscellaneous');
                $incomingStart = is_array($incomingMiscellaneous)
                    ? ($incomingMiscellaneous['startingOrderNumber'] ?? null)
                    : null;

                if ($incomingStart !== null) {
                    $incomingStart = (string) $incomingStart;
                    $currentMiscellaneous = $setting->miscellaneous ?? [];
                    $currentStart = $currentMiscellaneous['startingOrderNumber'] ?? null;

                    // The configured value is the next number available.
                    $minimumAllowed = $currentStart !== null
                        ? (string) $currentStart
                        : null;

                    // Also protect sequences if older orders were numbered before
                    // the generator maintained the setting value.
                    $highestAssigned = Order::where('store_id', $storeId)
                        ->whereNotNull('order_number')
                        ->whereRaw("order_number REGEXP '^[0-9]+$'")
                        ->orderByRaw('CAST(order_number AS DECIMAL(65, 0)) DESC')
                        ->value('order_number');

                    if ($highestAssigned !== null) {
                        $afterHighest = ltrim((string) $highestAssigned, '0');
                        $afterHighest = $afterHighest === '' ? '0' : $afterHighest;
                        // Avoid native integer overflow for large configured sequences.
                        $afterHighest = $this->incrementNumericString($afterHighest);

                        if (
                            $minimumAllowed === null ||
                            $this->compareOrderNumbers($afterHighest, $minimumAllowed) > 0
                        ) {
                            $minimumAllowed = $afterHighest;
                        }
                    }

                    if (
                        $minimumAllowed !== null &&
                        $this->compareOrderNumbers($incomingStart, $minimumAllowed) < 0
                    ) {
                        abort(response()->json([
                            'status' => false,
                            'message' => 'Starting order number cannot be lower than the current sequence. You can only increase it.',
                            'minimumAllowed' => $minimumAllowed,
                        ], 422));
                    }
                }

                $setting->fill($data);
                $setting->save();
            });

            $setting = StoreSetting::where('store_id', $storeId)->first();

            return response()->json([
                'status'  => true,
                'message' => 'Settings saved successfully',
                'data'    => $setting
            ], 200);
        } catch (Exception $e) {
            // Preserve intentional 4xx responses raised by the sequence guard.
            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                return $e->getResponse();
            }

            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Increment an unsigned numeric string without integer overflow.
     */
    private function incrementNumericString(string $number): string
    {
        $digits = str_split($number);
        for ($i = count($digits) - 1; $i >= 0; $i--) {
            if ($digits[$i] !== '9') {
                $digits[$i] = (string) ((int) $digits[$i] + 1);
                return implode('', $digits);
            }
            $digits[$i] = '0';
        }

        return '1' . implode('', $digits);
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
