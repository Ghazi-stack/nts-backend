<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class StripeAccountDisconnector
{
    public function __construct(
        private TenantCredentialResolver $tenantResolver,
        private PaymentSettingsService $paymentSettings
    ) {}

    public function disconnectForStoreId(int $storeId): void
    {
        $store = DB::connection('central')
            ->table('stores')
            ->where('legacy_store_id', $storeId)
            ->where('provisioning_status', 'active')
            ->first();

        if (!$store) {
            throw new RuntimeException('Active store not found.');
        }

        try {
            $this->tenantResolver->connectByStoreHash($store->store_hash);

            $tenantStoreId = (int) (
                $store->legacy_store_id ?? $store->id
            );

            $credentials = $this->paymentSettings->getCredentials(
                $tenantStoreId,
                'stripe'
            );

            // If the central registry has an account, its tenant
            // credentials must also exist and match.
            if (!empty($store->stripe_account_id)) {
                if (
                    !$credentials ||
                    empty($credentials['stripe_user_id']) ||
                    empty($credentials['access_token'])
                ) {
                    throw new RuntimeException(
                        'Stored Stripe connection credentials are missing.'
                    );
                }

                $accountId = $store->stripe_account_id;

                if ($credentials['stripe_user_id'] !== $accountId) {
                    throw new RuntimeException(
                        'Stripe account ID does not match the central store record.'
                    );
                }

                $environment = (bool) $store->stripe_livemode
                    ? 'live'
                    : 'sandbox';

                $expectedLivemode = $environment === 'live';
                $storedLivemode = (bool) ($credentials['livemode'] ?? false);

                if ($storedLivemode !== $expectedLivemode) {
                    throw new RuntimeException(
                        'Stripe account environment does not match the central store record.'
                    );
                }

                $stripeConfig = config(
                    "services.stripe.connect.{$environment}"
                );

                $secret = $stripeConfig['secret'] ?? null;
                $clientId = $stripeConfig['client_id'] ?? null;

                if (!$secret || !$clientId) {
                    throw new RuntimeException(
                        "Stripe {$environment} configuration is incomplete."
                    );
                }

                $response = Http::withBasicAuth($secret, '')
                    ->asForm()
                    ->timeout(30)
                    ->post(
                        'https://connect.stripe.com/oauth/deauthorize',
                        [
                            'client_id' => $clientId,
                            'stripe_user_id' => $accountId,
                        ]
                    );

                if (!$response->successful()) {
                    Log::error('Stripe deauthorization failed', [
                        'store_hash' => $store->store_hash,
                        'environment' => $environment,
                        'response' => $response->json(),
                    ]);

                    throw new RuntimeException(
                        'Stripe could not revoke the connection.'
                    );
                }
            }

            // Clear tenant Stripe credentials and configuration.
            $this->paymentSettings->disconnectStripeAccount(
                $tenantStoreId
            );

            // Clear central Stripe connection details.
            DB::connection('central')
                ->table('stores')
                ->where('id', $store->id)
                ->update([
                    'stripe_account_id' => null,
                    'stripe_livemode' => null,
                    'stripe_connected_at' => null,
                    'updated_at' => now(),
                ]);

            Log::info('Stripe account disconnected successfully', [
                'store_hash' => $store->store_hash,
            ]);

        } finally {
            $this->tenantResolver->disconnect();
        }
    }
}
