<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\StorePaymentMethod;
use RuntimeException;

class StripeConnectAccountResolver
{
    public function optionsForRequest(
        Request $request,
        bool $requireEnabled = true
    ): array {
        $store = $request->attributes->get('resolved_store')
            ?? $request->attributes->get('tenant_store');

        if (!$store || empty($store->store_hash)) {
            throw new RuntimeException('Store context is missing.');
        }

        $centralStore = DB::connection('central')
            ->table('stores')
            ->where('store_hash', $store->store_hash)
            ->where('provisioning_status', 'active')
            ->first();

        if (!$centralStore || empty($centralStore->stripe_account_id)) {
            throw new RuntimeException(
                'This store has not connected a Stripe account.'
            );
        }

        $storeId = (int) (
            $centralStore->legacy_store_id ?? $centralStore->id
        );

        $method = StorePaymentMethod::forStore($storeId)
            ->where('method_code', 'stripe')
            ->first();

        $credentials = $method?->credentials ?? [];

        if (
            !$method ||
            !$method->is_configured ||
            empty($credentials['stripe_user_id'])
        ) {
            throw new RuntimeException(
                'Stripe connection credentials are not configured for this store.'
            );
        }

        if ($requireEnabled && !$method->is_enabled) {
            throw new RuntimeException(
                'Stripe payments are disabled for this store.'
            );
        }

        $accountId = (string) $credentials['stripe_user_id'];

        if (!hash_equals(
            (string) $centralStore->stripe_account_id,
            $accountId
        )) {
            throw new RuntimeException(
                'Stripe account configuration does not match the central store registry.'
            );
        }

        // The OAuth credentials must explicitly contain their environment.
        if (!array_key_exists('livemode', $credentials)) {
            throw new RuntimeException(
                'Stripe account environment is missing from connection credentials.'
            );
        }

        $credentialsLivemode = filter_var(
            $credentials['livemode'],
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );

        if ($credentialsLivemode === null) {
            throw new RuntimeException(
                'Stripe account environment is invalid.'
            );
        }

        $centralLivemode = filter_var(
            $centralStore->stripe_livemode,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );

        if ($centralLivemode === null) {
            throw new RuntimeException(
                'Stripe environment is missing from the central store registry.'
            );
        }

        if ($credentialsLivemode !== $centralLivemode) {
            throw new RuntimeException(
                'Stripe account environment does not match the central store registry.'
            );
        }

        return [
            'stripe_account' => $centralStore->stripe_account_id,
        ];
    }
}