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

    // public function getRates(Request $request)
    // {
    //     try {
    //         $storeId = current_store_id();

    //         if (!$storeId) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Store not selected or token invalid.',
    //             ], 400);
    //         }

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Resolve current shopper
    //         |--------------------------------------------------------------------------
    //         | Authenticated user has priority.
    //         | Guest users use X-Session-ID.
    //         */
    //         $user = auth('sanctum')->user();
    //         $sessionId = $user ? null : $request->header('X-Session-ID');

    //         if (!$user && !$sessionId) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'X-Session-ID header required for guest users.',
    //             ], 422);
    //         }

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Validate destination only
    //         |--------------------------------------------------------------------------
    //         | Frontend no longer sends package weight.
    //         */
    //         $validated = $request->validate([
    //             'destination' => 'required|array',

    //             'destination.country_code' => 'required|string|size:2',
    //             'destination.state'        => 'nullable|string|max:255',
    //             'destination.city'         => 'nullable|string|max:255',
    //             'destination.postal_code'  => 'required|string|max:50',
    //         ]);

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Get current cart
    //         |--------------------------------------------------------------------------
    //         */
    //         $cartQuery = Cart::query()
    //             ->where('store_id', $storeId)
    //             ->with('product')
    //             ->when(
    //                 $user,
    //                 fn ($query) => $query->where('user_id', $user->id)
    //             )
    //             ->when(
    //                 !$user,
    //                 fn ($query) => $query
    //                     ->where('session_id', $sessionId)
    //                     ->whereNull('user_id')
    //             );

    //         $cartItems = $cartQuery->get();

    //         if ($cartItems->isEmpty()) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Cart is empty.',
    //                 'data'    => [
    //                     'rates' => [],
    //                 ],
    //             ], 422);
    //         }

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Calculate total shipment weight
    //         |--------------------------------------------------------------------------
    //         |
    //         | Product weight is the authoritative weight.
    //         |
    //         | Example:
    //         |
    //         | Product weight = 4 LB
    //         | Quantity       = 2
    //         |
    //         | Total weight   = 8 LB
    //         |
    //         */
    //         $totalWeight = 0;

    //         foreach ($cartItems as $cartItem) {

    //             if (!$cartItem->product) {
    //                 continue;
    //             }

    //             $productWeight = (float) ($cartItem->product->weight ?? 0);
    //             $quantity      = (int) ($cartItem->quantity ?? 0);

    //             if ($productWeight > 0 && $quantity > 0) {
    //                 $totalWeight += $productWeight * $quantity;
    //             }
    //         }

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Validate calculated weight
    //         |--------------------------------------------------------------------------
    //         */
    //         if ($totalWeight <= 0) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Unable to calculate shipping weight for the cart.',
    //                 'data'    => [
    //                     'rates' => [],
    //                 ],
    //             ], 422);
    //         }

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Calculate cart total
    //         |--------------------------------------------------------------------------
    //         */
    //         $orderTotal = 0;

    //         foreach ($cartItems as $cartItem) {

    //             if (!$cartItem->product) {
    //                 continue;
    //             }

    //             $product = $cartItem->product;
    //             $quantity = (int) ($cartItem->quantity ?? 0);

    //             /*
    //             * Use sale price when available, otherwise normal price.
    //             */
    //             $unitPrice = (float) (
    //                 $product->sale_price
    //                 ?? $product->price
    //                 ?? 0
    //             );

    //             $orderTotal += $unitPrice * $quantity;
    //         }

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Build server-side package
    //         |--------------------------------------------------------------------------
    //         */
    //         $packageInfo = [
    //             'total_weight'  => round($totalWeight, 2),
    //             'weight_unit'   => 'LB',
    //             'order_total'   => round($orderTotal, 2),
    //             'item_count'    => (int) $cartItems->sum('quantity'),
    //             'package_value' => round($orderTotal, 2),
    //         ];

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Get shipping rates
    //         |--------------------------------------------------------------------------
    //         */
    //         $rates = $this->shippingRateService->getCheckoutShippingRates(
    //             $validated['destination'],
    //             $packageInfo,
    //             $storeId
    //         );

    //         return response()->json([
    //             'status' => true,
    //             'message' => 'Shipping rates retrieved successfully.',
    //             'data' => [
    //                 'rates' => $rates,
    //                 'package' => [
    //                     'total_weight' => $packageInfo['total_weight'],
    //                     'weight_unit'  => $packageInfo['weight_unit'],
    //                     'item_count'   => $packageInfo['item_count'],
    //                 ],
    //             ],
    //         ], 200);

    //     } catch (\Illuminate\Validation\ValidationException $e) {

    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Validation failed.',
    //             'errors'  => $e->errors(),
    //         ], 422);

    //     } catch (\Throwable $e) {

    //         \Illuminate\Support\Facades\Log::error(
    //             'Checkout shipping rates failed',
    //             [
    //                 'store_id' => $storeId ?? null,
    //                 'error'    => $e->getMessage(),
    //                 'file'     => $e->getFile(),
    //                 'line'     => $e->getLine(),
    //             ]
    //         );

    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Failed to retrieve shipping rates.',
    //             'error'   => $e->getMessage(),
    //         ], 500);
    //     }
    // }

    // public function getRates(Request $request)
    // {
    //     try {
    //         /*
    //         |--------------------------------------------------------------------------
    //         | Store
    //         |--------------------------------------------------------------------------
    //         */
    //         $storeId = current_store_id();

    //         if (!$storeId) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Store not selected or token invalid.',
    //             ], 400);
    //         }

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Resolve current shopper
    //         |--------------------------------------------------------------------------
    //         |
    //         | Logged-in customer:
    //         |     use Sanctum user_id
    //         |
    //         | Guest:
    //         |     use X-Session-ID
    //         |
    //         */
    //         $user = auth('sanctum')->user();

    //         $sessionId = $user
    //             ? null
    //             : $request->header('X-Session-ID');

    //         Log::info('Shipping rate request started', [
    //             'store_id'    => $storeId,
    //             'user_id'     => $user?->id,
    //             'session_id'  => $sessionId,
    //             'has_user'    => (bool) $user,
    //             'has_session' => !empty($sessionId),
    //             'request_data' => $request->except([
    //                 'password',
    //                 'token',
    //             ]),
    //         ]);

    //         if (!$user && !$sessionId) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'X-Session-ID header required for guest users.',
    //             ], 422);
    //         }

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Validate destination
    //         |--------------------------------------------------------------------------
    //         |
    //         | IMPORTANT:
    //         | Frontend does NOT need to send weight anymore.
    //         |
    //         */
    //         $validated = $request->validate([
    //             'destination' => 'required|array',
    //             'destination.country_code' => 'required|string|size:2',
    //             'destination.state'        => 'nullable|string|max:255',
    //             'destination.city'         => 'nullable|string|max:255',
    //             'destination.postal_code'  => 'required|string|max:50',

    //             'package' => 'nullable|array',
    //             'package.total_weight'  => 'nullable|numeric|min:0.01',
    //             'package.weight_unit'   => 'nullable|string|max:10',
    //             'package.order_total'   => 'nullable|numeric|min:0',
    //             'package.item_count'    => 'nullable|integer|min:1',
    //             'package.package_value' => 'nullable|numeric|min:0',
    //         ]);

    //         $destination = $validated['destination'];

    //         $packageFromRequest = $validated['package'] ?? null;

    //         $hasPackageFromRequest = is_array($packageFromRequest)
    //             && !empty($packageFromRequest['total_weight'])
    //             && !empty($packageFromRequest['item_count']);

    //         // Log::info('Shipping destination validated', [
    //         //     'store_id'    => $storeId,
    //         //     'destination' => $destination,
    //         // ]);

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Load current cart
    //         |--------------------------------------------------------------------------
    //         */
    //         $cartQuery = Cart::query()
    //             ->where('store_id', $storeId)
    //             ->with('product');

    //         if ($user) {
    //             $cartQuery->where('user_id', $user->id);
    //         } else {
    //             $cartQuery
    //                 ->where('session_id', $sessionId)
    //                 ->whereNull('user_id');
    //         }

    //         $cartItems = $cartQuery->get();

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Log cart information
    //         |--------------------------------------------------------------------------
    //         */
    //         Log::info('Shipping cart loaded', [
    //             'store_id'       => $storeId,
    //             'user_id'        => $user?->id,
    //             'session_id'     => $sessionId,
    //             'cart_item_count' => $cartItems->count(),
    //             'cart_items'     => $cartItems->map(function ($cartItem) {
    //                 return [
    //                     'cart_id'           => $cartItem->id,
    //                     'product_id'        => $cartItem->product_id,
    //                     'quantity'          => $cartItem->quantity,
    //                     'product_exists'    => (bool) $cartItem->product,
    //                     'product_weight'    => $cartItem->product
    //                         ? $cartItem->product->getAttribute('weight')
    //                         : null,
    //                     'product_dimensions' => $cartItem->product
    //                         ? $cartItem->product->dimensions
    //                         : null,
    //                 ];
    //             })->values()->toArray(),
    //         ]);

    //         if ($cartItems->isEmpty()) {
    //             Log::warning('Shipping calculation failed: cart is empty', [
    //                 'store_id'   => $storeId,
    //                 'user_id'    => $user?->id,
    //                 'session_id' => $sessionId,
    //             ]);

    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Cart is empty.',
    //                 'data'    => [
    //                     'rates' => [],
    //                 ],
    //             ], 422);
    //         }

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Calculate shipment weight
    //         |--------------------------------------------------------------------------
    //         |
    //         | Primary source:
    //         |     products.weight
    //         |
    //         | Fallback:
    //         |     dimensions.weight
    //         |
    //         | Example:
    //         |
    //         | Product weight = 4 LB
    //         | Quantity       = 2
    //         | Total          = 8 LB
    //         |
    //         */
    //         $totalWeight = 0;
    //         $itemCount = 0;

    //         foreach ($cartItems as $cartItem) {

    //             $product = $cartItem->product;

    //             if (!$product) {
    //                 Log::warning('Shipping cart item has no product', [
    //                     'cart_id'    => $cartItem->id,
    //                     'product_id' => $cartItem->product_id,
    //                 ]);

    //                 continue;
    //             }

    //             $quantity = (int) $cartItem->quantity;

    //             if ($quantity <= 0) {
    //                 Log::warning('Shipping cart item has invalid quantity', [
    //                     'cart_id'  => $cartItem->id,
    //                     'quantity' => $cartItem->quantity,
    //                 ]);

    //                 continue;
    //             }

    //             /*
    //             |--------------------------------------------------------------------------
    //             | Primary weight source: products.weight
    //             |--------------------------------------------------------------------------
    //             */
    //             $productWeight = (float) (
    //                 $product->getAttribute('weight') ?? 0
    //             );

    //             /*
    //             |--------------------------------------------------------------------------
    //             | Fallback: dimensions.weight
    //             |--------------------------------------------------------------------------
    //             |
    //             | Your current dimensions JSON looks like:
    //             |
    //             | {
    //             |     "weight": 4,
    //             |     "width": 0,
    //             |     "height": 0,
    //             |     "depth": 0
    //             | }
    //             |
    //             */
    //             $dimensions = $product->dimensions;

    //             if (is_string($dimensions)) {
    //                 $decodedDimensions = json_decode($dimensions, true);

    //                 if (json_last_error() === JSON_ERROR_NONE) {
    //                     $dimensions = $decodedDimensions;
    //                 } else {
    //                     $dimensions = [];
    //                 }
    //             }

    //             if (!is_array($dimensions)) {
    //                 $dimensions = [];
    //             }

    //             $dimensionWeight = (float) (
    //                 $dimensions['weight'] ?? 0
    //             );

    //             /*
    //             |--------------------------------------------------------------------------
    //             | If products.weight is empty, use dimensions.weight
    //             |--------------------------------------------------------------------------
    //             */
    //             $weightUsed = $productWeight;

    //             if ($weightUsed <= 0 && $dimensionWeight > 0) {
    //                 $weightUsed = $dimensionWeight;
    //             }

    //             /*
    //             |--------------------------------------------------------------------------
    //             | Add item weight × quantity
    //             |--------------------------------------------------------------------------
    //             */
    //             $itemTotalWeight = $weightUsed * $quantity;

    //             $totalWeight += $itemTotalWeight;
    //             $itemCount += $quantity;

    //             /*
    //             |--------------------------------------------------------------------------
    //             | Detailed per-item log
    //             |--------------------------------------------------------------------------
    //             */
    //             Log::info('Shipping item weight calculated', [
    //                 'cart_id'            => $cartItem->id,
    //                 'product_id'         => $cartItem->product_id,
    //                 'quantity'           => $quantity,
    //                 'products_weight'    => $productWeight,
    //                 'dimensions_weight'  => $dimensionWeight,
    //                 'weight_used'        => $weightUsed,
    //                 'item_total_weight'  => $itemTotalWeight,
    //                 'running_total_weight' => $totalWeight,
    //             ]);
    //         }

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Final weight validation
    //         |--------------------------------------------------------------------------
    //         */
    //         if ($itemCount <= 0) {
    //             Log::warning('Shipping calculation failed: no valid cart items', [
    //                 'store_id' => $storeId,
    //             ]);

    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Cart does not contain any valid items.',
    //                 'data'    => [
    //                     'rates' => [],
    //                 ],
    //             ], 422);
    //         }

    //         if ($totalWeight <= 0) {

    //             Log::error('Shipping calculation failed: total weight is zero', [
    //                 'store_id'      => $storeId,
    //                 'user_id'       => $user?->id,
    //                 'session_id'    => $sessionId,
    //                 'item_count'    => $itemCount,
    //                 'total_weight'  => $totalWeight,
    //             ]);

    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Unable to calculate shipping weight for the cart.',
    //                 'data'    => [
    //                     'rates' => [],
    //                 ],
    //             ], 422);
    //         }

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Calculate order total
    //         |--------------------------------------------------------------------------
    //         */
    //         $orderTotal = 0;

    //         foreach ($cartItems as $cartItem) {

    //             $product = $cartItem->product;

    //             if (!$product) {
    //                 continue;
    //             }

    //             $quantity = (int) $cartItem->quantity;

    //             if ($quantity <= 0) {
    //                 continue;
    //             }

    //             /*
    //             | Use sale price when available, otherwise normal price.
    //             */
    //             $salePrice = $product->getAttribute('sale_price');
    //             $normalPrice = $product->getAttribute('price');

    //             $unitPrice = $salePrice !== null
    //                 ? (float) $salePrice
    //                 : (float) ($normalPrice ?? 0);

    //             $orderTotal += $unitPrice * $quantity;
    //         }

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Build server-side package
    //         |--------------------------------------------------------------------------
    //         */
    //         $packageInfo = [
    //             'total_weight'  => round($totalWeight, 2),
    //             'weight_unit'   => 'LB',
    //             'order_total'   => round($orderTotal, 2),
    //             'item_count'    => $itemCount,
    //             'package_value' => round($orderTotal, 2),
    //         ];

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Log final package
    //         |--------------------------------------------------------------------------
    //         */
    //         Log::info('Shipping package calculated successfully', [
    //             'store_id'      => $storeId,
    //             'user_id'       => $user?->id,
    //             'session_id'    => $sessionId,
    //             'total_weight'  => $packageInfo['total_weight'],
    //             'weight_unit'   => $packageInfo['weight_unit'],
    //             'item_count'    => $packageInfo['item_count'],
    //             'order_total'   => $packageInfo['order_total'],
    //             'package_value' => $packageInfo['package_value'],
    //             'destination'   => $destination,
    //         ]);

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Get shipping rates
    //         |--------------------------------------------------------------------------
    //         */
    //         $rates = $this->shippingRateService->getCheckoutShippingRates(
    //             $destination,
    //             $packageInfo,
    //             $storeId
    //         );

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Log returned rates
    //         |--------------------------------------------------------------------------
    //         */
    //         Log::info('Shipping rates retrieved', [
    //             'store_id'     => $storeId,
    //             'total_weight' => $packageInfo['total_weight'],
    //             'rate_count'   => is_array($rates) ? count($rates) : 0,
    //             'rates'        => $rates,
    //         ]);

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Response
    //         |--------------------------------------------------------------------------
    //         */
    //         return response()->json([
    //             'status'  => true,
    //             'message' => 'Shipping rates retrieved successfully.',
    //             'data'    => [
    //                 'rates' => $rates,

    //                 /*
    //                 | This is useful during testing.
    //                 | Once everything works, we can remove this if desired.
    //                 */
    //                 'package' => [
    //                     'total_weight' => $packageInfo['total_weight'],
    //                     'weight_unit'  => $packageInfo['weight_unit'],
    //                     'item_count'   => $packageInfo['item_count'],
    //                 ],
    //             ],
    //         ], 200);

    //     } catch (\Illuminate\Validation\ValidationException $e) {

    //         Log::warning('Shipping rate validation failed', [
    //             'errors' => $e->errors(),
    //         ]);

    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Validation failed.',
    //             'errors'  => $e->errors(),
    //         ], 422);

    //     } catch (\Throwable $e) {

    //         Log::error('Checkout shipping rates failed', [
    //             'store_id' => $storeId ?? null,
    //             'error'    => $e->getMessage(),
    //             'file'     => $e->getFile(),
    //             'line'     => $e->getLine(),
    //             'trace'    => $e->getTraceAsString(),
    //         ]);

    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Failed to retrieve shipping rates.',
    //             'error'   => $e->getMessage(),
    //         ], 500);
    //     }
    // }

    public function getRates(Request $request)
    {
        try {
            /*
            |--------------------------------------------------------------------------
            | Store
            |--------------------------------------------------------------------------
            */
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
            |
            | Website:
            |   Logged-in customer -> Sanctum user_id
            |   Guest             -> X-Session-ID
            |
            | Dashboard:
            |   package is supplied in the request, so a customer/session cart
            |   is NOT required.
            |
            */
            $user = auth('sanctum')->user();

            $sessionId = $user
                ? null
                : $request->header('X-Session-ID');

            /*
            |--------------------------------------------------------------------------
            | Validate request
            |--------------------------------------------------------------------------
            */
            $validated = $request->validate([
                'destination' => 'required|array',

                'destination.country_code' => 'required|string|size:2',
                'destination.state'        => 'nullable|string|max:255',
                'destination.city'         => 'nullable|string|max:255',
                'destination.postal_code'  => 'nullable|string|max:50',

                /*
                * Dashboard can provide the already-prepared package.
                *
                * Website does not need to send this.
                */
                'package' => 'nullable|array',
                'package.total_weight'  => 'nullable|numeric|min:0.01',
                'package.weight_unit'   => 'nullable|string|max:10',
                'package.order_total'   => 'nullable|numeric|min:0',
                'package.item_count'    => 'nullable|integer|min:1',
                'package.package_value' => 'nullable|numeric|min:0',
            ]);

            $destination = $validated['destination'];

            /*
            |--------------------------------------------------------------------------
            | Determine package source
            |--------------------------------------------------------------------------
            |
            | Dashboard:
            |   package.total_weight + package.item_count are supplied.
            |
            | Website:
            |   package is not supplied, so calculate everything from cart.
            |
            */
            $packageFromRequest = $validated['package'] ?? null;

            $hasPackageFromRequest = is_array($packageFromRequest)
                && isset($packageFromRequest['total_weight'])
                && (float) $packageFromRequest['total_weight'] > 0
                && isset($packageFromRequest['item_count'])
                && (int) $packageFromRequest['item_count'] > 0;

            /*
            |--------------------------------------------------------------------------
            | Log request
            |--------------------------------------------------------------------------
            */
            Log::info('Shipping rate request started', [
                'store_id'       => $storeId,
                'user_id'        => $user?->id,
                'session_id'     => $sessionId,
                'has_user'       => (bool) $user,
                'has_session'    => !empty($sessionId),
                'package_source' => $hasPackageFromRequest
                    ? 'request'
                    : 'cart',
                'destination'     => $destination,
                'package'        => $packageFromRequest,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Package supplied by Dashboard
            |--------------------------------------------------------------------------
            |
            | If the dashboard already calculated the package, use it directly.
            |
            | This avoids trying to find a customer/session cart.
            |
            */
            if ($hasPackageFromRequest) {

                $packageInfo = [
                    'total_weight'  => round(
                        (float) $packageFromRequest['total_weight'],
                        2
                    ),

                    'weight_unit'   => strtoupper(
                        $packageFromRequest['weight_unit'] ?? 'LB'
                    ),

                    'order_total'   => round(
                        (float) ($packageFromRequest['order_total'] ?? 0),
                        2
                    ),

                    'item_count'    => (int) $packageFromRequest['item_count'],

                    'package_value' => round(
                        (float) (
                            $packageFromRequest['package_value']
                            ?? $packageFromRequest['order_total']
                            ?? 0
                        ),
                        2
                    ),
                ];

                Log::info('Using shipping package supplied by dashboard', [
                    'store_id' => $storeId,
                    'package'  => $packageInfo,
                ]);

            } else {

                /*
                |--------------------------------------------------------------------------
                | Website requires customer/session identity
                |--------------------------------------------------------------------------
                */
                if (!$user && !$sessionId) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'X-Session-ID header required for guest users.',
                    ], 422);
                }

                /*
                |--------------------------------------------------------------------------
                | Load current website cart
                |--------------------------------------------------------------------------
                */
                $cartQuery = Cart::query()
                    ->where('store_id', $storeId)
                    ->with('product');

                if ($user) {

                    /*
                    * Logged-in website customer.
                    */
                    $cartQuery->where('user_id', $user->id);

                } else {

                    /*
                    * Guest website customer.
                    */
                    $cartQuery
                        ->where('session_id', $sessionId)
                        ->whereNull('user_id');
                }

                $cartItems = $cartQuery->get();

                /*
                |--------------------------------------------------------------------------
                | Log cart information
                |--------------------------------------------------------------------------
                */
                Log::info('Shipping cart loaded', [
                    'store_id'        => $storeId,
                    'user_id'         => $user?->id,
                    'session_id'      => $sessionId,
                    'cart_item_count' => $cartItems->count(),
                    'cart_items'      => $cartItems->map(function ($cartItem) {
                        return [
                            'cart_id'        => $cartItem->id,
                            'product_id'     => $cartItem->product_id,
                            'quantity'       => $cartItem->quantity,
                            'product_exists' => (bool) $cartItem->product,

                            'product_weight' => $cartItem->product
                                ? $cartItem->product->getAttribute('weight')
                                : null,

                            'product_dimensions' => $cartItem->product
                                ? $cartItem->product->dimensions
                                : null,
                        ];
                    })->values()->toArray(),
                ]);

                /*
                |--------------------------------------------------------------------------
                | Empty cart
                |--------------------------------------------------------------------------
                */
                if ($cartItems->isEmpty()) {

                    Log::warning(
                        'Shipping calculation failed: cart is empty',
                        [
                            'store_id'   => $storeId,
                            'user_id'    => $user?->id,
                            'session_id' => $sessionId,
                        ]
                    );

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
                | Calculate shipment weight
                |--------------------------------------------------------------------------
                |
                | Primary:
                |   products.weight
                |
                | Fallback:
                |   dimensions.weight
                |
                */
                $totalWeight = 0;
                $itemCount = 0;

                foreach ($cartItems as $cartItem) {

                    $product = $cartItem->product;

                    /*
                    * Product no longer exists.
                    */
                    if (!$product) {

                        Log::warning(
                            'Shipping cart item has no product',
                            [
                                'cart_id'    => $cartItem->id,
                                'product_id' => $cartItem->product_id,
                            ]
                        );

                        continue;
                    }

                    $quantity = (int) $cartItem->quantity;

                    /*
                    * Invalid quantity.
                    */
                    if ($quantity <= 0) {

                        Log::warning(
                            'Shipping cart item has invalid quantity',
                            [
                                'cart_id'  => $cartItem->id,
                                'quantity' => $cartItem->quantity,
                            ]
                        );

                        continue;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Primary weight source: products.weight
                    |--------------------------------------------------------------------------
                    */
                    $productWeight = (float) (
                        $product->getAttribute('weight') ?? 0
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | Fallback: dimensions.weight
                    |--------------------------------------------------------------------------
                    */
                    $dimensions = $product->dimensions;

                    if (is_string($dimensions)) {

                        $decodedDimensions = json_decode(
                            $dimensions,
                            true
                        );

                        if (json_last_error() === JSON_ERROR_NONE) {
                            $dimensions = $decodedDimensions;
                        } else {
                            $dimensions = [];
                        }
                    }

                    if (!is_array($dimensions)) {
                        $dimensions = [];
                    }

                    $dimensionWeight = (float) (
                        $dimensions['weight'] ?? 0
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | Select weight
                    |--------------------------------------------------------------------------
                    */
                    $weightUsed = $productWeight;

                    if ($weightUsed <= 0 && $dimensionWeight > 0) {
                        $weightUsed = $dimensionWeight;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Add item weight × quantity
                    |--------------------------------------------------------------------------
                    */
                    $itemTotalWeight = $weightUsed * $quantity;

                    $totalWeight += $itemTotalWeight;
                    $itemCount += $quantity;
                }

                /*
                |--------------------------------------------------------------------------
                | Validate item count
                |--------------------------------------------------------------------------
                */
                if ($itemCount <= 0) {

                    Log::warning(
                        'Shipping calculation failed: no valid cart items',
                        [
                            'store_id' => $storeId,
                        ]
                    );

                    return response()->json([
                        'status'  => false,
                        'message' => 'Cart does not contain any valid items.',
                        'data'    => [
                            'rates' => [],
                        ],
                    ], 422);
                }

                /*
                |--------------------------------------------------------------------------
                | Validate total weight
                |--------------------------------------------------------------------------
                */
                if ($totalWeight <= 0) {

                    Log::error(
                        'Shipping calculation failed: total weight is zero',
                        [
                            'store_id'     => $storeId,
                            'user_id'      => $user?->id,
                            'session_id'   => $sessionId,
                            'item_count'  => $itemCount,
                            'total_weight' => $totalWeight,
                        ]
                    );

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
                | Calculate order total
                |--------------------------------------------------------------------------
                */
                $orderTotal = 0;

                foreach ($cartItems as $cartItem) {

                    $product = $cartItem->product;

                    if (!$product) {
                        continue;
                    }

                    $quantity = (int) $cartItem->quantity;

                    if ($quantity <= 0) {
                        continue;
                    }

                    /*
                    * Use sale price when available,
                    * otherwise normal price.
                    */
                    $salePrice = $product->getAttribute('sale_price');
                    $normalPrice = $product->getAttribute('price');

                    $unitPrice = $salePrice !== null
                        ? (float) $salePrice
                        : (float) ($normalPrice ?? 0);

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
                    'item_count'    => $itemCount,
                    'package_value' => round($orderTotal, 2),
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | Final package validation
            |--------------------------------------------------------------------------
            */
            if (
                empty($packageInfo['total_weight'])
                || $packageInfo['total_weight'] <= 0
            ) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Unable to calculate shipping weight.',
                    'data'    => [
                        'rates' => [],
                    ],
                ], 422);
            }

            if (
                empty($packageInfo['item_count'])
                || $packageInfo['item_count'] <= 0
            ) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Unable to calculate shipping item count.',
                    'data'    => [
                        'rates' => [],
                    ],
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Log final package
            |--------------------------------------------------------------------------
            */
            Log::info('Shipping package calculated successfully', [
                'store_id'      => $storeId,
                'user_id'       => $user?->id,
                'session_id'    => $sessionId,
                'package_source' => $hasPackageFromRequest
                    ? 'dashboard'
                    : 'website_cart',
                'total_weight'  => $packageInfo['total_weight'],
                'weight_unit'   => $packageInfo['weight_unit'],
                'item_count'    => $packageInfo['item_count'],
                'order_total'  => $packageInfo['order_total'],
                'package_value' => $packageInfo['package_value'],
                'destination'  => $destination,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Get shipping rates
            |--------------------------------------------------------------------------
            */
            $rates = $this->shippingRateService->getCheckoutShippingRates(
                $destination,
                $packageInfo,
                $storeId
            );

            /*
            |--------------------------------------------------------------------------
            | Log returned rates
            |--------------------------------------------------------------------------
            */
            Log::info('Shipping rates retrieved', [
                'store_id'     => $storeId,
                'total_weight' => $packageInfo['total_weight'],
                'rate_count'   => is_array($rates) ? count($rates) : 0,
                'rates'        => $rates,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Response
            |--------------------------------------------------------------------------
            |
            | Keep the existing response structure unchanged.
            |
            */
            return response()->json([
                'status'  => true,
                'message' => 'Shipping rates retrieved successfully.',
                'data'    => [
                    'rates' => $rates,

                    'package' => [
                        'total_weight' => $packageInfo['total_weight'],
                        'weight_unit'  => $packageInfo['weight_unit'],
                        'item_count'   => $packageInfo['item_count'],
                    ],
                ],
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {

            Log::warning('Shipping rate validation failed', [
                'errors' => $e->errors(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {

            Log::error('Checkout shipping rates failed', [
                'store_id' => $storeId ?? null,
                'error'    => $e->getMessage(),
                'file'     => $e->getFile(),
                'line'     => $e->getLine(),
                'trace'    => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve shipping rates.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
