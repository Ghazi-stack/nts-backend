<?php

namespace App\Http\Controllers\api\Web;

use App\Http\Controllers\Controller;
use App\Models\OrderTimeline;
use App\Models\Payment;
use App\Services\TenantCredentialResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Webhook;

class StripeConnectWebhookController extends Controller
{
    public function __construct(private TenantCredentialResolver $tenantResolver) {}

    public function handle(Request $request)
    {
        $secret = config('services.stripe.connect.webhook_secret');

        if (!$secret) {
            Log::error('Stripe Connect webhook secret is not configured.');
            return response()->json(['error' => 'Webhook is not configured.'], 500);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature'),
                $secret
            );
        } catch (\UnexpectedValueException | \Stripe\Exception\SignatureVerificationException $e) {
            return response()->json(['error' => 'Invalid webhook signature or payload.'], 400);
        }

        $accountId = $event->account ?? null;
        if (!$accountId) {
            // This endpoint is exclusively for connected-account events.
            return response()->json(['received' => true]);
        }

        $store = DB::connection('central')
            ->table('stores')
            ->where('stripe_account_id', $accountId)
            ->where('provisioning_status', 'active')
            ->first();

        if (!$store) {
            Log::warning('Stripe Connect webhook account is not mapped to an active store.', [
                'stripe_account_id' => $accountId,
                'event_id' => $event->id,
            ]);
            return response()->json(['received' => true]);
        }

        try {
            $this->tenantResolver->connectByStoreHash($store->store_hash);
            $storeId = (int) ($store->legacy_store_id ?? $store->id);
            $object = $event->data->object ?? null;
            $intentId = $object->id ?? null;

            if ($intentId && str_starts_with($intentId, 'pi_')) {
                $payment = Payment::where('stripe_session_id', $intentId)->first();

                if ($payment) {
                    if ($event->type === 'payment_intent.amount_capturable_updated') {
                        if ($payment->payment_status !== 'completed') {
                            $payment->update(['payment_status' => 'authorized']);
                        }
                    } elseif ($event->type === 'payment_intent.succeeded') {
                        if ($payment->payment_status !== 'completed') {
                            $payment->update(['payment_status' => 'completed']);
                            $order = $payment->order;

                            if ($order) {
                                $previousStatus = $order->status;
                                $order->update(['status' => 'Awaiting Fulfillment']);

                                try {
                                    OrderTimeline::addEvent(
                                        $order->id,
                                        $storeId,
                                        'payment_captured',
                                        'Payment captured successfully.',
                                        [
                                            'source' => 'Stripe Connect',
                                            'payment_intent' => $intentId,
                                            'amount' => '$' . number_format(
                                                (($object->amount_received ?? $object->amount ?? 0) / 100),
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
                                } catch (\Throwable $e) {
                                    Log::warning('Stripe Connect timeline update failed.', [
                                        'event_id' => $event->id,
                                        'error' => $e->getMessage(),
                                    ]);
                                }
                            }
                        }
                    } elseif ($event->type === 'payment_intent.canceled') {
                        if (in_array($payment->payment_status, ['pending', 'authorized'], true)) {
                            $payment->update(['payment_status' => 'expired']);
                            $payment->order?->update(['status' => 'Payment Expired']);
                        }
                    } elseif ($event->type === 'payment_intent.payment_failed') {
                        if ($payment->payment_status !== 'completed') {
                            $payment->update(['payment_status' => 'failed']);
                            $payment->order?->update(['status' => 'Payment Failed']);
                        }
                    }
                }
            }

            return response()->json(['received' => true]);
        } catch (\Throwable $e) {
            Log::error('Stripe Connect webhook processing failed.', [
                'event_id' => $event->id,
                'event_type' => $event->type,
                'stripe_account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Webhook processing failed.'], 500);
        } finally {
            $this->tenantResolver->disconnect();
        }
    }
}
