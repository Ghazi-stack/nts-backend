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

class StripePaymentController extends Controller
{
    /**
     * 💳 Pay - Authorize only (hold), do NOT capture yet
     * Guest ya logged-in user dono use kar sakte hain (token optional)
     */
    public function pay(Request $request)
    {
        // \Log::info('Stripe Secret:', ['stripe_secret' => env('STRIPE_SECRET')]);
        $request->validate([
            'payment_method_id' => 'required|string',
            'amount'         => 'required',
            'products'         => 'required|array|min:1',
            'products.*.product_id' => 'required|integer|exists:products,id',
            'products.*.quantity'   => 'required|integer|min:1',
        ]);

        $user = auth('sanctum')->user();
        $cartItems = $request->products;

        // $totalAmount = 0;
        // foreach ($cartItems as $item) {
        //     $product = Product::find($item['product_id']);
        //     $totalAmount += $product->price * $item['quantity'];
        // }
        $currency = 'usd';

        // 2️⃣ Generate order number
        $orderNumber = 'ORD-' . strtoupper(Str::random(8));

        Stripe::setApiKey(config('services.stripe.secret'));

        try {
            DB::beginTransaction();


            $intent = PaymentIntent::create([
                'amount' => $request->amount,
                'currency' => $currency,
                'payment_method' => $request->payment_method_id,
                'confirmation_method' => 'manual',
                'payment_method_types' => ['card'],
                'confirm' => true,
                'capture_method' => 'manual', // ← HOLD payment, don't capture yet
                'metadata' => [
                    'user_id' => $user?->id,
                    'order_number' => $orderNumber,
                    'products' => json_encode($cartItems),
                ],
            ], [
                'idempotency_key' => uniqid('payment_', true)
            ]);

            // 4️⃣ Handle 3D Secure / extra authentication
            if (in_array($intent->status, ['requires_action', 'requires_source_action'])) {
                DB::commit();
                return response()->json([
                    'status' => 'requires_action',
                    'payment_intent_client_secret' => $intent->client_secret
                ]);
            }
            // dd($intent);

            // Payment hold successful → status should be 'requires_capture'
            if ($intent->status !== 'requires_capture') {
                DB::rollBack();
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Payment authorization failed. Status: ' . $intent->status,
                ], 400);
            }

            // Extract risk data from the Charge
            $riskScore = null;
            $riskLevel = null;

            $charge = $intent->latest_charge
                ? \Stripe\Charge::retrieve($intent->latest_charge)
                : null;

            if ($charge && $charge->outcome) {
                $riskScore = $charge->outcome->risk_score ?? null;
                $riskLevel = $charge->outcome->risk_level ?? null;
            }

            // 5️⃣ Payment succeeded → create order
            // $order = Order::create([
            //     'user_id' => $user?->id,
            //     'order_number' => $orderNumber,
            //     'status' => 'paid',
            //     'total_amount' => $totalAmount,
            // ]);

            // // 6️⃣ Create order items
            // foreach ($cartItems as $item) {
            //     $product = Product::find($item['product_id']);
            //     $order->items()->create([
            //         'product_id' => $product->id,
            //         'quantity' => $item['quantity'],
            //         'price' => $product->price,
            //     ]);
            // }

            // // 7️⃣ Store payment record
            // Payment::create([
            //     'order_id' => $order->id,
            //     'stripe_session_id' => $intent->id,
            //     'amount' => $totalAmount,
            //     'payment_status' => 'completed',
            // ]);

            DB::commit();

            return response()->json([
                'status' => 'authorized',
                'message' => 'Payment authorized (on hold). Capture pending.',
                'amount' => $intent->amount,
                'payment_intent_id' => $intent->id,
                'payment_status' => $intent->status,
                'risk_score' => $riskScore,
                'risk_level' => $riskLevel,
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

                $intent = PaymentIntent::retrieve($intentId);

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

                $captured = $intent->capture($captureParams);

                $payment->update([
                    'payment_status' => 'completed',
                ]);

                $storeId = current_store_id();

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
    public function handleWebhook(Request $request)
    {
        $payload = @file_get_contents('php://input');
        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? null;
        $endpoint_secret = env('STRIPE_WEBHOOK_SECRET');

        try {
            $event = Webhook::constructEvent(
                $payload,
                $sig_header,
                $endpoint_secret
            );
        } catch (\Exception $e) {
            \Log::error('Stripe webhook verification failed:', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 400);
        }

        if ($event->type === 'payment_intent.succeeded') {
            $intent = $event->data->object;

            $payment = Payment::where('stripe_session_id', $intent->id)->first();
            if ($payment) {
                $payment->update(['payment_status' => 'completed']);

                $order = $payment->order;
                if ($order) {
                    $order->update(['status' => 'Awaiting Fulfillment']);
                }
            }
        }

        if ($event->type === 'payment_intent.canceled') {
            $intent = $event->data->object;

            $payment = Payment::where('stripe_session_id', $intent->id)->first();
            if ($payment && $payment->payment_status === 'authorized') {
                $payment->update(['payment_status' => 'expired']);

                $order = $payment->order;
                if ($order) {
                    $order->update(['status' => 'Payment Expired']);
                }
            }
        }

        return response()->json(['status' => 'success']);
    }
}
