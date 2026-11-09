<?php

namespace App\Http\Controllers\api\Admin;

use Illuminate\Http\Request;
use App\Models\InventorySetting;
use App\Http\Resources\InventorySettingResource;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventorySettingController extends Controller
{
    // Exact values from screenshots
    private array $productNoLonger = [
        'Hide the product',
        'Hide the product, but leave the product page accessible',
        'Redirect to category page',
        'Do nothing',
    ];

    private array $optionNoLongerSold = [
        'Hide the option',
        'Mark the option as out of stock',
        'Do nothing',
    ];

    private array $updateStockLevels = [
        'When an order is placed',
        'When order status is changed to "completed" or "shipped"',
    ];

    private array $stockLevelDisplay = [
        'Show stock levels',
        'Only show stock levels when stock is low',
        'Don’t show stock levels',
    ];

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

            $setting = InventorySetting::where('store_id', $storeId)->first();

            return response()->json([
                'status'  => true,
                'message' => 'Inventory settings retrieved successfully',
                'data'    => $setting ? new InventorySettingResource($setting) : null,
                'options' => [
                    'productNoLongerSold' => $this->productNoLongerSold,
                    'optionNoLongerSold'  => $this->optionNoLongerSold,
                    'updateStockLevels'   => $this->updateStockLevels,
                    'stockLevelDisplay'   => $this->stockLevelDisplay,
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching inventory settings: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve inventory settings',
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
                'productNoLongerSold'             => ['nullable', Rule::in($this->productNoLonger)],
                'optionNoLongerSold'              => ['nullable', Rule::in($this->optionNoLongerSold)],
                'updateStockLevels'               => ['nullable', Rule::in($this->updateStockLevels)],
                'autoAdjustStockOnRefundCancel'   => 'nullable|boolean',

                'stockLevelDisplay'               => ['nullable', Rule::in($this->stockLevelDisplay)],
                'outOfStockMessage'               => 'nullable|string|max:255',
                'showOosOnListing'                => 'nullable|boolean',

                'showQuantityOnBackorder'         => 'nullable|boolean',
                'showBackorderMessage'            => 'nullable|boolean',
                'backorderMessages'               => 'nullable|array',
                'backorderMessages.*.name'        => 'required_with:backorderMessages|string|max:255',
                'backorderMessages.*.message'     => 'nullable|string',
                'backorderMessages.*.isDefault'   => 'nullable|boolean',

                'showQuantityOnHand'              => 'nullable|boolean',
                'showBackorderAvailabilityPrompt' => 'nullable|boolean',
                'moreAvailableForBackorder'       => 'nullable|string|max:255',
                'showShippingExpectation'         => 'nullable|boolean',
                'shippingExpectationMessage'      => 'nullable|string',

                'lowStockEmail'                   => 'nullable|email|max:255',
                'outOfStockEmail'                 => 'nullable|email|max:255',
            ]);

            $data = [
                'product_no_longer_sold'                => $request->productNoLongerSold,
                'option_no_longer_sold'                 => $request->optionNoLongerSold,
                'update_stock_levels'                   => $request->updateStockLevels,
                'auto_adjust_stock_on_refund_cancel'    => $request->boolean('autoAdjustStockOnRefundCancel'),
                'stock_level_display'                   => $request->stockLevelDisplay,
                'out_of_stock_message'                  => $request->outOfStockMessage,
                'show_oos_on_listing'                   => $request->boolean('showOosOnListing'),
                'show_quantity_on_backorder'            => $request->boolean('showQuantityOnBackorder'),
                'show_backorder_message'                => $request->boolean('showBackorderMessage'),
                'show_quantity_on_hand'                 => $request->boolean('showQuantityOnHand'),
                'show_backorder_availability_prompt'    => $request->boolean('showBackorderAvailabilityPrompt'),
                'more_available_for_backorder'          => $request->moreAvailableForBackorder,
                'show_shipping_expectation'             => $request->boolean('showShippingExpectation'),
                'shipping_expectation_message'          => $request->shippingExpectationMessage,
                'low_stock_email'                       => $request->lowStockEmail,
                'out_of_stock_email'                    => $request->outOfStockEmail,
            ];

            // Remove nulls so only sent fields are updated
            $data = array_filter($data, fn($v) => !is_null($v));

            // Backorder messages (ensure only one default)
            if ($request->has('backorderMessages')) {
                $messages = $request->backorderMessages ?? [];
                $defaultSet = false;

                foreach ($messages as $index => &$msg) {
                    $msg['id'] = $msg['id'] ?? ($index + 1);
                    $msg['isDefault'] = !empty($msg['isDefault']);

                    if ($msg['isDefault']) {
                        if ($defaultSet) {
                            $msg['isDefault'] = false;
                        } else {
                            $defaultSet = true;
                        }
                    }
                }
                unset($msg);

                if (!$defaultSet && count($messages) > 0) {
                    $messages[0]['isDefault'] = true;
                }

                $data['backorder_messages'] = $messages;
            }

            $setting = InventorySetting::updateOrCreate(
                ['store_id' => $storeId],
                $data
            );

            return response()->json([
                'status'  => true,
                'message' => $setting->wasRecentlyCreated
                    ? 'Inventory settings created successfully'
                    : 'Inventory settings updated successfully',
                'data'    => new InventorySettingResource($setting),
            ], $setting->wasRecentlyCreated ? 201 : 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error saving inventory settings: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to save inventory settings',
            ], 500);
        }
    }
}
