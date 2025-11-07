<?php

namespace App\Http\Controllers\api\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\Webhook;
use App\Models\Order;
use App\Models\Payment;

class StripePaymentController extends Controller
{
    /**
     * 🧱 1️⃣ Create a PaymentIntent
     * Frontend will call this when user proceeds to pay
     */
    public function createPaymentIntent(Request $request)
    {
        try {
            $request->validate([
                'orderId' => 'required|exists:orders,id',
            ]);

            $order = Order::findOrFail($request->orderId);

            Stripe::setApiKey(env('STRIPE_SECRET'));

            // Create PaymentIntent in Stripe
            $intent = PaymentIntent::create([
                'amount' => intval($order->total_amount * 100), // cents
                'currency' => 'usd',
                'metadata' => [
                    'order_id' => $order->id,
                ],
            ]);

            // Store PaymentIntent ID in local DB
            Payment::create([
                'order_id' => $order->id,
                'stripe_session_id' => $intent->id,
                'amount' => $order->total_amount,
                'payment_status' => 'pending',
            ]);

            return response()->json([
                'clientSecret' => $intent->client_secret,
            ]);
        } catch (\Exception $e) {
            \Log::error('Stripe PaymentIntent error:', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * 🧾 2️⃣ Check Payment Status (optional)
     * Frontend can call this after payment attempt
     */
    public function checkPaymentStatus($orderId)
    {
        try {
            $order = Order::findOrFail($orderId);
            Stripe::setApiKey(env('STRIPE_SECRET'));

            $intent = PaymentIntent::retrieve($order->stripe_payment_intent);

            return response()->json([
                'status' => $intent->status,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * 📩 3️⃣ Stripe Webhook
     * Stripe will call this automatically after successful payment
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

        // Handle successful payment
        if ($event->type === 'payment_intent.succeeded') {
            $intent = $event->data->object;
            $orderId = $intent->metadata->order_id ?? null;

            if ($orderId) {
                $order = Order::find($orderId);
                if ($order) {
                    $order->update(['status' => 'paid']);
                   Payment::where('stripe_session_id', $intent->id)
                            ->update(['payment_status' => 'completed']);
                }
            }
        }

        return response()->json(['status' => 'success']);
    }
}
