<?php

namespace App\Http\Controllers\api\Admin;

use Illuminate\Http\Request;
use App\Models\OrderStatusSetting;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class OrderStatusController extends Controller
{
    // BigCommerce default statuses
    private array $defaultStatuses = [
        'incomplete' => [
            'name'        => 'Incomplete',
            'description' => 'An incomplete order happens when a shopper reached the payment page, but did not complete the transaction.',
        ],
        'pending' => [
            'name'        => 'Pending',
            'description' => 'Customer started the checkout process, but did not complete it.',
        ],
        'awaiting_payment' => [
            'name'        => 'Awaiting Payment',
            'description' => 'Customer has completed checkout process, but payment has yet to be confirmed.',
        ],
        'awaiting_fulfillment' => [
            'name'        => 'Awaiting Fulfillment',
            'description' => 'Customer has completed the checkout process and payment has been confirmed.',
        ],
        'awaiting_shipment' => [
            'name'        => 'Awaiting Shipment',
            'description' => 'Order has been pulled and packaged, and is awaiting collection from a shipping provider.',
        ],
        'awaiting_pickup' => [
            'name'        => 'Awaiting Pickup',
            'description' => 'Order has been pulled, and is awaiting customer pickup from a seller-specified location.',
        ],
        'partially_shipped' => [
            'name'        => 'Partially Shipped',
            'description' => 'Only some items in the order have been shipped, due to some products being pre-order only or other reasons.',
        ],
        'completed' => [
            'name'        => 'Completed',
            'description' => 'Client has paid for their digital product and their file(s) are available for download.',
        ],
        'shipped' => [
            'name'        => 'Shipped',
            'description' => 'Order has been shipped, but receipt has not been confirmed; seller used the Ship Items action.',
        ],
        'cancelled' => [
            'name'        => 'Cancelled',
            'description' => 'Seller has cancelled an order, due to a stock inconsistency or other reasons.',
        ],
        'declined' => [
            'name'        => 'Declined',
            'description' => 'Seller has marked the order as declined for lack of manual payment, or other reasons.',
        ],
        'refunded' => [
            'name'        => 'Refunded',
            'description' => 'Seller has used the Refund action.',
        ],
        'disputed' => [
            'name'        => 'Disputed',
            'description' => 'Customer has initiated a dispute resolution process for the PayPal transaction that paid for the order.',
        ],
        'manual_verification_required' => [
            'name'        => 'Manual Verification Required',
            'description' => 'Order on hold while some aspect needs to be manually confirmed.',
        ],
        'partially_refunded' => [
            'name'        => 'Partially Refunded',
            'description' => 'Seller has partially refunded the order.',
        ],
    ];

    /**
     * Get all order statuses for current store
     * Custom name agar hai to woh, warna default
     */
    public function index()
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.',
                ], 400);
            }

            $customs = OrderStatusSetting::where('store_id', $storeId)
                ->pluck('custom_name', 'status_key')
                ->toArray();

            $statuses = [];
            foreach ($this->defaultStatuses as $key => $default) {
                $statuses[] = [
                    'key'         => $key,
                    'name'        => $customs[$key] ?? $default['name'],
                    'description' => $default['description'],
                    'is_custom'   => isset($customs[$key]) && $customs[$key] !== $default['name'],
                ];
            }

            return response()->json([
                'status'  => true,
                'message' => 'Order statuses retrieved successfully',
                'data'    => $statuses,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching order statuses: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve order statuses',
            ], 500);
        }
    }

    /**
     * Save all statuses (Edit mode ke baad Save)
     */
    public function save(Request $request)
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
                'statuses'         => 'required|array',
                'statuses.*.key'   => 'required|string|in:' . implode(',', array_keys($this->defaultStatuses)),
                'statuses.*.name'  => 'required|string|max:255',
            ]);

            foreach ($request->statuses as $item) {
                $key  = $item['key'];
                $name = trim($item['name']);

                // Agar default name hi hai to record delete (ya null) kar do
                $defaultName = $this->defaultStatuses[$key]['name'];

                if ($name === $defaultName || $name === '') {
                    OrderStatusSetting::where('store_id', $storeId)
                        ->where('status_key', $key)
                        ->delete();
                } else {
                    OrderStatusSetting::updateOrCreate(
                        ['store_id' => $storeId, 'status_key' => $key],
                        ['custom_name' => $name]
                    );
                }
            }

            return response()->json([
                'status'  => true,
                'message' => 'Order statuses saved successfully',
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error saving order statuses: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to save order statuses',
            ], 500);
        }
    }

    /**
     * Restore single status to default
     */
    public function restore(Request $request, $key)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.',
                ], 400);
            }

            if (!array_key_exists($key, $this->defaultStatuses)) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Invalid status key',
                ], 404);
            }

            OrderStatusSetting::where('store_id', $storeId)
                ->where('status_key', $key)
                ->delete();

            return response()->json([
                'status'  => true,
                'message' => 'Status restored to default successfully',
                'data'    => [
                    'key'  => $key,
                    'name' => $this->defaultStatuses[$key]['name'],
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error restoring order status: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to restore status',
            ], 500);
        }
    }
}
