<?php

namespace App\Http\Controllers\api\Web;

use Mail;
use Stripe\Stripe;
use Stripe\Webhook;
use App\Models\User;
use App\Models\Payment;
use Illuminate\Http\Request;
use App\Mail\PaymentInvoiceMail;
use App\Http\Controllers\Controller;

class StripeWebhookController extends Controller
{
    public function handle(Request $request)
    {
        Stripe::setApiKey(env('STRIPE_KEY'));

        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');

        // try {

        //     $event = \Stripe\Webhook::constructEvent(
        //         $payload, $sigHeader, env('STRIPE_WEBHOOK_SECRET')
        //     );
        // }catch (\Exception $e) {
        //     return response()->json([
        //         'error' => 'Invalid Webhook Signature'
        //     ],400);
        // }
        $event = json_decode($payload);
          if (!$event || !isset($event->type) || !isset($event->data->object->id)) {
        return response()->json([
            'error' => 'Invalid Webhook Format',
        ], 400);
    }
        if ($event->type === 'checkout.session.completed') {
        $session = $event->data->object;

        // Find payment
        $payment = \App\Models\Payment::where('stripe_session_id', $session->id)->first();

        if ($payment) {
            $payment->update(['payment_status' => 'paid']);

            // Find related order using session_id
            $order = \App\Models\Order::where('session_id', $session->id)->first();

            if ($order) {
                // Determine customer email
                if ($order->user_id) {
                    $user = User::find($order->user_id);
                    $customerEmail = $user?->email ?? $order->email;
                } else {
                    $customerEmail = $order->email;
                }

                // Send invoice to customer
                Mail::to($customerEmail)->send(new PaymentInvoiceMail($payment));

                // Send invoice to admin
                Mail::to('testing@shiwantek.com')->send(new PaymentInvoiceMail($payment));
            }
        }
    }

        return response()->json([
            'status' => true,
            'message'=>'Receieved Payment'
        ]);
    }
}
