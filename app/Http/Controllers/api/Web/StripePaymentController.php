<?php

namespace App\Http\Controllers\api\Web;

use Stripe\Stripe;
use Stripe\Webhook;
use Stripe\PaymentIntent;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Http\Controllers\Controller;
use App\Models\OrderTimeline;
use App\Services\StripeConnectAccountResolver;
use Illuminate\Support\Facades\Log;

class StripePaymentController extends Controller
{
    
    private function connectedStripeOptions(Request $request): array
    {
        // Temporarily use the original platform Stripe account.
        if (!config('services.stripe.connect_enabled', false)) {
            return [];
        }

        // Use Stripe Connect when explicitly enabled.
        return app(StripeConnectAccountResolver::class)->optionsForRequest($request);
    }


    /**
     * 💳 Pay - Authorize only (hold), do NOT capture yet
     * Guest ya logged-in user dono use kar sakte hain (token optional)
     */
    public function pay(Request $request)
    {
        $validated = $request->validate([
            'payment_method_id' => 'required|string',
            'amount'            => 'required|integer', // cents

            'selectedCurrency' => 'required|array',
            'selectedCurrency.id' => 'required|integer',
            'selectedCurrency.exchangeRate' => 'required|numeric',

            'coupon'           => 'nullable|array',
            'coupon.id'        => 'required_with:coupon|integer',
            'coupon.couponCode' => 'required_with:coupon|string',

            'method_id'        => 'nullable|integer',
            'service_type'     => 'nullable|string',
            'destination'      => 'required_with:method_id|array',
            'destination.country_code' => 'required_with:destination|string|size:2',
            'destination.state'        => 'nullable|string|max:255',
            'destination.city'         => 'nullable|string|max:255',
            'destination.postal_code'  => 'nullable|string|max:50',

            'products'         => 'required|array|min:1',
            'products.*.product_id' => 'required|integer|exists:products,id',
            'products.*.quantity'   => 'required|integer|min:1',

            'manualDiscount'   => 'nullable|numeric',
            'shippingCost'     => 'nullable|numeric',
        ]);

        $user = auth('sanctum')->user();
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json(['status' => false, 'message' => 'Store not selected'], 400);
        }

        // ===== CURRENCY =====
        $currencyId = $request->input('selectedCurrency.id');
        $currency = \App\Models\Currency::where('store_id', $storeId)
            ->where('id', $currencyId)
            ->where('enabled', true)
            ->first();

        if (!$currency) {
            return response()->json(['status' => false, 'message' => 'Currency not found'], 404);
        }

        $exchangeRate = (float) $currency->exchange_rate;
        $currencyCode = strtolower($currency->code);

        // ===== COUPON =====
        $discount = 0;
        if (!empty($validated['coupon'])) {
            $coupon = \App\Models\CouponCode::where('store_id', $storeId)
                ->where('id', $validated['coupon']['id'])
                ->where('couponCode', $validated['coupon']['couponCode'])
                ->where('enabled', true)
                ->first();

            if (!$coupon) {
                return response()->json(['status' => false, 'message' => 'Invalid coupon'], 404);
            }
            $discount = (float) $coupon->discountAmount;
        }

        $manualDiscount = $validated['manualDiscount'] ?? 0;
        $calculatedTotal = 0;
        $shippingCost = $validated['shippingCost'] ?? 0;
        $matchedRate = null;

        // ===== PRODUCTS CALCULATION (same as calculate) =====
        $totalWeight = 0;
        $itemCount = 0;
        $baseOrderTotal = 0;

        foreach ($validated['products'] as $item) {
            $product = Product::where('store_id', $storeId)->find($item['product_id']);
            if ($product) {
                $convertedPrice = round($product->price * $exchangeRate, 6);
                $lineTotal = round($convertedPrice * $item['quantity'], 6);
                $calculatedTotal += $lineTotal;

                $salePrice = $product->getAttribute('sale_price');
                $normalPrice = $product->getAttribute('price');
                $unitPrice = $salePrice !== null ? (float) $salePrice : (float) ($normalPrice ?? 0);
                $baseOrderTotal += $unitPrice * $item['quantity'];

                $productWeight = (float) ($product->getAttribute('weight') ?? 0);
                $dimensions = $product->dimensions;

                if (is_string($dimensions)) {
                    $decoded = json_decode($dimensions, true);
                    $dimensions = (json_last_error() === JSON_ERROR_NONE) ? $decoded : [];
                }
                if (!is_array($dimensions)) $dimensions = [];

                $dimensionWeight = (float) ($dimensions['weight'] ?? 0);
                $weightUsed = $productWeight > 0 ? $productWeight : $dimensionWeight;

                $totalWeight += $weightUsed * $item['quantity'];
                $itemCount += $item['quantity'];
            }
        }

