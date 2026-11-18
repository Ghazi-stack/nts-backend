<?php

namespace App\Http\Controllers\api\Admin;

use App\Http\Controllers\Controller;
use App\Models\StorePaymentMethod;
use App\Models\StoreStripeWebhookEndpoint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Illuminate\Support\Str;

class StripePaymentSettingsController extends Controller
{

    private function toCamelCase(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $result[Str::camel($key)] = is_array($value)
                ? $this->toCamelCase($value)
                : $value;
        }

        return $result;
    }
    private function storeId(): int
    {
        $storeId = (int) current_store_id();

        if (!$storeId) {
            abort(response()->json([
                'status' => false,
                'message' => 'Store not selected.',
            ], 400));
        }

        return $storeId;
    }

    private function stripeMethod(int $storeId): StorePaymentMethod
    {
        return StorePaymentMethod::forStore($storeId)
            ->where('method_code', 'stripe')
            ->firstOrFail();
    }

    private function defaultConfiguration(): array
    {
        return config('stripe_payment.defaults', []);
    }

    /**
     * GET Stripe settings.
     */
    public function showConfiguration()
    {
        $method = $this->stripeMethod($this->storeId());

        $configuration = array_replace_recursive(
            $this->defaultConfiguration(),
            $method->configuration ?? []
        );

        return response()->json([
            'status' => true,
            'data' => [
                'methodCode' => 'stripe',
                'displayName' => $method->display_name,
                'isEnabled' => (bool) $method->is_enabled,
                'isConfigured' => (bool) $method->is_configured,
                'dynamicSettingsActive' => (bool) config(
                    'services.stripe.dynamic_settings_enabled',
                    false
                ),
                'configuration' => $this->toCamelCase($configuration),
                'resolvedSettings' => app(
                    \App\Services\PaymentSettingsService::class
                )->getResolvedStripeSettings($this->storeId()),
                'connection' => [
                    'connected' => !empty(
                        $method->credentials['stripe_user_id'] ?? null
                    ),
                    'accountId' => $method->credentials['stripe_user_id'] ?? null,
                    'livemode' => $method->credentials['livemode'] ?? null,
                ],
            ],
        ]);
    }

    /**
     * PUT Stripe settings.
     */
    public function updateConfiguration(Request $request)
    {
        $storeId = $this->storeId();

        $validator = Validator::make($request->all(), [
            'configuration' => [
                'required',
                'array:transactionType,testMode,statementDescriptor,createCustomerOnPaymentAttempt,sendReceiptEmail,sortPaymentMethodsFirst,stripeLinkEnabled,adaptivePricingEnabled,savedCardsEnabled,paymentMethods',
            ],
            'configuration.paymentMethods' => [
                'sometimes',
                'array:card,applePay,googlePay,link',
            ],

            'configuration.transactionType' =>
                'sometimes|in:authorize_capture,authorize_only',

            'configuration.testMode' => 'sometimes|boolean',

            'configuration.statementDescriptor' =>
                'sometimes|nullable|string|max:22',

            'configuration.createCustomerOnPaymentAttempt' =>
                'sometimes|boolean',

            'configuration.sendReceiptEmail' => 'sometimes|boolean',

            'configuration.sortPaymentMethodsFirst' =>
                'sometimes|boolean',

            'configuration.stripeLinkEnabled' => 'sometimes|boolean',

            'configuration.adaptivePricingEnabled' =>
                'sometimes|boolean',

            'configuration.savedCardsEnabled' => 'sometimes|boolean',

            'configuration.paymentMethods' => 'sometimes|array',

            'configuration.paymentMethods.card' => 'sometimes|boolean',
            'configuration.paymentMethods.applePay' => 'sometimes|boolean',
            'configuration.paymentMethods.googlePay' => 'sometimes|boolean',
            'configuration.paymentMethods.link' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $input = $validator->validated()['configuration'];

        $keyMap = [
            'transactionType' => 'transaction_type',
            'testMode' => 'test_mode',
            'statementDescriptor' => 'statement_descriptor',
            'createCustomerOnPaymentAttempt' => 'create_customer_on_payment_attempt',
            'sendReceiptEmail' => 'send_receipt_email',
            'sortPaymentMethodsFirst' => 'sort_payment_methods_first',
            'stripeLinkEnabled' => 'stripe_link_enabled',
            'adaptivePricingEnabled' => 'adaptive_pricing_enabled',
            'savedCardsEnabled' => 'saved_cards_enabled',
            'paymentMethods' => 'payment_methods',
            'applePay' => 'apple_pay',
            'googlePay' => 'google_pay',
        ];

        $normalized = $this->normalizeKeys($input, $keyMap);

        $method = $this->stripeMethod($storeId);

        $method->configuration = array_replace_recursive(
            $method->configuration ?? [],
            $normalized
        );

        $method->save();

        return $this->showConfiguration();
    }

    private function normalizeKeys(array $data, array $keyMap): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $normalizedKey = $keyMap[$key] ?? $key;

            $result[$normalizedKey] = is_array($value)
                ? $this->normalizeKeys($value, $keyMap)
                : $value;
        }

        return $result;
    }

    /**
     * GET webhook configuration.
     * Never return the signing secret.
     */
    public function showWebhook()
    {
        $storeId = $this->storeId();

        $endpoints = StoreStripeWebhookEndpoint::forStore($storeId)
            ->orderBy('environment')
            ->get();

        $data = collect(config('stripe_payment.environments', ['test', 'live']))
            ->map(function ($environment) use ($endpoints) {
                $endpoint = $endpoints->firstWhere(
                    'environment',
                    $environment
                );

                return [
                    'environment' => $environment,
                    'endpointId' => $endpoint?->stripe_endpoint_id,
                    'endpointUrl' => $endpoint?->endpoint_url,
                    'enabledEvents' => $endpoint?->enabled_events ?? [],
                    'isEnabled' => (bool) ($endpoint?->is_enabled ?? false),
                    'isVerified' => (bool) ($endpoint?->is_verified ?? false),
                    'hasSigningSecret' => !empty(
                        $endpoint?->signing_secret
                    ),
                    'lastEventAt' => $endpoint?->last_event_at,
                    'lastEventType' => $endpoint?->last_event_type,
                    'lastEventStatus' => $endpoint?->last_event_status,
                    'lastError' => $endpoint?->last_error,
                ];
            })
            ->values();

        return response()->json([
            'status' => true,
            'data' => $data,
        ]);
    }

    /**
     * PUT webhook configuration for one environment.
     *
     * The signing secret is write-only.
     */
    public function saveWebhook(Request $request, string $environment)
    {
        if (!in_array($environment, ['test', 'live'], true)) {
            return response()->json([
                'status' => false,
                'message' => 'Environment must be test or live.',
            ], 422);
        }

        $validated = $request->validate([
            'endpointId' => 'nullable|string|max:255',
            'endpointUrl' => 'required|url|max:2048',
            'signingSecret' => 'nullable|string|max:500',
            'enabledEvents' => 'required|array|min:1',
            'enabledEvents.*' => 'required|string|max:150',
            'isEnabled' => 'sometimes|boolean',
        ]);

        $allowedEvents = config(
            'stripe_payment.initial_webhook_events',
            []
        );

        $invalidEvents = array_diff(
            $validated['enabledEvents'],
            $allowedEvents
        );

        if (!empty($invalidEvents)) {
            return response()->json([
                'status' => false,
                'message' => 'One or more webhook events are not supported.',
                'invalidEvents' => array_values($invalidEvents),
            ], 422);
        }

        $storeId = $this->storeId();

        if (
            ($validated['isEnabled'] ?? false) &&
            empty($validated['signingSecret']) &&
            !StoreStripeWebhookEndpoint::forStore($storeId)
                ->where('environment', $environment)
                ->value('signing_secret')
        ) {
            return response()->json([
                'status' => false,
                'message' => 'A webhook signing secret is required before enabling this endpoint.',
            ], 422);
        }

        $endpoint = DB::connection('tenant')->transaction(
            function () use ($storeId, $environment, $validated) {
                $endpoint = StoreStripeWebhookEndpoint::forStore($storeId)
                    ->where('environment', $environment)
                    ->lockForUpdate()
                    ->first();

                if (!$endpoint) {
                    $endpoint = new StoreStripeWebhookEndpoint();
                    $endpoint->store_id = $storeId;
                    $endpoint->environment = $environment;
                }

                $endpoint->stripe_endpoint_id =
                    $validated['endpointId'] ?? null;

                $endpoint->endpoint_url = $validated['endpointUrl'];

                $endpoint->enabled_events =
                    array_values(array_unique($validated['enabledEvents']));

                if (!empty($validated['signingSecret'])) {
                    $endpoint->signing_secret =
                        $validated['signingSecret'];
                }

                if (array_key_exists('isEnabled', $validated)) {
                    $endpoint->is_enabled = $validated['isEnabled'];
                }

                // Saving a secret is not proof that Stripe verified it.
                $endpoint->is_verified = false;

                $endpoint->last_error = null;

                $endpoint->save();

                return $endpoint;
            }
        );

        return response()->json([
            'status' => true,
            'message' => 'Webhook configuration saved.',
            'data' => [
                'environment' => $endpoint->environment,
                'endpointId' => $endpoint->stripe_endpoint_id,
                'endpointUrl' => $endpoint->endpoint_url,
                'enabledEvents' => $endpoint->enabled_events,
                'isEnabled' => $endpoint->is_enabled,
                'isVerified' => $endpoint->is_verified,
                'hasSigningSecret' => !empty($endpoint->signing_secret),
            ],
        ]);
    }
}
