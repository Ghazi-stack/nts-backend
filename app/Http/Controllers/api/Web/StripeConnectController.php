<?php

namespace App\Http\Controllers\api\Web;

use App\Http\Controllers\Controller;
use App\Services\PaymentSettingsService;
use App\Services\TenantCredentialResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stripe\Stripe;

class StripeConnectController extends Controller
{
    public function __construct(
        private TenantCredentialResolver $tenantResolver,
        private PaymentSettingsService $paymentSettings
    ) {}

    /**
     * Start Standard Stripe OAuth connection.
     */
    // public function connect(Request $request)
    // {
    //     $storeId = current_store_id();

    //     if (!$storeId) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Store not selected.',
    //         ], 400);
    //     }

    //     $store = DB::connection('central')
    //         ->table('stores')
    //         ->where('legacy_store_id', $storeId)
    //         ->where('provisioning_status', 'active')
    //         ->first();

    //     if (!$store) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Active store not found.',
    //         ], 404);
    //     }

    //     $clientId = config('services.stripe.connect.client_id');
    //     $redirectUri = config('services.stripe.connect.redirect_uri');

    //     if (!$clientId || !$redirectUri) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Stripe Connect is not configured.',
    //         ], 500);
    //     }

    //     $state = Str::random(64);

    //     Cache::put(
    //         'stripe_connect_state:' . hash('sha256', $state),
    //         [
    //             'store_hash' => $store->store_hash,
    //             'created_at' => now()->timestamp,
    //         ],
    //         now()->addMinutes(10)
    //     );

    //     $stripeUser = [];

    //     if (!empty($store->name)) {
    //         $stripeUser['business_name'] = $store->name;
    //     }

    //     if (!empty($store->base_url)) {
    //         $stripeUser['url'] = $store->base_url;
    //     }

    //     $params = [
    //         'client_id' => $clientId,
    //         'response_type' => 'code',
    //         'scope' => 'read_write',
    //         'redirect_uri' => $redirectUri,
    //         'state' => $state,
    //     ];

    //     if (!empty($stripeUser)) {
    //         $params['stripe_user'] = $stripeUser;
    //     }

    //     $authorizationUrl = 'https://connect.stripe.com/oauth/authorize?'
    //         . http_build_query($params);

    //     return response()->json([
    //         'status' => true,
    //         'authorization_url' => $authorizationUrl,
    //     ]);
    // }

    

    /**
     * Start Standard Stripe OAuth connection.
     */
    public function connect(Request $request)
    {
        $validator = \Illuminate\Support\Facades\Validator::make(
            $request->all(),
            [
                'environment' => [
                    'required',
                    'string',
                    'in:sandbox,live',
                ],
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid Stripe environment.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store not selected.',
            ], 400);
        }

        $environment = $request->input('environment');

        $store = DB::connection('central')
            ->table('stores')
            ->where('legacy_store_id', $storeId)
            ->where('provisioning_status', 'active')
            ->first();

        if (!$store) {
            return response()->json([
                'status' => false,
                'message' => 'Active store not found.',
            ], 404);
        }

        $stripeConfig = config(
            "services.stripe.connect.{$environment}"
        );

        $clientId = $stripeConfig['client_id'] ?? null;
        $redirectUri = $stripeConfig['redirect_uri'] ?? null;

        if (!$clientId || !$redirectUri) {
            return response()->json([
                'status' => false,
                'message' => "Stripe {$environment} Connect is not configured.",
            ], 500);
        }

        $state = Str::random(64);

        Cache::put(
            'stripe_connect_state:' . hash('sha256', $state),
            [
                'store_hash' => $store->store_hash,
                'environment' => $environment,
                'created_at' => now()->timestamp,
            ],
            now()->addMinutes(10)
        );

        $stripeUser = [];

        if (!empty($store->name)) {
            $stripeUser['business_name'] = $store->name;
        }

        if (!empty($store->base_url)) {
            $stripeUser['url'] = $store->base_url;
        }

        $params = [
            'client_id' => $clientId,
            'response_type' => 'code',
            'scope' => 'read_write',
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ];

