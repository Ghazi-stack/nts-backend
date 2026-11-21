<?php

namespace App\Services;

use App\Models\StorePaymentMethod;

class StripePaymentSettingsResolver
{
    /**
     * Resolve Stripe settings for a store.
     *
     * This service is intentionally separate from the payment controller.
     * It does not create, authorize, capture, or modify payments.
     */
    public function resolve(int $storeId): array
    {
        $dynamicSettingsEnabled = (bool) config(
            'services.stripe.connect.dynamic_settings_enabled',
            config('services.stripe.dynamic_settings_enabled', false)
        );

        // Preserve the existing payment behavior until explicitly activated.
        if (!$dynamicSettingsEnabled) {
            return [
                'active' => false,
                'reason' => 'dynamic_settings_disabled',
                'storeId' => $storeId,
                'settings' => null,
            ];
        }

        $method = StorePaymentMethod::forStore($storeId)
            ->where('method_code', 'stripe')
            ->first();

        if (!$method) {
            return [
                'active' => false,
                'reason' => 'stripe_method_not_found',
                'storeId' => $storeId,
                'settings' => null,
            ];
        }

        if (!$method->is_enabled || !$method->is_configured) {
            return [
                'active' => false,
                'reason' => 'stripe_not_enabled_or_configured',
                'storeId' => $storeId,
                'settings' => null,
            ];
        }

        $credentials = $method->credentials ?? [];

        if (
            empty($credentials['stripe_user_id']) ||
            empty($credentials['access_token']) ||
            !array_key_exists('livemode', $credentials)
        ) {
            return [
                'active' => false,
                'reason' => 'stripe_connection_credentials_incomplete',
                'storeId' => $storeId,
                'settings' => null,
            ];
        }

        $configuration = $method->configuration ?? [];

        $transactionType = $configuration['transaction_type']
            ?? 'authorize_only';

        // Accept only explicitly supported transaction types.
        if (!in_array(
            $transactionType,
            ['authorize_capture', 'authorize_only'],
            true
        )) {
            $transactionType = 'authorize_only';
        }

        $paymentMethods = $configuration['payment_methods'] ?? [];

        $livemode = (bool) $credentials['livemode'];

        return [
            'active' => true,
            'reason' => null,
            'storeId' => $storeId,
            'environment' => $livemode ? 'live' : 'sandbox',
            'settings' => [
                'transactionType' => $transactionType,
                'testMode' => !$livemode,
                'statementDescriptor' => $configuration['statement_descriptor'] ?? null,
                'createCustomerOnPaymentAttempt' => (bool) (
                    $configuration['create_customer_on_payment_attempt'] ?? false
                ),
                'sendReceiptEmail' => (bool) (
                    $configuration['send_receipt_email'] ?? false
                ),
                'sortPaymentMethodsFirst' => (bool) (
                    $configuration['sort_payment_methods_first'] ?? false
                ),
                'stripeLinkEnabled' => (bool) (
                    $configuration['stripe_link_enabled'] ?? false
                ),
                'adaptivePricingEnabled' => (bool) (
                    $configuration['adaptive_pricing_enabled'] ?? false
                ),
                'savedCardsEnabled' => (bool) (
                    $configuration['saved_cards_enabled'] ?? false
                ),
                'paymentMethods' => [
                    'card' => (bool) ($paymentMethods['card'] ?? true),
                    'applePay' => (bool) ($paymentMethods['apple_pay'] ?? false),
                    'googlePay' => (bool) ($paymentMethods['google_pay'] ?? false),
                    'link' => (bool) ($paymentMethods['link'] ?? false),
                ],
            ],
        ];
    }

    /**
     * Check whether dynamic Stripe settings are active for a store.
     */
    public function isActive(int $storeId): bool
    {
        return $this->resolve($storeId)['active'];
    }
}