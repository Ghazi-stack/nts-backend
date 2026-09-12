<?php

namespace App\Http\Controllers\API\FedEx;

use App\Http\Controllers\Controller;
use App\Services\FedEx\ShippingRateService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Cart;
use Illuminate\Support\Facades\Log;

class CheckoutShippingController extends Controller
{
    protected ShippingRateService $shippingRateService;

    public function __construct(ShippingRateService $shippingRateService)
    {
        $this->shippingRateService = $shippingRateService;
    }

    /**
     * POST + GET /checkout/get-shipping-rates
     *
     * Customer checkout pe address enter kare → ye call hogi
     * Response mein saare shipping options aayenge with prices
     */
    // public function getRates(Request $request): JsonResponse
    // {
    //     $storeId = current_store_id();
    //     if (!$storeId) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Store not selected or token invalid.'
    //         ], 400);
    //     }

    //     $validated = $request->validate([
    //         'destination' => 'required|array',
    //         'destination.country_code' => 'required|string|max:5',
    //         'destination.state' => 'sometimes|string|max:5',
    //         'destination.city' => 'nullable|string|max:255',
    //         'destination.postal_code' => 'required|string|max:20',
    //         'package' => 'required|array',
    //         'package.total_weight' => 'required|numeric|min:0.01',
    //         'package.weight_unit' => 'sometimes|string|in:LB,KG',
    //         // 'package.package_length'=>'sometimes|numeric|min:0',
    //         // 'package.package_width'=>'sometimes|numeric|min:0',
    //         // 'package.package_height'=>'sometimes|numeric|min:0',
    //         // 'package.dimension_unit'=>'sometimes|string|in:IN,CM',
    //         'package.order_total' => 'sometimes|numeric|min:0',
    //         'package.item_count' => 'sometimes|integer|min:1',
    //         'package.package_value' => 'sometimes|numeric|min:0',
    //     ]);

    //     return response()->json($this->shippingRateService->getCheckoutShippingRates($validated['destination'], $validated['package'], $storeId));
    // }

    public function getRates(Request $request)
    {
        try {
            $storeId = current_store_id();

            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.',
                ], 400);
            }

            /*
            |--------------------------------------------------------------------------
            | Resolve current shopper
            |--------------------------------------------------------------------------
            | Authenticated user has priority.
            | Guest users use X-Session-ID.
            */
            $user = auth('sanctum')->user();
            $sessionId = $user ? null : $request->header('X-Session-ID');

            if (!$user && !$sessionId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'X-Session-ID header required for guest users.',
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Validate destination only
            |--------------------------------------------------------------------------
            | Frontend no longer sends package weight.
            */
            $validated = $request->validate([
                'destination' => 'required|array',

                'destination.country_code' => 'required|string|size:2',
                'destination.state'        => 'nullable|string|max:255',
                'destination.city'         => 'nullable|string|max:255',
                'destination.postal_code'  => 'required|string|max:50',
            ]);

            /*
            |--------------------------------------------------------------------------
            | Get current cart
            |--------------------------------------------------------------------------
            */
            $cartQuery = Cart::query()
                ->where('store_id', $storeId)
                ->with('product')
                ->when(
                    $user,
                    fn ($query) => $query->where('user_id', $user->id)
                )
                ->when(
                    !$user,
                    fn ($query) => $query
                        ->where('session_id', $sessionId)
                        ->whereNull('user_id')
                );

            $cartItems = $cartQuery->get();

            if ($cartItems->isEmpty()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Cart is empty.',
                    'data'    => [
                        'rates' => [],
                    ],
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Calculate total shipment weight
            |--------------------------------------------------------------------------
            |
            | Product weight is the authoritative weight.
            |
            | Example:
            |
            | Product weight = 4 LB
            | Quantity       = 2
            |
            | Total weight   = 8 LB
            |
            */
            $totalWeight = 0;

            foreach ($cartItems as $cartItem) {

                if (!$cartItem->product) {
                    continue;
                }

                $productWeight = (float) ($cartItem->product->weight ?? 0);
                $quantity      = (int) ($cartItem->quantity ?? 0);

                if ($productWeight > 0 && $quantity > 0) {
                    $totalWeight += $productWeight * $quantity;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Validate calculated weight
            |--------------------------------------------------------------------------
            */
            if ($totalWeight <= 0) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Unable to calculate shipping weight for the cart.',
                    'data'    => [
                        'rates' => [],
                    ],
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Calculate cart total
            |--------------------------------------------------------------------------
            */
            $orderTotal = 0;

            foreach ($cartItems as $cartItem) {

                if (!$cartItem->product) {
                    continue;
                }

                $product = $cartItem->product;
                $quantity = (int) ($cartItem->quantity ?? 0);

                /*
                * Use sale price when available, otherwise normal price.
                */
                $unitPrice = (float) (
                    $product->sale_price
                    ?? $product->price
                    ?? 0
                );

                $orderTotal += $unitPrice * $quantity;
            }

            /*
            |--------------------------------------------------------------------------
            | Build server-side package
            |--------------------------------------------------------------------------
            */
            $packageInfo = [
                'total_weight'  => round($totalWeight, 2),
                'weight_unit'   => 'LB',
                'order_total'   => round($orderTotal, 2),
                'item_count'    => (int) $cartItems->sum('quantity'),
                'package_value' => round($orderTotal, 2),
            ];

            /*
            |--------------------------------------------------------------------------
            | Get shipping rates
            |--------------------------------------------------------------------------
            */
            $rates = $this->shippingRateService->getCheckoutShippingRates(
                $validated['destination'],
                $packageInfo,
                $storeId
            );

            return response()->json([
                'status' => true,
                'message' => 'Shipping rates retrieved successfully.',
                'data' => [
                    'rates' => $rates,
                    'package' => [
                        'total_weight' => $packageInfo['total_weight'],
                        'weight_unit'  => $packageInfo['weight_unit'],
                        'item_count'   => $packageInfo['item_count'],
                    ],
                ],
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {

            \Illuminate\Support\Facades\Log::error(
                'Checkout shipping rates failed',
                [
                    'store_id' => $storeId ?? null,
                    'error'    => $e->getMessage(),
                    'file'     => $e->getFile(),
                    'line'     => $e->getLine(),
                ]
            );

            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve shipping rates.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
