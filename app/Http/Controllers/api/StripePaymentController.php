<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Stripe\Stripe;
use Stripe\Checkout\Session;
use App\Models\Order;
use App\Models\Payment;

class StripePaymentController extends Controller
{
   public function createCheckoutSession(Request $request)
{
    try {
        \Log::info('Stripe session creation started.');

        $request->validate([
            'orderId' => 'required|exists:orders,id',
        ]);

        $order = \App\Models\Order::findOrFail($request->orderId);
        \Log::info('Stripe Secret ENV:', ['key' => env('STRIPE_SECRET')]);
        \Log::info('Stripe Config:', ['key' => config('services.stripe.secret')]);


        \Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));

        $session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => [
                        'name' => 'Order #' . $order->id,
                    ],
                    'unit_amount' => intval($order->total_amount * 100),
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            // 'success_url' => env('FRONTEND_URL', 'http://localhost:3000') . '/payment-success?session_id={CHECKOUT_SESSION_ID}',
            // 'cancel_url'  => env('FRONTEND_URL', 'http://localhost:3000') . '/payment-cancelled',
            'success_url' => 'https://example.com/success',
            'cancel_url'  => 'https://example.com/cancel',
        ]);

        \App\Models\Payment::create([
            'order_id' => $order->id,
            'stripe_session_id' => $session->id,
            'amount' => $order->total_amount,
            'payment_status' => 'pending',
        ]);

        return response()->json(['id' => $session->id]);

    } catch (\Exception $e) {
        \Log::error('Stripe Checkout Session Error:', [
            'message' => $e->getMessage(),
            'line' => $e->getLine(),
            'file' => $e->getFile(),
        ]);

        return response()->json(['error' => $e->getMessage()], 500);
    }
}

}