        if (!empty($stripeUser)) {
            $params['stripe_user'] = $stripeUser;
        }

        $authorizationUrl = 'https://connect.stripe.com/oauth/authorize?'
            . http_build_query($params);

        return response()->json([
            'status' => true,
            'environment' => $environment,
            'authorization_url' => $authorizationUrl,
        ]);
    }

    
    /**
     * Handle Stripe OAuth callback.
     */
    // public function callback(Request $request)
    // {
    //     if ($request->filled('error')) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => $request->input('error_description')
    //                 ?: 'Stripe connection was cancelled.',
    //         ], 400);
    //     }

    //     $request->validate([
    //         'code' => 'required|string',
    //         'state' => 'required|string',
    //     ]);

    //     $stateKey = 'stripe_connect_state:'
    //         . hash('sha256', $request->input('state'));

    //     $stateData = Cache::pull($stateKey);

    //     if (!$stateData || empty($stateData['store_hash'])) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Invalid or expired Stripe connection state.',
    //         ], 400);
    //     }

    //     $secret = config('services.stripe.secret');
    //     $clientId = config('services.stripe.connect.client_id');

    //     if (!$secret || !$clientId) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Stripe Connect configuration is incomplete.',
    //         ], 500);
    //     }

    //     try {
    //         // Exchange authorization code for connected account credentials.
    //         $response = Http::asForm()
    //             ->timeout(30)
    //             ->post('https://connect.stripe.com/oauth/token', [
    //                 'client_secret' => $secret,
    //                 'code' => $request->input('code'),
    //                 'grant_type' => 'authorization_code',
    //             ]);

    //         if (!$response->successful()) {
    //             Log::error('Stripe OAuth token exchange failed', [
    //                 'status' => $response->status(),
    //                 'response' => $response->json(),
    //             ]);

    //             return response()->json([
    //                 'status' => false,
    //                 'message' => 'Unable to complete Stripe authorization.',
    //             ], 422);
    //         }

    //         $oauthData = $response->json();

    //         if (
    //             empty($oauthData['stripe_user_id']) ||
    //             empty($oauthData['access_token'])
    //         ) {
    //             throw new \RuntimeException(
    //                 'Stripe did not return the required account credentials.'
    //             );
    //         }

    //         $store = $this->tenantResolver->connectByStoreHash(
    //             $stateData['store_hash']
    //         );

    //         $tenantStoreId = (int) (
    //             $store->legacy_store_id ?? $store->id
    //         );

    //         $this->paymentSettings->connectStripeAccount(
    //             $tenantStoreId,
    //             $oauthData
    //         );

    //         // Save account reference in the central store registry.
    //         DB::connection('central')
    //             ->table('stores')
    //             ->where('store_hash', $stateData['store_hash'])
    //             ->update([
    //                 'stripe_account_id' => $oauthData['stripe_user_id'],
    //                 'stripe_livemode' => (bool) ($oauthData['livemode'] ?? false),
    //                 'stripe_connected_at' => now(),
    //                 'updated_at' => now(),
    //             ]);

    //         $this->tenantResolver->disconnect();

    //         Log::info('Stripe account connected successfully', [
    //             'store_hash' => $stateData['store_hash'],
    //             'stripe_account_id' => $oauthData['stripe_user_id'],
    //             'livemode' => $oauthData['livemode'] ?? false,
    //         ]);

    //         // Return merchant to the store admin payment settings page.
    //         $frontendUrl = rtrim(config('app.dashboard_url'), '/');

    //         if ($frontendUrl) {
    //             return redirect(
    //                 $frontendUrl . '/admin/payment-settings?stripe=connected'
    //             );
    //         }

    //         return response()->json([
    //             'status' => true,
    //             'message' => 'Stripe connected successfully.',
    //         ]);
    //     } catch (\Throwable $e) {
    //         $this->tenantResolver->disconnect();

    //         Log::error('Stripe Connect callback failed', [
    //             'store_hash' => $stateData['store_hash'] ?? null,
    //             'error' => $e->getMessage(),
    //         ]);

    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Stripe connection could not be completed.',
    //         ], 500);
    //     }
    // }

    

    
    /**
     * Handle Stripe OAuth callback.
     */
    public function callback(Request $request)
    {
        if ($request->filled('error')) {
            return response()->json([
                'status' => false,
                'message' => $request->input('error_description')
                    ?: 'Stripe connection was cancelled.',
            ], 400);
        }

        $request->validate([
            'code' => 'required|string',
            'state' => 'required|string',
        ]);

        $stateKey = 'stripe_connect_state:'
            . hash('sha256', $request->input('state'));

        $stateData = Cache::pull($stateKey);

        if (
            !$stateData ||
            empty($stateData['store_hash']) ||
            empty($stateData['environment']) ||
            !in_array($stateData['environment'], ['sandbox', 'live'], true)
        ) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or expired Stripe connection state.',
            ], 400);
        }

        $environment = $stateData['environment'];

        $stripeConfig = config("services.stripe.connect.{$environment}");

        $secret = $stripeConfig['secret'] ?? null;
        $clientId = $stripeConfig['client_id'] ?? null;

        if (!$secret || !$clientId) {
            return response()->json([
                'status' => false,
                'message' => 'Stripe Connect configuration is incomplete.',
            ], 500);
        }

        try {
            // Exchange authorization code using the selected environment.
            $response = Http::asForm()
                ->timeout(30)
                ->post('https://connect.stripe.com/oauth/token', [
                    'client_secret' => $secret,
                    'code' => $request->input('code'),
                    'grant_type' => 'authorization_code',
                ]);

            if (!$response->successful()) {
                Log::error('Stripe OAuth token exchange failed', [
                    'environment' => $environment,
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);

                return response()->json([
                    'status' => false,
                    'message' => 'Unable to complete Stripe authorization.',
                ], 422);
            }

            $oauthData = $response->json();

            if (
                empty($oauthData['stripe_user_id']) ||
                empty($oauthData['access_token'])
            ) {
                throw new \RuntimeException(
                    'Stripe did not return the required account credentials.'
                );
            }

            // Ensure Stripe returned an account for the selected environment.
            $expectedLivemode = $environment === 'live';
            $actualLivemode = (bool) ($oauthData['livemode'] ?? false);

            if ($actualLivemode !== $expectedLivemode) {
                throw new \RuntimeException(
                    'The connected Stripe account environment does not match the selected environment.'
                );
            }

            $store = $this->tenantResolver->connectByStoreHash(
                $stateData['store_hash']
            );

            $tenantStoreId = (int) (
                $store->legacy_store_id ?? $store->id
            );

            $this->paymentSettings->connectStripeAccount(
                $tenantStoreId,
                $oauthData
            );

            // Save account reference in the central store registry.
            DB::connection('central')
                ->table('stores')
                ->where('store_hash', $stateData['store_hash'])
                ->update([
                    'stripe_account_id' => $oauthData['stripe_user_id'],
                    'stripe_livemode' => $actualLivemode,
                    'stripe_connected_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->tenantResolver->disconnect();

            Log::info('Stripe account connected successfully', [
                'store_hash' => $stateData['store_hash'],
                'stripe_account_id' => $oauthData['stripe_user_id'],
                'environment' => $environment,
                'livemode' => $actualLivemode,
            ]);

            // Return merchant to the store admin payment settings page.
            $frontendUrl = rtrim(config('app.dashboard_url'), '/');

            if ($frontendUrl) {
                return redirect(
                    $frontendUrl . '/admin/payment-settings?stripe=connected'
                );
            }

            return response()->json([
                'status' => true,
                'message' => 'Stripe connected successfully.',
                'environment' => $environment,
            ]);
        } catch (\Throwable $e) {
            $this->tenantResolver->disconnect();

            Log::error('Stripe Connect callback failed', [
                'store_hash' => $stateData['store_hash'] ?? null,
                'environment' => $environment,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Stripe connection could not be completed.',
            ], 500);
        }
    }


    
    /**
     * Get safe connection status for the current store.
     */
    // public function status()
    // {
    //     $storeId = current_store_id();

    //     if (!$storeId) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Store not selected.',
    //         ], 400);
    //     }

    //     $store = DB::connection('central')
    //         ->table('stores')
    //         ->where('legacy_store_id', $storeId)
    //         ->where('provisioning_status', 'active')
    //         ->first();

    //     if (!$store) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Store not found.',
    //         ], 404);
    //     }

    //     try {
    //         $this->tenantResolver->connectByStoreHash($store->store_hash);

    //         $connection = $this->paymentSettings
    //             ->getStripeConnectionStatus((int) (
    //                 $store->legacy_store_id ?? $store->id
    //             ));

    //         return response()->json([
    //             'status' => true,
    //             'data' => $connection,
    //         ]);
    //     } catch (\Throwable $e) {
    //         Log::error('Stripe connection status lookup failed', [
    //             'store_hash' => $store->store_hash,
    //             'error' => $e->getMessage(),
    //         ]);

    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Unable to retrieve Stripe connection status.',
    //         ], 500);
    //     } finally {
    //         $this->tenantResolver->disconnect();
    //     }
    // }


    /**
     * Get Stripe connection status for the current store.
     */
    public function status()
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store not selected.',
            ], 400);
        }

        $store = DB::connection('central')
            ->table('stores')
            ->where('legacy_store_id', $storeId)
            ->where('provisioning_status', 'active')
            ->first();

        if (!$store) {
            return response()->json([
                'status' => false,
                'message' => 'Store not found.',
            ], 404);
        }

        try {
            $this->tenantResolver->connectByStoreHash($store->store_hash);

            $connection = $this->paymentSettings
                ->getStripeConnectionStatus((int) (
                    $store->legacy_store_id ?? $store->id
                ));

            // Determine the environment from the central store registry.
            $environment = $store->stripe_account_id
                ? ((bool) $store->stripe_livemode ? 'live' : 'sandbox')
                : null;

            $connection['environment'] = $environment;

            // Keep the central registry and tenant connection consistent.
            if ($connection['connected']) {
                $connection['account_id'] = $store->stripe_account_id
                    ?: $connection['account_id'];

                $connection['livemode'] = (bool) $store->stripe_livemode;
            }

            return response()->json([
                'status' => true,
                'data' => $connection,
            ]);

        } catch (\Throwable $e) {
            Log::error('Stripe connection status lookup failed', [
                'store_hash' => $store->store_hash,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Unable to retrieve Stripe connection status.',
            ], 500);

        } finally {
            $this->tenantResolver->disconnect();
        }
    }


    /**
     * Disconnect the current store's Stripe account.
     */
    // public function disconnect()
    // {
    //     $storeId = current_store_id();

    //     if (!$storeId) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Store not selected.',
    //         ], 400);
    //     }

    //     $store = DB::connection('central')
    //         ->table('stores')
    //         ->where('legacy_store_id', $storeId)
    //         ->where('provisioning_status', 'active')
    //         ->first();

    //     if (!$store || !$store->stripe_account_id) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'No Stripe account is connected.',
    //         ], 404);
    //     }

    //     $connectedAccountId = $store->stripe_account_id;

    //     try {
    //         $this->tenantResolver->connectByStoreHash($store->store_hash);

    //         $credentials = $this->paymentSettings->getCredentials(
    //             (int) ($store->legacy_store_id ?? $store->id),
    //             'stripe'
    //         );

    //         if (!$credentials || empty($credentials['stripe_user_id'])) {
    //             throw new \RuntimeException(
    //                 'Stored Stripe connection credentials are missing.'
    //             );
    //         }

    //         // Revoke OAuth authorization with Stripe.
    //         $response = Http::withBasicAuth(
    //             config('services.stripe.secret'),
    //             ''
    //         )
    //             ->asForm()
    //             ->timeout(30)
    //             ->post('https://connect.stripe.com/oauth/deauthorize', [
    //                 'client_id' => config('services.stripe.connect.client_id'),
    //                 'stripe_user_id' => $connectedAccountId,
    //             ]);

    //         if (!$response->successful()) {
    //             Log::error('Stripe deauthorization failed', [
    //                 'store_hash' => $store->store_hash,
    //                 'response' => $response->json(),
    //             ]);

    //             return response()->json([
    //                 'status' => false,
    //                 'message' => 'Stripe could not revoke the connection.',
    //             ], 422);
    //         }

    //         $this->paymentSettings->disconnectStripeAccount(
    //             (int) ($store->legacy_store_id ?? $store->id)
    //         );

    //         DB::connection('central')
    //             ->table('stores')
    //             ->where('id', $store->id)
    //             ->update([
    //                 'stripe_account_id' => null,
    //                 'stripe_livemode' => null,
    //                 'stripe_connected_at' => null,
    //                 'updated_at' => now(),
    //             ]);

    //         return response()->json([
    //             'status' => true,
    //             'message' => 'Stripe disconnected successfully.',
    //         ]);
    //     } catch (\Throwable $e) {
    //         Log::error('Stripe disconnect failed', [
    //             'store_hash' => $store->store_hash,
    //             'error' => $e->getMessage(),
    //         ]);

    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Unable to disconnect Stripe.',
    //         ], 500);
    //     } finally {
    //         $this->tenantResolver->disconnect();
    //     }
    // }


    
    /**
     * Disconnect the current store's Stripe account.
     */
    public function disconnect()
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store not selected.',
            ], 400);
        }

        $store = DB::connection('central')
            ->table('stores')
            ->where('legacy_store_id', $storeId)
            ->where('provisioning_status', 'active')
            ->first();

        if (!$store || !$store->stripe_account_id) {
            return response()->json([
                'status' => false,
                'message' => 'No Stripe account is connected.',
            ], 404);
        }

        // Select the same environment used when the account was connected.
        $environment = (bool) $store->stripe_livemode
            ? 'live'
            : 'sandbox';

        $stripeConfig = config("services.stripe.connect.{$environment}");

        $secret = $stripeConfig['secret'] ?? null;
        $clientId = $stripeConfig['client_id'] ?? null;

        if (!$secret || !$clientId) {
            return response()->json([
                'status' => false,
                'message' => "Stripe {$environment} configuration is incomplete.",
            ], 500);
        }

        $connectedAccountId = $store->stripe_account_id;

        try {
            $this->tenantResolver->connectByStoreHash($store->store_hash);

            $credentials = $this->paymentSettings->getCredentials(
                (int) ($store->legacy_store_id ?? $store->id),
                'stripe'
            );

            if (
                !$credentials ||
                empty($credentials['stripe_user_id'])
            ) {
                throw new \RuntimeException(
                    'Stored Stripe connection credentials are missing.'
                );
            }

            // Verify stored account environment before deauthorization.
            $storedLivemode = (bool) ($credentials['livemode'] ?? false);
            $expectedLivemode = $environment === 'live';

            if ($storedLivemode !== $expectedLivemode) {
                throw new \RuntimeException(
                    'Stored Stripe account environment does not match the central store record.'
                );
            }

            // Revoke OAuth authorization using the matching environment credentials.
            $response = Http::withBasicAuth($secret, '')
                ->asForm()
                ->timeout(30)
                ->post('https://connect.stripe.com/oauth/deauthorize', [
                    'client_id' => $clientId,
                    'stripe_user_id' => $connectedAccountId,
                ]);

            if (!$response->successful()) {
                Log::error('Stripe deauthorization failed', [
                    'store_hash' => $store->store_hash,
                    'environment' => $environment,
                    'response' => $response->json(),
                ]);

                return response()->json([
                    'status' => false,
                    'message' => 'Stripe could not revoke the connection.',
                ], 422);
            }

            $this->paymentSettings->disconnectStripeAccount(
                (int) ($store->legacy_store_id ?? $store->id)
            );

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
                'environment' => $environment,
                'stripe_account_id' => $connectedAccountId,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Stripe disconnected successfully.',
            ]);
        } catch (\Throwable $e) {
            Log::error('Stripe disconnect failed', [
                'store_hash' => $store->store_hash,
                'environment' => $environment,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Unable to disconnect Stripe.',
            ], 500);
        } finally {
            $this->tenantResolver->disconnect();
        }
    }


}