        // ===== SHIPPING RATE MATCHING (same as calculate) =====
        if (!empty($validated['method_id']) && !empty($validated['destination'])) {
            $packageInfo = [
                'total_weight'  => round($totalWeight, 2),
                'weight_unit'   => 'LB',
                'order_total'   => round($baseOrderTotal, 2),
                'item_count'    => $itemCount,
                'package_value' => 0,
            ];

            $shippingRateService = app(\App\Services\FedEx\ShippingRateService::class);

            $ratesResponse = $shippingRateService->getCheckoutShippingRates(
                $validated['destination'],
                $packageInfo,
                $storeId,
                null,
                $currency->code
            );

            $rates = $ratesResponse['rates'] ?? [];

            foreach ($rates as $rate) {
                if ((int) $rate['method_id'] === (int) $validated['method_id']) {
                    if (!empty($validated['service_type'])) {
                        if (($rate['service_type'] ?? '') === $validated['service_type']) {
                            $matchedRate = $rate;
                            break;
                        }
                    } else {
                        $matchedRate = $rate;
                        break;
                    }
                }
            }

            if ($matchedRate) {
                $shippingCost = (float) $matchedRate['total_charge'];
            }
        }

        // ===== FINAL TOTAL (same formula as calculate) =====
        $calculatedTotal += $shippingCost;
        $convertedCouponDiscount = round($discount * $exchangeRate, 6);
        $calculatedTotal -= $convertedCouponDiscount;
        $calculatedTotal -= $manualDiscount;

        if ($calculatedTotal < 0) {
            $calculatedTotal = 0;
        }

        $calculatedCents = (int) round($calculatedTotal * 100);

        // ===== AMOUNT VERIFICATION =====
        if (abs($calculatedCents - $request->amount) > 1) {
            return response()->json([
                'status'     => false,
                'message'    => 'Amount mismatch. Please refresh and try again.',
                'calculated' => $calculatedCents,
                'received'   => $request->amount,
            ], 422);
        }

        $orderNumber = 'ORD-' . strtoupper(Str::random(8));

        Stripe::setApiKey(config('services.stripe.secret'));
        $stripeOptions = $this->connectedStripeOptions($request);

