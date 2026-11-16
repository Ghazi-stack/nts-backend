<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Resolves the connected Stripe account for the store already selected by
 * ResolveStoreByHash / ConnectTenantDatabase. Never accepts an account ID
 * supplied by the browser.
 */
class StripeConnectAccountResolver
{
    public function optionsForRequest(Request $request, bool $requireEnabled = true): array
    {
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
            throw new RuntimeException('This store has not connected a Stripe account.');
        }

        $storeId = (int) ($centralStore->legacy_store_id ?? $centralStore->id);
        $method = \App\Models\StorePaymentMethod::forStore($storeId)
            ->where('method_code', 'stripe')
            ->first();

        $credentials = $method?->credentials ?? [];
        $accountId = $credentials['stripe_user_id'] ?? null;

        if (!$method || !$method->is_configured || !$accountId) {
            throw new RuntimeException('Stripe connection credentials are not configured for this store.');
        }

        if ($requireEnabled && !$method->is_enabled) {
            throw new RuntimeException('Stripe payments are disabled for this store.');
        }

        if (!hash_equals((string) $centralStore->stripe_account_id, (string) $accountId)) {
            throw new RuntimeException('Stripe account configuration does not match the central store registry.');
        }

        return [
            'stripe_account' => $centralStore->stripe_account_id,
        ];
    }
}
