<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class StoreRegistryService
{
    /**
     * Create the central registry entry for a legacy store.
     * This does not create or activate the tenant database.
     */
    public function registerPendingStore(Store $legacyStore, int $userId): object
    {
        $connection = DB::connection('central');

        // Avoid creating a duplicate registry entry for the same legacy store.
        $existing = $connection->table('stores')
            ->where('legacy_store_id', $legacyStore->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $storeHash = 'store-' . Str::lower(Str::random(12));

        // Protect against the unlikely event of a hash collision.
        while ($connection->table('stores')
            ->where('store_hash', $storeHash)
            ->exists()) {
            $storeHash = 'store-' . Str::lower(Str::random(12));
        }

        $now = now();

        $registryId = $connection->table('stores')->insertGetId([
            'legacy_store_id' => $legacyStore->id,
            'user_id' => $userId,
            'store_hash' => $storeHash,
            'name' => $legacyStore->name,
            'base_url' => $legacyStore->base_url,
            'tenant_database' => null,
            'provisioning_status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $registryStore = $connection->table('stores')
            ->where('id', $registryId)
            ->first();

        if (!$registryStore) {
            throw new RuntimeException(
                'The central store registry record could not be retrieved.'
            );
        }

        return $registryStore;
    }
}