        try {
            DB::beginTransaction();

            $intent = PaymentIntent::create([
                'amount' => $request->amount,
                'currency' => $currencyCode,
                'payment_method' => $request->payment_method_id,
                'confirmation_method' => 'manual',
                'payment_method_types' => ['card'],
                'confirm' => true,
                'capture_method' => 'manual',
                'metadata' => [
                    'user_id'      => $user?->id,
                    'order_number' => $orderNumber,
                    'products'     => json_encode($validated['products']),
                    'currency_id'  => $currency->id,
                    'coupon_id'    => $validated['coupon']['id'] ?? null,
                ],
            ], array_merge($stripeOptions, [
                'idempotency_key' => 'payment_' . (string) ($request->header('Idempotency-Key') ?: ($request->input('checkout_id') ?: Str::uuid()))
            ]));

            if (in_array($intent->status, ['requires_action', 'requires_source_action'])) {
                DB::commit();
                return response()->json([
                    'status' => 'requires_action',
                    'payment_intent_client_secret' => $intent->client_secret
                ]);
            }

            if ($intent->status !== 'requires_capture') {
                DB::rollBack();
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Payment authorization failed. Status: ' . $intent->status,
                ], 400);
            }

            $riskScore = null;
            $riskLevel = null;

            $charge = $intent->latest_charge
                ? \Stripe\Charge::retrieve($intent->latest_charge, [], $stripeOptions)
                : null;

            if ($charge && $charge->outcome) {
                $riskScore = $charge->outcome->risk_score ?? null;
                $riskLevel = $charge->outcome->risk_level ?? null;
            }

            DB::commit();

            return response()->json([
                'status'             => 'authorized',
                'message'            => 'Payment authorized (on hold). Capture pending.',
                'amount'             => $intent->amount,
                'payment_intent_id'  => $intent->id,
                'payment_status'     => $intent->status,
                'risk_score'         => $riskScore,
                'risk_level'         => $riskLevel,
                'order_number'       => $orderNumber,
            ]);
        } catch (\Stripe\Exception\CardException $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => $e->getError()->message
            ], 402);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 💰 Capture - Actually charge the held payment
     * ADMIN ONLY - route middleware se protect hoga
     */
    public function capturePayment(Request $request)
    {
        $request->validate([
            'payment_intent_id'  => 'nullable',
            'payment_intent_ids' => 'nullable|array',
            'amount_to_capture'  => 'nullable|integer',
        ]);

        $intentIds = [];

        if ($request->filled('payment_intent_id')) {
            $single = $request->input('payment_intent_id');
            $intentIds = is_array($single) ? $single : [$single];
        }

        if ($request->filled('payment_intent_ids') && is_array($request->payment_intent_ids)) {
            $intentIds = array_merge($intentIds, $request->payment_intent_ids);
        }

        $intentIds = array_values(array_unique(array_filter(array_map('trim', $intentIds))));

        if (empty($intentIds)) {
            return response()->json([
                'status'  => false,
                'message' => 'Please provide payment_intent_id.',
            ], 422);
        }

        Stripe::setApiKey(config('services.stripe.secret'));
        $stripeOptions = $this->connectedStripeOptions($request);

        $results      = [];
        $successCount = 0;
        $failedCount  = 0;

        foreach ($intentIds as $intentId) {
            try {
                $payment = Payment::where('stripe_session_id', $intentId)->first();

                if (!$payment) {
                    $failedCount++;
                    $results[] = [
                        'payment_intent_id' => $intentId,
                        'status'            => false,
                        'message'           => 'Payment record not found.',
                    ];
                    continue;
                }

                if ($payment->payment_status === 'completed') {
                    $failedCount++;
                    $results[] = [
                        'payment_intent_id' => $intentId,
                        'order_id'          => $payment->order_id,
                        'status'            => false,
                        'message'           => 'Payment already captured.',
                    ];
                    continue;
                }

                $intent = PaymentIntent::retrieve($intentId, [], $stripeOptions);

                if ($intent->status !== 'requires_capture') {
                    $failedCount++;
                    $results[] = [
                        'payment_intent_id' => $intentId,
                        'order_id'          => $payment->order_id,
                        'status'            => false,
                        'message'           => 'Payment cannot be captured. Current status: ' . $intent->status,
                    ];
                    continue;
                }

                $captureParams = [];
                if (count($intentIds) === 1 && $request->has('amount_to_capture') && $request->amount_to_capture > 0) {
                    $captureParams['amount_to_capture'] = $request->amount_to_capture;
                }

                $captured = $intent->capture($captureParams, $stripeOptions);

                $payment->update([
                    'payment_status' => 'completed',
                ]);


                $storeId = current_store_id();
                event(new \App\Events\RealtimeStatsUpdated((int) $storeId));

                OrderTimeline::addEvent($payment->order_id, $storeId, 'payment_captured', 'Capture request successfully processed for the order #' . ($payment->order->order_number ?? $payment->order_id), [
                    'source'         => 'Credit Card (via Stripe)',
                    'payment_intent' => $intentId,
                    'amount'         => '$' . number_format($captured->amount_received / 100, 2),
                ], 'payment');

                $order = $payment->order;
                if ($order) {
                    $previousStatus = $order->status;
                    $order->update(['status' => 'Awaiting Fulfillment']);

                    OrderTimeline::addEvent($order->id, $storeId, 'status_changed', 'Order status changed', [
                        'previous_status' => $previousStatus,
                        'new_status'      => 'Awaiting Fulfillment',
                    ], 'dot');
                }

                $successCount++;
                $results[] = [
                    'payment_intent_id' => $intentId,
                    'order_id'          => $payment->order_id,
                    'status'            => true,
                    'message'           => 'Payment captured successfully.',
                    'amount_captured'   => $captured->amount_received,
                    'payment_status'    => $captured->status,
                ];
            } catch (\Stripe\Exception\CardException $e) {
                $failedCount++;
                $results[] = [
                    'payment_intent_id' => $intentId,
                    'status'            => false,
                    'message'           => $e->getError()->message,
                ];
            } catch (\Exception $e) {
                $failedCount++;
                $results[] = [
                    'payment_intent_id' => $intentId,
                    'status'            => false,
                    'message'           => $e->getMessage(),
                ];
            }
        }

        $isSingle = count($intentIds) === 1;
        $first    = $results[0];

        if ($isSingle) {
            return response()->json([
                'status'          => $first['status'],
                'message'         => $first['message'],
                'amount_captured' => $first['amount_captured'] ?? null,
                'payment_status'  => $first['payment_status'] ?? null,
                'order_id'        => $first['order_id'] ?? null,
            ], $first['status'] ? 200 : 400);
        }

        return response()->json([
            'status'         => $failedCount === 0,
            'message'        => $successCount . ' captured, ' . $failedCount . ' failed.',
            'success_count'  => $successCount,
            'failed_count'   => $failedCount,
            'data'           => $results,
        ]);
    }

    /**
     * 📩 Stripe Webhook to update order/payment status
     */
    // public function handleWebhook(Request $request)
    // {
    //     $payload = @file_get_contents('php://input');
    //     $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? null;
    //     $endpoint_secret = env('STRIPE_WEBHOOK_SECRET');

    //     try {
    //         $event = Webhook::constructEvent(
    //             $payload,
    //             $sig_header,
    //             $endpoint_secret
    //         );
    //     } catch (\Exception $e) {
    //         \Log::error('Stripe webhook verification failed:', ['error' => $e->getMessage()]);
    //         return response()->json(['error' => $e->getMessage()], 400);
    //     }

    //     if ($event->type === 'payment_intent.succeeded') {
    //         $intent = $event->data->object;

    //         $payment = Payment::where('stripe_session_id', $intent->id)->first();
    //         if ($payment) {
    //             $payment->update(['payment_status' => 'completed']);

    //             $order = $payment->order;
    //             if ($order) {
    //                 $order->update(['status' => 'Awaiting Fulfillment']);
    //             }
    //         }
    //     }

    //     if ($event->type === 'payment_intent.canceled') {
    //         $intent = $event->data->object;

    //         $payment = Payment::where('stripe_session_id', $intent->id)->first();
    //         if ($payment && $payment->payment_status === 'authorized') {
    //             $payment->update(['payment_status' => 'expired']);

    //             $order = $payment->order;
    //             if ($order) {
    //                 $order->update(['status' => 'Payment Expired']);
    //             }
    //         }
    //     }

    //     return response()->json(['status' => 'success']);
    // }

    public function handleWebhook(Request $request)
    {
        $payload = $request->getContent();

        $sigHeader = $request->header('Stripe-Signature');

        $endpointSecret = config('services.stripe.webhook_secret')
            ?? env('STRIPE_WEBHOOK_SECRET');

        try {

            $event = Webhook::constructEvent(
                $payload,
                $sigHeader,
                $endpointSecret
            );
        } catch (\UnexpectedValueException $e) {

            \Log::error('Stripe webhook invalid payload', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Invalid payload',
            ], 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {

            \Log::error('Stripe webhook signature verification failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Invalid signature',
            ], 400);
        }

        try {

            /*
            |--------------------------------------------------------------------------
            | PAYMENT AUTHORIZED
            |--------------------------------------------------------------------------
            |
            | Manual capture PaymentIntent becomes capturable.
            |
            */

            if ($event->type === 'payment_intent.amount_capturable_updated') {

                $intent = $event->data->object;

                $payment = Payment::where(
                    'stripe_session_id',
                    $intent->id
                )->first();

                if ($payment) {

                    $payment->update([
                        'payment_status' => 'authorized',
                    ]);

                    \Log::info(
                        'Stripe Payment authorized',
                        [
                            'payment_intent_id' => $intent->id,
                            'order_id' => $payment->order_id,
                        ]
                    );
                }
            }


            /*
            |--------------------------------------------------------------------------
            | PAYMENT CAPTURED
            |--------------------------------------------------------------------------
            */ elseif ($event->type === 'payment_intent.succeeded') {

                $intent = $event->data->object;

                $payment = Payment::where(
                    'stripe_session_id',
                    $intent->id
                )->first();

                if ($payment) {

                    /*
                    |--------------------------------------------------------------------------
                    | Idempotency protection
                    |--------------------------------------------------------------------------
                    */

                    if ($payment->payment_status !== 'completed') {

                        $payment->update([
                            'payment_status' => 'completed',
                        ]);

                        $order = $payment->order;

                        if ($order) {

                            $previousStatus = $order->status;

                            $order->update([
                                'status' => 'Awaiting Fulfillment',
                            ]);

                            /*
                            |--------------------------------------------------------------------------
                            | Timeline
                            |--------------------------------------------------------------------------
                            */

                            try {

                                $storeId = current_store_id();

                                OrderTimeline::addEvent(
                                    $order->id,
                                    $storeId,
                                    'payment_captured',
                                    'Payment captured successfully.',
                                    [
                                        'source' => 'Express Checkout via Stripe',
                                        'payment_intent' => $intent->id,
                                        'amount' => '$' . number_format(
                                            ($intent->amount_received ?? $intent->amount) / 100,
                                            2
                                        ),
                                    ],
                                    'payment'
                                );

                                OrderTimeline::addEvent(
                                    $order->id,
                                    $storeId,
                                    'status_changed',
                                    'Order status changed',
                                    [
                                        'previous_status' => $previousStatus,
                                        'new_status' => 'Awaiting Fulfillment',
                                    ],
                                    'dot'
                                );
                            } catch (\Throwable $timelineException) {

                                \Log::warning(
                                    'Unable to create Express Checkout order timeline',
                                    [
                                        'error' => $timelineException->getMessage(),
                                    ]
                                );
                            }
                        }
                    }
                }
            }


            /*
            |--------------------------------------------------------------------------
            | PAYMENT CANCELED
            |--------------------------------------------------------------------------
            */ elseif ($event->type === 'payment_intent.canceled') {

                $intent = $event->data->object;

                $payment = Payment::where(
                    'stripe_session_id',
                    $intent->id
                )->first();

                if ($payment) {

                    if (
                        in_array(
                            $payment->payment_status,
                            ['pending', 'authorized']
                        )
                    ) {

                        $payment->update([
                            'payment_status' => 'expired',
                        ]);

                        $order = $payment->order;

                        if ($order) {

                            $order->update([
                                'status' => 'Payment Expired',
                            ]);
                        }
                    }
                }
            }


            /*
            |--------------------------------------------------------------------------
            | PAYMENT FAILED
            |--------------------------------------------------------------------------
            */ elseif ($event->type === 'payment_intent.payment_failed') {

                $intent = $event->data->object;

                $payment = Payment::where(
                    'stripe_session_id',
                    $intent->id
                )->first();

                if ($payment) {

                    $payment->update([
                        'payment_status' => 'failed',
                    ]);

                    $order = $payment->order;

                    if ($order) {

                        $order->update([
                            'status' => 'Payment Failed',
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {

            \Log::error(
                'Stripe webhook processing error',
                [
                    'event_type' => $event->type ?? null,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Return 200 only if you intentionally don't want Stripe retrying.
            |
            | For critical processing errors, returning 500 lets Stripe retry.
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'error' => 'Webhook processing failed',
            ], 500);
        }

        return response()->json([
            'status' => 'success',
        ]);
    }

    // Wallet Payment Functions

    /**
     * Create PaymentIntent for Express Checkout wallets.
     *
     * Supports:
     * - Apple Pay
     * - Google Pay
     * - Link
     * - PayPal
     * - Klarna
     * - Amazon Pay
     * - Other Stripe-supported Express Checkout methods
     */
    public function createExpressCheckoutIntent(Request $request)
    {
        $validated = $request->validate([
            'products' => 'required|array|min:1',

            'products.*.product_id' => [
                'required',
                'integer',
                'exists:products,id',
            ],

            'products.*.quantity' => [
                'required',
                'integer',
                'min:1',
            ],

            'shippingCost' => 'nullable|numeric|min:0',

            'discountAmount' => 'nullable|numeric|min:0',

            'manualDiscount' => 'nullable|numeric|min:0',

            'currency' => 'nullable|string|size:3',

            'isMultiAddress' => 'nullable|boolean',

            'destinations' => 'nullable|array',
        ]);

        try {

            $storeId = current_store_id();

            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected.',
                ], 400);
            }

            /*
            |--------------------------------------------------------------------------
            | Calculate product total from DATABASE
            |--------------------------------------------------------------------------
            |
            | Never trust the price sent from Next.js.
            |
            */

            $productTotal = 0;

            foreach ($validated['products'] as $item) {

                $product = Product::where('store_id', $storeId)
                    ->find($item['product_id']);

                if (!$product) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Product not found.',
                        'product_id' => $item['product_id'],
                    ], 404);
                }

                if ($product->current_stock < $item['quantity']) {
                    return response()->json([
                        'status' => false,
                        'message' => "Insufficient stock for {$product->name}.",
                    ], 422);
                }

                $productTotal +=
                    ((float) $product->price * (int) $item['quantity']);
            }

            /*
            |--------------------------------------------------------------------------
            | Shipping
            |--------------------------------------------------------------------------
            */

            $shippingCost = (float) (
                $validated['shippingCost'] ?? 0
            );

            /*
            |--------------------------------------------------------------------------
            | Discounts
            |--------------------------------------------------------------------------
            */

            $discountAmount = (float) (
                $validated['discountAmount'] ?? 0
            );

            $manualDiscount = (float) (
                $validated['manualDiscount'] ?? 0
            );

            /*
            |--------------------------------------------------------------------------
            | Final amount
            |--------------------------------------------------------------------------
            */

            $totalAmount =
                $productTotal
                + $shippingCost
                - $discountAmount
                - $manualDiscount;

            $totalAmount = max($totalAmount, 0);

            /*
            |--------------------------------------------------------------------------
            | Stripe amount
            |--------------------------------------------------------------------------
            */

            $stripeAmount = (int) round($totalAmount * 100);

            if ($stripeAmount <= 0) {
                return response()->json([
                    'status' => false,
                    'message' => 'Payment amount must be greater than zero.',
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Stripe
            |--------------------------------------------------------------------------
            */

            Stripe::setApiKey(
                config('services.stripe.secret')
            );
            $stripeOptions = $this->connectedStripeOptions($request);

            /*
            |--------------------------------------------------------------------------
            | PaymentIntent
            |--------------------------------------------------------------------------
            |
            | automatic_payment_methods allows Stripe to determine which
            | payment methods can be used.
            |
            | Express Checkout will then display the eligible wallet(s).
            |
            */

            $intent = PaymentIntent::create([
                'amount' => $stripeAmount,

                'currency' => strtolower(
                    $validated['currency'] ?? 'usd'
                ),

                'automatic_payment_methods' => [
                    'enabled' => true,
                ],

                /*
                 * IMPORTANT:
                 *
                 * Your existing system authorizes first and captures later.
                 */
                'capture_method' => 'manual',

                'metadata' => [
                    'store_id' => (string) $storeId,
                    'checkout_type' => 'express_checkout',
                ],
            ], array_merge($stripeOptions, [
                /*
                 * Prevent accidental duplicate PaymentIntents.
                 *
                 * Ideally replace this with a stable cart/session key.
                 */
                'idempotency_key' =>
                'express_checkout_' .
                    $storeId . '_' .
                    sha1(json_encode([
                        'products' => $validated['products'],
                        'shipping' => $shippingCost,
                        'discount' => $discountAmount,
                        'manual_discount' => $manualDiscount,
                    ])),
            ]));

            return response()->json([
                'status' => true,

                'message' =>
                'Express Checkout PaymentIntent created.',

                'data' => [
                    'payment_intent_id' => $intent->id,

                    'client_secret' => $intent->client_secret,

                    'amount' => $totalAmount,

                    'amount_cents' => $stripeAmount,

                    'currency' => $intent->currency,

                    'status' => $intent->status,
                ],
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {

            Log::error(
                'Stripe Express Checkout PaymentIntent Error',
                [
                    'error' => $e->getMessage(),
                ]
            );

            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        } catch (\Throwable $e) {

            Log::error(
                'Express Checkout Intent Error',
                [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return response()->json([
                'status' => false,
                'message' => 'Unable to create payment.',
            ], 500);
        }
    }

    /**
     * Update Express Checkout PaymentIntent after
     * customer selects a shipping rate.
     */
    public function updateExpressCheckoutIntent(Request $request)
    {
        $validated = $request->validate([
            'payment_intent_id' => 'required|string',

            'products' => 'required|array|min:1',

            'products.*.product_id' => [
                'required',
                'integer',
                'exists:products,id',
            ],

            'products.*.quantity' => [
                'required',
                'integer',
                'min:1',
            ],

            'shippingCost' => 'required|numeric|min:0',

            'discountAmount' => 'nullable|numeric|min:0',

            'manualDiscount' => 'nullable|numeric|min:0',

            'currency' => 'nullable|string|size:3',
        ]);

        try {
            $storeId = current_store_id();

            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected.',
                ], 400);
            }

            Stripe::setApiKey(
                config('services.stripe.secret')
            );
            $stripeOptions = $this->connectedStripeOptions($request);

            /*
            |--------------------------------------------------------------------------
            | Retrieve PaymentIntent
            |--------------------------------------------------------------------------
            */

            $intent = PaymentIntent::retrieve(
                $validated['payment_intent_id'],
                [],
                $stripeOptions
            );

            /*
            |--------------------------------------------------------------------------
            | Make sure this is our Express Checkout intent
            |--------------------------------------------------------------------------
            */

            if (
                ($intent->metadata->store_id ?? null)
                && (string) $intent->metadata->store_id !== (string) $storeId
            ) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid payment intent.',
                ], 403);
            }

            if (
                !in_array(
                    $intent->status,
                    [
                        'requires_payment_method',
                        'requires_confirmation',
                    ],
                    true
                )
            ) {
                return response()->json([
                    'status' => false,
                    'message' => 'PaymentIntent can no longer be updated.',
                    'payment_status' => $intent->status,
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Recalculate product total from database
            |--------------------------------------------------------------------------
            */

            $productTotal = 0;

            foreach ($validated['products'] as $item) {

                $product = Product::where('store_id', $storeId)
                    ->find($item['product_id']);

                if (!$product) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Product not found.',
                        'product_id' => $item['product_id'],
                    ], 404);
                }

                if (
                    $product->current_stock
                    < $item['quantity']
                ) {
                    return response()->json([
                        'status' => false,
                        'message' => "Insufficient stock for {$product->name}.",
                    ], 422);
                }

                $productTotal +=
                    (float) $product->price
                    * (int) $item['quantity'];
            }

            /*
            |--------------------------------------------------------------------------
            | Calculate final total
            |--------------------------------------------------------------------------
            */

            $shippingCost = (float) $validated['shippingCost'];

            $discountAmount = (float) (
                $validated['discountAmount'] ?? 0
            );

            $manualDiscount = (float) (
                $validated['manualDiscount'] ?? 0
            );

            $totalAmount =
                $productTotal
                + $shippingCost
                - $discountAmount
                - $manualDiscount;

            $totalAmount = max(
                $totalAmount,
                0
            );

            $stripeAmount = (int) round(
                $totalAmount * 100
            );

            if ($stripeAmount <= 0) {
                return response()->json([
                    'status' => false,
                    'message' => 'Payment amount must be greater than zero.',
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Update PaymentIntent
            |--------------------------------------------------------------------------
            */

            $updatedIntent = $intent->update([
                'amount' => $stripeAmount,
                'metadata' => [
                    'store_id' => (string) $storeId,
                    'checkout_type' => 'express_checkout',
                    'shipping_cost' => (string) $shippingCost,
                ],
            ], $stripeOptions);

            return response()->json([
                'status' => true,
                'message' => 'Express Checkout PaymentIntent updated.',
                'data' => [
                    'payment_intent_id' => $updatedIntent->id,
                    'amount' => $totalAmount,
                    'amount_cents' => $stripeAmount,
                    'currency' => $updatedIntent->currency,
                    'status' => $updatedIntent->status,
                ],
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {

            \Log::error(
                'Express Checkout PaymentIntent update failed',
                [
                    'error' => $e->getMessage(),
                ]
            );

            return response()->json([
                'status' => false,
                'message' => 'Unable to update payment amount.',
            ], 422);
        } catch (\Throwable $e) {

            \Log::error(
                'Express Checkout update error',
                [
                    'error' => $e->getMessage(),
                ]
            );

            return response()->json([
                'status' => false,
                'message' => 'Unable to update Express Checkout.',
            ], 500);
        }
    }
}
