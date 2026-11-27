<?php

namespace App\Services;

use App\Models\StorePaymentMethod;
use App\Models\StorePaymentMethodProvider;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaymentSettingsService
{
    private const CATEGORY_ORDER = ['online', 'wallet', 'offline'];

    public function getMethods(int $storeId)
    {
        return StorePaymentMethod::forStore($storeId)
            ->orderByRaw("FIELD(category, 'online', 'wallet', 'offline')")
            ->orderBy('id')
            ->get();
    }

    public function getMethod(int $storeId, string $methodCode): ?StorePaymentMethod
    {
        return StorePaymentMethod::forStore($storeId)
            ->where('method_code', $methodCode)
            ->first();
    }

    public function getActiveMethods(int $storeId)
    {
        return StorePaymentMethod::forStore($storeId)
            ->enabled()
            ->configured()
            ->orderByRaw("FIELD(category, 'online', 'wallet', 'offline')")
            ->orderBy('id')
            ->get();
    }

    /**
     * Resolve either the legacy store-wide settings or one currency's settings.
     * The internal currency map is never returned as part of configuration.
     */
    public function getEffectiveSettings(
        StorePaymentMethod $method,
        ?string $currency = null
    ): array {
        $storedConfiguration = $method->configuration ?? [];
        $currencySettings = $storedConfiguration['currencySettings'] ?? [];

        if ($currency !== null) {
            $currency = strtoupper(trim($currency));

            if (is_array($currencySettings)
                && array_key_exists($currency, $currencySettings)) {
                $entry = is_array($currencySettings[$currency])
                    ? $currencySettings[$currency]
                    : [];
                $configuration = $entry;
                unset($configuration['isEnabled'], $configuration['isConfigured']);
                if ($method->method_code === 'stripe') {
                    unset(
                        $configuration['connection_type'],
                        $configuration['account_id'],
                        $configuration['livemode']
                    );
                }

                return [
                    'isEnabled' => (bool) ($entry['isEnabled'] ?? false),
                    'isConfigured' => array_key_exists('isConfigured', $entry)
                        ? (bool) $entry['isConfigured']
                        : $this->isConfigurationComplete(
                            $method->method_code,
                            $configuration,
                            $method->credentials ?? []
                        ),
                    'configuration' => $configuration,
                    'isCurrencySpecific' => true,
                ];
            }
        }

        unset($storedConfiguration['currencySettings']);

        return [
            'isEnabled' => (bool) $method->is_enabled,
            'isConfigured' => (bool) $method->is_configured,
            'configuration' => $storedConfiguration,
            'isCurrencySpecific' => false,
        ];
    }

    /**
     * Return enabled, configured methods that match storefront filters.
     * A missing filter does not make a method unavailable; configured
     * restrictions are applied when the shopper supplies that dimension.
     */
    public function getStorefrontMethods(
        int $storeId,
        ?string $currency = null,
        ?string $country = null
    ) {
        $currency = $currency !== null ? strtoupper(trim($currency)) : null;
        $country = $country !== null ? strtoupper(trim($country)) : null;

        // Keep this query portable across supported tenant database drivers;
        // the dashboard's getActiveMethods currently orders with MySQL FIELD().
        return StorePaymentMethod::forStore($storeId)
            ->orderBy('id')
            ->get()
            ->filter(function (StorePaymentMethod $method) use ($currency, $country) {
                $effective = $this->getEffectiveSettings($method, $currency);

                if (!$effective['isEnabled'] || !$effective['isConfigured']) {
                    return false;
                }

                if ($currency !== null) {
                    $currencies = $method->available_currencies;

                    if (!is_array($currencies)) {
                        return false;
                    }

                    $currencies = array_map(
                        fn ($code) => strtoupper(trim((string) $code)),
                        $currencies
                    );

                    if (!in_array('*', $currencies, true)
                        && !in_array($currency, $currencies, true)) {
                        return false;
                    }
                }

                if ($country !== null) {
                    $availability = $method->available_countries;

                    if (is_array($availability)
                        && !($availability['all_countries'] ?? false)) {
                        $countries = array_map(
                            fn ($code) => strtoupper(trim((string) $code)),
                            $availability['countries'] ?? []
                        );

                        if (!in_array($country, $countries, true)) {
                            return false;
                        }
                    }
                }

                return true;
            })
            ->sortBy(fn (StorePaymentMethod $method) => array_search(
                $method->category,
                self::CATEGORY_ORDER,
                true
            ))
            ->values();
    }

    // public function getGroupedMethods(int $storeId): array
    // {
    //     $methods = $this->getMethods($storeId);

    //     $grouped = [];

    //     foreach (self::CATEGORY_ORDER as $category) {
    //         $grouped[$category] = $methods
    //             ->where('category', $category)
    //             ->values();
    //     }

    //     return $grouped;
    // }

    
    public function getGroupedMethods(
        int $storeId,
        ?string $currency = null
    ): array {
        $methods = $this->getMethods($storeId);

        // Without a selected currency, show offline methods only.
        if ($currency === null || trim($currency) === '') {
            $methods = $methods
                ->where('category', 'offline')
                ->values();
        } else {
            $currency = strtoupper(trim($currency));

            $methods = $methods->filter(function ($method) use ($currency) {
                $availableCurrencies = $method->available_currencies;

                if (!is_array($availableCurrencies)) {
                    return false;
                }

                $availableCurrencies = array_map(
                    fn ($code) => strtoupper(trim((string) $code)),
                    $availableCurrencies
                );

                // Wildcard means available for every currency.
                if (in_array('*', $availableCurrencies, true)) {
                    return true;
                }

                return in_array($currency, $availableCurrencies, true);
            })->values();
        }

        $grouped = [];

        foreach (self::CATEGORY_ORDER as $category) {
            $grouped[$category] = $methods
                ->where('category', $category)
                ->values();
        }

        return $grouped;
    }

    public function getCatalog(): array
    {
        return config('payment_methods.methods', []);
    }

    public function getCatalogMethod(string $methodCode): ?array
    {
        $catalog = $this->getCatalog();

        return $catalog[$methodCode] ?? null;
    }

    public function isEnabled(
        int $storeId,
        string $methodCode,
        ?string $currency = null
    ): bool
    {
        $method = $this->getMethod($storeId, $methodCode);

        if (!$method) {
            return false;
        }

        $effective = $this->getEffectiveSettings($method, $currency);

        return $effective['isEnabled'] && $effective['isConfigured'];
    }

    public function getConfiguration(
        int $storeId,
        string $methodCode,
        ?string $currency = null
    ): ?array
    {
        $method = $this->getMethod($storeId, $methodCode);

        return $method
            ? $this->getEffectiveSettings($method, $currency)['configuration']
            : null;
    }

    public function getCredentials(int $storeId, string $methodCode): ?array
    {
        $method = $this->getMethod($storeId, $methodCode);

        if (!$method || !$method->is_configured) {
            return null;
        }

        return $method->credentials ?? [];
    }

    /**
     * Determine whether the method has completed its required setup.
     * Credentials are deliberately not considered complete merely
     * because an arbitrary credentials array was submitted.
     */
    private function isConfigurationComplete(
        string $methodCode,
        array $configuration,
        array $credentials
    ): bool {
        $requiredFields = [
            'bank_deposit' => ['account_information'],
            'cash_on_delivery' => ['delivery_information'],
            'check' => ['payment_information'],
            'money_order' => ['payment_information'],
            'pay_in_store' => ['payment_information'],
        ];

        if (isset($requiredFields[$methodCode])) {
            foreach ($requiredFields[$methodCode] as $field) {
                if (empty(trim((string) ($configuration[$field] ?? '')))) {
                    return false;
                }
            }

            return true;
        }

        // Stripe is configured through Stripe Connect OAuth.
        if ($methodCode === 'stripe') {
            return !empty($credentials['stripe_user_id'])
                && !empty($credentials['access_token']);
        }

        // PayPal is not considered configured until its credentials
        // have been supplied. Actual API verification is a later step.
        if ($methodCode === 'paypal') {
            return !empty($credentials['client_id'])
                && !empty($credentials['client_secret']);
        }

        // Wallets require a verified provider, not just a saved form.
        if (in_array($methodCode, ['apple_pay', 'google_pay'], true)) {
            return false;
        }

        return false;
    }

    // public function saveConfiguration(
    //     int $storeId,
    //     string $methodCode,
    //     array $data
    // ): StorePaymentMethod {
    //     return DB::connection('tenant')->transaction(function () use (
    //         $storeId,
    //         $methodCode,
    //         $data
    //     ) {
    //         $method = StorePaymentMethod::forStore($storeId)
    //             ->where('method_code', $methodCode)
    //             ->lockForUpdate()
    //             ->first();

    //         if (!$method) {
    //             throw new RuntimeException(
    //                 'Payment method not found for this store.'
    //             );
    //         }

    //         if (array_key_exists('display_name', $data)) {
    //             $method->display_name = $data['display_name'];
    //         }

    //         if (array_key_exists('available_countries', $data)) {
    //             $method->available_countries = $data['available_countries'];
    //         }

    //         if (array_key_exists('configuration', $data)) {
    //             $method->configuration = array_replace_recursive(
    //                 $method->configuration ?? [],
    //                 $data['configuration'] ?? []
    //             );
    //         }

    //         if (array_key_exists('credentials', $data)) {
    //             $method->credentials = array_merge(
    //                 $method->credentials ?? [],
    //                 $data['credentials'] ?? []
    //             );
    //         }

    //         $method->is_configured = $this->isConfigurationComplete(
    //             $methodCode,
    //             $method->configuration ?? [],
    //             $method->credentials ?? []
    //         );

    //         // Do not silently leave an enabled method active if its
    //         // required configuration has become incomplete.
    //         if (!$method->is_configured) {
    //             $method->is_enabled = false;
    //         }

    //         $method->save();

    //         return $method->fresh();
    //     });
    // }

    public function saveConfiguration(
        int $storeId,
        string $methodCode,
        array $data
    ): StorePaymentMethod {
        return DB::connection('tenant')->transaction(function () use (
            $storeId,
            $methodCode,
            $data
        ) {
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
                $method->available_countries = $data['available_countries'];
            }

            // Save supported currency codes.
            if (array_key_exists('available_currencies', $data)) {
                $method->available_currencies = $data['available_currencies'];
            }

            if (array_key_exists('configuration', $data)) {
                $existingCurrencySettings = $method->configuration['currencySettings'] ?? null;
                $method->configuration = array_replace_recursive(
                    $method->configuration ?? [],
                    array_diff_key($data['configuration'] ?? [], [
                        'currencySettings' => true,
                    ])
                );

                if (is_array($existingCurrencySettings)) {
                    $method->configuration['currencySettings'] = $existingCurrencySettings;
                }
            }

            if (array_key_exists('credentials', $data)) {
                $method->credentials = array_merge(
                    $method->credentials ?? [],
                    $data['credentials'] ?? []
                );
            }

            $method->is_configured = $this->isConfigurationComplete(
                $methodCode,
                $method->configuration ?? [],
                $method->credentials ?? []
            );

            // Automatically enable when configuration is complete.
            // Keep incomplete payment methods disabled.
            $method->is_enabled = $method->is_configured;

            $method->save();

            return $method->fresh();
        });
    }

    /** Save configuration for one currency without modifying global state. */
    public function saveCurrencyConfiguration(
        int $storeId,
        string $methodCode,
        string $currency,
        array $configuration
    ): StorePaymentMethod {
        $currency = strtoupper(trim($currency));

        return DB::connection('tenant')->transaction(function () use (
            $storeId,
            $methodCode,
            $currency,
            $configuration
        ) {
            $method = StorePaymentMethod::forStore($storeId)
                ->where('method_code', $methodCode)
                ->lockForUpdate()
                ->first();

            if (!$method) {
                throw new RuntimeException('Payment method not found for this store.');
            }

            $stored = $method->configuration ?? [];
            $currencySettings = $stored['currencySettings'] ?? [];
            $currencySettings = is_array($currencySettings) ? $currencySettings : [];
            $hasCurrencyEntry = array_key_exists($currency, $currencySettings);

            if ($hasCurrencyEntry && is_array($currencySettings[$currency])) {
                $entry = $currencySettings[$currency];
                $wasEnabled = (bool) ($entry['isEnabled'] ?? false);
                unset($entry['isEnabled'], $entry['isConfigured']);
            } else {
                $entry = $stored;
                unset($entry['currencySettings']);
                $wasEnabled = (bool) $method->is_enabled;
            }

            if ($methodCode === 'stripe') {
                unset(
                    $entry['connection_type'],
                    $entry['account_id'],
                    $entry['livemode']
                );
            }

            $configuration = array_diff_key($configuration, [
                'currencySettings' => true,
                'isEnabled' => true,
                'isConfigured' => true,
            ]);

            $entry = array_replace_recursive($entry, $configuration);
            $isConfigured = $this->isConfigurationComplete(
                $methodCode,
                $entry,
                $method->credentials ?? []
            );

            // A new currency starts with legacy enablement. A complete first
            // setup follows the legacy save behavior and enables that currency.
            $isEnabled = $hasCurrencyEntry
                ? $wasEnabled
                : ($wasEnabled || $isConfigured);

            if (!$isConfigured) {
                $isEnabled = false;
            }

            $currencySettings[$currency] = array_merge(
                $entry,
                [
                    'isEnabled' => $isEnabled,
                    'isConfigured' => $isConfigured,
                ]
            );
            $stored['currencySettings'] = $currencySettings;

            $method->configuration = $stored;
            $method->save();

            return $method->fresh();
        });
    }

    /** Enable one configured currency, preserving every other entry. */
    public function enableCurrency(
        int $storeId,
        string $methodCode,
        string $currency
    ): StorePaymentMethod {
        $currency = strtoupper(trim($currency));

        return DB::connection('tenant')->transaction(function () use (
            $storeId,
            $methodCode,
            $currency
        ) {
            $method = StorePaymentMethod::forStore($storeId)
                ->where('method_code', $methodCode)
                ->lockForUpdate()
                ->first();

            if (!$method) {
                throw new RuntimeException('Payment method not found.');
            }

            $stored = $method->configuration ?? [];
            $currencySettings = $stored['currencySettings'] ?? [];
            $currencySettings = is_array($currencySettings) ? $currencySettings : [];

            if (array_key_exists($currency, $currencySettings)
                && is_array($currencySettings[$currency])) {
                $entry = $currencySettings[$currency];
                unset($entry['isEnabled'], $entry['isConfigured']);
            } else {
                $entry = $stored;
                unset($entry['currencySettings']);
            }

            if ($methodCode === 'stripe') {
                unset(
                    $entry['connection_type'],
                    $entry['account_id'],
                    $entry['livemode']
                );
            }

            if (!$this->isConfigurationComplete(
                $methodCode,
                $entry,
                $method->credentials ?? []
            )) {
                throw new RuntimeException(
                    'Please complete the required configuration for this currency before enabling this payment method.'
                );
            }

            $currencySettings[$currency] = array_merge(
                $entry,
                ['isEnabled' => true, 'isConfigured' => true]
            );
            $stored['currencySettings'] = $currencySettings;
            $method->configuration = $stored;
            $method->save();

            return $method->fresh();
        });
    }

    /** Disable one currency without clearing shared provider credentials. */
    public function disableCurrency(
        int $storeId,
        string $methodCode,
        string $currency
    ): StorePaymentMethod {
        $currency = strtoupper(trim($currency));

        return DB::connection('tenant')->transaction(function () use (
            $storeId,
            $methodCode,
            $currency
        ) {
            $method = StorePaymentMethod::forStore($storeId)
                ->where('method_code', $methodCode)
                ->lockForUpdate()
                ->first();

            if (!$method) {
                throw new RuntimeException('Payment method not found.');
            }

            $stored = $method->configuration ?? [];
            $currencySettings = $stored['currencySettings'] ?? [];
            $currencySettings = is_array($currencySettings) ? $currencySettings : [];
            $currencySettings[$currency] = [
                'isEnabled' => false,
                'isConfigured' => false,
            ];
            $stored['currencySettings'] = $currencySettings;

            $method->configuration = $stored;
            $method->save();

            return $method->fresh();
        });
    }

    public function enableMethod(
        int $storeId,
        string $methodCode
    ): StorePaymentMethod {
        return DB::connection('tenant')->transaction(function () use (
            $storeId,
            $methodCode
        ) {
            $method = StorePaymentMethod::forStore($storeId)
                ->where('method_code', $methodCode)
                ->lockForUpdate()
                ->first();

            if (!$method) {
                throw new RuntimeException('Payment method not found.');
            }

            if (!$method->is_configured) {
                throw new RuntimeException(
                    'Please complete the required configuration before enabling this payment method.'
                );
            }

            $method->is_enabled = true;
            $method->save();

            return $method->fresh();
        });
    }

    // public function disableMethod(
    //     int $storeId,
    //     string $methodCode
    // ): StorePaymentMethod {
    //     $method = $this->getMethod($storeId, $methodCode);

    //     if (!$method) {
    //         throw new RuntimeException('Payment method not found.');
    //     }

    //     $method->is_enabled = false;
    //     $method->save();

    //     return $method->fresh();
    // }


    
    public function disableMethod(
        int $storeId,
        string $methodCode
    ): StorePaymentMethod {
        return DB::connection('tenant')->transaction(function () use (
            $storeId,
            $methodCode
        ) {
            $method = StorePaymentMethod::forStore($storeId)
                ->where('method_code', $methodCode)
                ->lockForUpdate()
                ->first();

            if (!$method) {
                throw new RuntimeException('Payment method not found.');
            }

            // Stripe must be disconnected through OAuth first.
            if ($methodCode === 'stripe') {
                throw new RuntimeException(
                    'Stripe must be disconnected through the Stripe OAuth disconnect flow.'
                );
            }

            // Clear configuration for every non-Stripe payment method.
            $method->configuration = null;
            $method->credentials = null;
            $method->is_configured = false;
            $method->is_enabled = false;

            $method->save();

            return $method->fresh();
        });
    }



    /**
     * Return wallet provider options from the catalog and tenant records.
     * Availability is never inferred just from catalog support.
     */
    public function getWalletProviders(
        int $storeId,
        string $methodCode
    ): array {
        $catalogMethod = $this->getCatalogMethod($methodCode);

        if (
            !$catalogMethod ||
            !in_array($methodCode, ['apple_pay', 'google_pay'], true)
        ) {
            throw new RuntimeException('Wallet payment method not found.');
        }

        $supportedProviders = $catalogMethod['supportedProviders'] ?? [];

        $records = StorePaymentMethodProvider::forStore($storeId)
            ->where('method_code', $methodCode)
            ->get()
            ->keyBy('provider_code');

        $result = [];

        foreach ($supportedProviders as $providerCode) {
            $record = $records->get($providerCode);

            $connected = false;

            if ($providerCode === 'stripe') {
                $status = $this->getStripeConnectionStatus($storeId);
                $connected = $status['connected'];
            }

            // PayPal remains disconnected until its connection flow
            // and credential verification are implemented.
            if ($providerCode === 'paypal') {
                $connected = false;
            }

            $result[] = [
                'providerCode' => $providerCode,
                'isEnabled' => (bool) ($record?->is_enabled ?? false),
                'isAvailable' => (bool) ($record?->is_available ?? false),
                'isConnected' => $connected,
                'configuration' => $record?->configuration ?? [],
            ];
        }

        return $result;
    }

    public function enableWalletProvider(
        int $storeId,
        string $methodCode,
        string $providerCode
    ): StorePaymentMethodProvider {
        return DB::connection('tenant')->transaction(function () use (
            $storeId,
            $methodCode,
            $providerCode
        ) {
            $providers = $this->getWalletProviders($storeId, $methodCode);

            $provider = collect($providers)->firstWhere(
                'providerCode',
                $providerCode
            );

            if (!$provider) {
                throw new RuntimeException('Unsupported wallet provider.');
            }

            if (!$provider['isConnected']) {
                throw new RuntimeException(
                    'Connect this provider before enabling it.'
                );
            }

            if (!$provider['isAvailable']) {
                throw new RuntimeException(
                    'This provider has not passed the required wallet eligibility checks.'
                );
            }

            $record = StorePaymentMethodProvider::forStore($storeId)
                ->where('method_code', $methodCode)
                ->where('provider_code', $providerCode)
                ->lockForUpdate()
                ->first();

            if (!$record) {
                throw new RuntimeException(
                    'Wallet provider record not found. Initialize the provider record first.'
                );
            }

            $record->is_enabled = true;
            $record->save();

            return $record->fresh();
        });
    }

    public function disableWalletProvider(
        int $storeId,
        string $methodCode,
        string $providerCode
    ): StorePaymentMethodProvider {
        $record = StorePaymentMethodProvider::forStore($storeId)
            ->where('method_code', $methodCode)
            ->where('provider_code', $providerCode)
            ->first();

        if (!$record) {
            throw new RuntimeException('Wallet provider record not found.');
        }

        $record->is_enabled = false;
        $record->save();

        return $record->fresh();
    }

    public function connectStripeAccount(
        int $storeId,
        array $oauthData
    ): StorePaymentMethod {
        return DB::connection('tenant')->transaction(function () use (
            $storeId,
            $oauthData
        ) {
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
            $method->is_enabled = true;
            $method->save();

            return $method->fresh();
        });
    }

    public function disconnectStripeAccount(int $storeId): void
    {
        DB::connection('tenant')->transaction(function () use ($storeId) {
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

            // Disable wallet provider selections that depend on Stripe.
            StorePaymentMethodProvider::forStore($storeId)
                ->where('provider_code', 'stripe')
                ->update(['is_enabled' => false]);
        });
    }

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

    /**
     * Get resolved Stripe settings for a store.
     *
     * Dynamic settings remain inactive until the feature flag is enabled.
     */
    public function getResolvedStripeSettings(
        int $storeId,
        ?string $currency = null
    ): array
    {
        return app(StripePaymentSettingsResolver::class)
            ->resolve($storeId, $currency);
    }
}
