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
                'payment_status' => $intent->status
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
            'payment_intent_id' => 'required|string',
            'amount_to_capture' => 'nullable|integer',
        ]);

        Stripe::setApiKey(config('services.stripe.secret'));

        try {
            $payment = Payment::where('stripe_session_id', $request->payment_intent_id)->first();

            if (!$payment) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Payment record not found.',
                ], 404);
            }

            if ($payment->payment_status === 'completed') {
                return response()->json([
                    'status'  => false,
                    'message' => 'Payment already captured.',
                ], 409);
            }

            $intent = PaymentIntent::retrieve($request->payment_intent_id);

            if ($intent->status !== 'requires_capture') {
                return response()->json([
                    'status'  => false,
                    'message' => 'Payment cannot be captured. Current status: ' . $intent->status,
                ], 400);
            }

            $captureParams = [];
            if ($request->has('amount_to_capture') && $request->amount_to_capture > 0) {
                $captureParams['amount_to_capture'] = $request->amount_to_capture;
            }

            $captured = $intent->capture($captureParams);

            $payment->update([
                'payment_status' => 'completed',
            ]);

            $order = $payment->order;
            if ($order) {
                $order->update(['status' => 'Awaiting Fulfillment']);
            }

            return response()->json([
                'status'          => true,
                'message'         => 'Payment captured successfully.',
                'amount_captured' => $captured->amount_received,
                'payment_status'  => $captured->status,
                'order_id'        => $payment->order_id,
            ]);
        } catch (\Stripe\Exception\CardException $e) {
            return response()->json([
                'status'  => false,
                'message' => $e->getError()->message,
            ], 402);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
            ], 500);
        }
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
