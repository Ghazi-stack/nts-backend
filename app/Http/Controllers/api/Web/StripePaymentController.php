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
     * 💳 Pay and create order only after payment
     */
    public function pay(Request $request)
    {
        // \Log::info('Stripe Secret:', ['stripe_secret' => env('STRIPE_SECRET')]);
        $request->validate([
            'payment_method_id' => 'required|string',
            'products'         => 'required|array|min:1',
            'products.*.product_id' => 'required|integer|exists:products,id',
            'products.*.quantity'   => 'required|integer|min:1',
        ]);

        $user = auth('sanctum')->user();
        $cartItems = $request->products;

        $totalAmount = 0;
        foreach ($cartItems as $item) {
            $product = Product::find($item['product_id']);
            $totalAmount += $product->price * $item['quantity'];
        }
        $currency = 'usd';

        // 2️⃣ Generate order number
        $orderNumber = 'ORD-' . strtoupper(Str::random(8));

        Stripe::setApiKey('sk_test_51Q84ITDXm8Pt3arONFu7h0WRrTdN5GUpTIeWXWc6KkIGo6LbrgFpIhAs8U0x7zDJj9igFanjYQqK1nDkqhvToAhw00sAOUIOWQ');

        try {
            DB::beginTransaction();

         
            $intent = PaymentIntent::create([
                'amount' => intval($totalAmount * 100), // cents
                'currency' => $currency,
                'payment_method' => $request->payment_method_id,
                'confirmation_method' => 'manual',
                'payment_method_types' => ['card'], // Only allow cards
                'confirm' => true,
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

            // 5️⃣ Payment succeeded → create order
            $order = Order::create([
                'user_id' => $user?->id,
                'order_number' => $orderNumber,
                'status' => 'paid',
                'total_amount' => $totalAmount,
            ]);

            // 6️⃣ Create order items
            foreach ($cartItems as $item) {
                $product = Product::find($item['product_id']);
                $order->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $item['quantity'],
                    'price' => $product->price,
                ]);
            }

            // 7️⃣ Store payment record
            Payment::create([
                'order_id' => $order->id,
                'stripe_session_id' => $intent->id,
                'amount' => $totalAmount,
                'payment_status' => 'completed',
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'order_id' => $order->id,
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

            // Find payment record by Stripe PaymentIntent ID
            $payment = Payment::where('stripe_session_id', $intent->id)->first();
            if ($payment) {
                $payment->update(['payment_status' => 'completed']);

                // Update order status
                $order = $payment->order;
                if ($order) {
                    $order->update(['status' => 'paid']);
                }
            }
        }

        return response()->json(['status' => 'success']);
    }
}
