<?php

namespace App\Services;

use App\Models\StorePaymentMethod;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaymentSettingsService
{
    /**
     * Retrieve all payment methods for a store.
     */
    public function getMethods(int $storeId)
    {
        return StorePaymentMethod::forStore($storeId)
            ->orderByRaw("
                FIELD(category, 'offline', 'wallet', 'online')
            ")
            ->orderBy('id')
            ->get();
    }

    /**
     * Retrieve one payment method.
     */
    public function getMethod(
        int $storeId,
        string $methodCode
    ): ?StorePaymentMethod {
        return StorePaymentMethod::forStore($storeId)
            ->where('method_code', $methodCode)
            ->first();
    }

    /**
     * Retrieve enabled and configured payment methods.
     */
    public function getActiveMethods(int $storeId)
    {
        return StorePaymentMethod::forStore($storeId)
            ->enabled()
            ->configured()
            ->orderBy('category')
            ->orderBy('id')
            ->get();
    }

    /**
     * Check whether a payment method is enabled
     * and configured for a store.
     */
    public function isEnabled(
        int $storeId,
        string $methodCode
    ): bool {
        return StorePaymentMethod::forStore($storeId)
            ->where('method_code', $methodCode)
            ->where('is_enabled', true)
            ->where('is_configured', true)
            ->exists();
    }

    /**
     * Retrieve non-sensitive configuration.
     */
    public function getConfiguration(
        int $storeId,
        string $methodCode
    ): ?array {
        $method = $this->getMethod($storeId, $methodCode);

        if (!$method) {
            return null;
        }

        return $method->configuration ?? [];
    }

    /**
     * Retrieve decrypted credentials internally.
     *
     * Never expose this response directly through an API.
     */
    public function getCredentials(
        int $storeId,
        string $methodCode
    ): ?array {
        $method = $this->getMethod($storeId, $methodCode);

        if (!$method || !$method->is_configured) {
            return null;
        }

        return $method->credentials ?? [];
    }

    /**
     * Save payment method configuration.
     *
     * Omitted fields are preserved instead of being cleared.
     */
    public function saveConfiguration(
        int $storeId,
        string $methodCode,
        array $data
    ): StorePaymentMethod {

        return DB::connection('tenant')->transaction(
            function () use ($storeId, $methodCode, $data) {

                $method = StorePaymentMethod::forStore($storeId)
                    ->where('method_code', $methodCode)
                    ->lockForUpdate()
                    ->first();

                if (!$method) {
                    throw new RuntimeException(
                        'Payment method not found for this store.'
                    );
                }

                if (array_key_exists('display_name', $data)) {
                    $method->display_name = $data['display_name'];
                }

                if (array_key_exists('available_countries', $data)) {
                    $method->available_countries =
                        $data['available_countries'];
                }

                if (array_key_exists('configuration', $data)) {
                    $existingConfiguration = $method->configuration ?? [];

                    $newConfiguration = $data['configuration'] ?? [];

                    $method->configuration = array_replace_recursive(
                        $existingConfiguration,
                        $newConfiguration
                    );
                }

                if (array_key_exists('credentials', $data)) {
                    $existingCredentials = $method->credentials ?? [];

                    $newCredentials = $data['credentials'] ?? [];

                    $method->credentials = array_merge(
                        $existingCredentials,
                        $newCredentials
                    );
                }

                $method->is_configured = true;

                $method->save();

                return $method->fresh();
            }
        );
    }

    /**
     * Enable a configured payment method.
     */
    public function enableMethod(
        int $storeId,
        string $methodCode
    ): StorePaymentMethod {

        return DB::connection('tenant')->transaction(
            function () use ($storeId, $methodCode) {

                $method = StorePaymentMethod::forStore($storeId)
                    ->where('method_code', $methodCode)
                    ->lockForUpdate()
                    ->first();

                if (!$method) {
                    throw new RuntimeException(
                        'Payment method not found.'
                    );
                }

                if (!$method->is_configured) {
                    throw new RuntimeException(
                        'Please configure this payment method before enabling it.'
                    );
                }

                $method->is_enabled = true;
                $method->save();

                return $method->fresh();
            }
        );
    }

    /**
     * Disable a payment method.
     *
     * Existing credentials and configuration remain intact.
     */
    public function disableMethod(
        int $storeId,
        string $methodCode
    ): StorePaymentMethod {

        $method = StorePaymentMethod::forStore($storeId)
            ->where('method_code', $methodCode)
            ->first();

        if (!$method) {
            throw new RuntimeException(
                'Payment method not found.'
            );
        }

        $method->is_enabled = false;
        $method->save();

        return $method->fresh();
    }


    /**
     * Save credentials returned by Stripe Connect OAuth.
     * Credentials are encrypted by the StorePaymentMethod model cast.
     */
    public function connectStripeAccount(
        int $storeId,
        array $oauthData
    ): StorePaymentMethod {
        return DB::connection('tenant')->transaction(
            function () use ($storeId, $oauthData) {
                $method = StorePaymentMethod::forStore($storeId)
                    ->where('method_code', 'stripe')
                    ->lockForUpdate()
                    ->first();

                if (!$method) {
                    throw new RuntimeException(
                        'Stripe payment method not found for this store.'
                    );
                }

                $method->credentials = array_merge(
                    $method->credentials ?? [],
                    [
                        'connection_type' => 'standard',
                        'stripe_user_id' => $oauthData['stripe_user_id'],
                        'access_token' => $oauthData['access_token'],
                        'refresh_token' => $oauthData['refresh_token'] ?? null,
                        'publishable_key' => $oauthData['stripe_publishable_key'] ?? null,
                        'scope' => $oauthData['scope'] ?? 'read_write',
                        'token_type' => $oauthData['token_type'] ?? 'bearer',
                        'livemode' => (bool) ($oauthData['livemode'] ?? false),
                    ]
                );

                $method->configuration = array_replace_recursive(
                    $method->configuration ?? [],
                    [
                        'connection_type' => 'standard',
                        'account_id' => $oauthData['stripe_user_id'],
                        'livemode' => (bool) ($oauthData['livemode'] ?? false),
                    ]
                );

                $method->is_configured = true;

                // Do not automatically enable payments.
                // Merchant can enable Stripe from Payment Settings.
                $method->save();

                return $method->fresh();
            }
        );
    }

    /**
     * Remove stored Stripe connection credentials.
     */
    public function disconnectStripeAccount(int $storeId): void
    {
        DB::connection('tenant')->transaction(
            function () use ($storeId) {
                $method = StorePaymentMethod::forStore($storeId)
                    ->where('method_code', 'stripe')
                    ->lockForUpdate()
                    ->first();

                if (!$method) {
                    throw new RuntimeException(
                        'Stripe payment method not found.'
                    );
                }

                $method->credentials = null;
                $method->configuration = null;
                $method->is_enabled = false;
                $method->is_configured = false;
                $method->save();
            }
        );
    }

    /**
     * Return safe Stripe connection details.
     * Never return access or refresh tokens.
     */
    public function getStripeConnectionStatus(int $storeId): array
    {
        $method = $this->getMethod($storeId, 'stripe');

        $credentials = $method?->credentials ?? [];

        $connected = !empty($credentials['stripe_user_id'])
            && !empty($credentials['access_token']);

        return [
            'connected' => $connected,
            'account_id' => $connected
                ? $credentials['stripe_user_id']
                : null,
            'livemode' => $connected
                ? (bool) ($credentials['livemode'] ?? false)
                : null,
            'is_enabled' => (bool) ($method?->is_enabled ?? false),
            'is_configured' => (bool) ($method?->is_configured ?? false),
        ];
    }

}