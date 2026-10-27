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
    // public function registerPendingStore(Store $legacyStore, int $userId): object
    // {
    //     $connection = DB::connection('central');

    //     // Avoid creating a duplicate registry entry for the same legacy store.
    //     $existing = $connection->table('stores')
    //         ->where('legacy_store_id', $legacyStore->id)
    //         ->first();

    //     if ($existing) {
    //         return $existing;
    //     }

    //     $storeHash = 'store-' . Str::lower(Str::random(12));

    //     // Protect against the unlikely event of a hash collision.
    //     while ($connection->table('stores')
    //         ->where('store_hash', $storeHash)
    //         ->exists()) {
    //         $storeHash = 'store-' . Str::lower(Str::random(12));
    //     }

    //     $now = now();

    //     $registryId = $connection->table('stores')->insertGetId([
    //         'legacy_store_id' => $legacyStore->id,
    //         'user_id' => $userId,
    //         'store_hash' => $storeHash,
    //         'name' => $legacyStore->name,
    //         'base_url' => $legacyStore->base_url,
    //         'tenant_database' => null,
    //         'provisioning_status' => 'pending',
    //         'created_at' => $now,
    //         'updated_at' => $now,
    //     ]);

    //     $registryStore = $connection->table('stores')
    //         ->where('id', $registryId)
    //         ->first();

    //     if (!$registryStore) {
    //         throw new RuntimeException(
    //             'The central store registry record could not be retrieved.'
    //         );
    //     }

    //     return $registryStore;
    // }

    
    public function registerPendingStore(Store $legacyStore, int $userId): object
    {
        $connection = DB::connection('central');

        // Store::create() already created this central registry row.
        $existing = $connection->table('stores')
            ->where('id', $legacyStore->id)
            ->first();

        if ($existing) {
            // Ensure the existing row is initialized for provisioning.
            $updates = [
                'user_id' => $userId,
                'name' => $legacyStore->name,
                'base_url' => $legacyStore->base_url,
                'legacy_store_id' => $legacyStore->id,
                'updated_at' => now(),
            ];

            if (empty($existing->store_hash)) {
                $storeHash = 'store-' . Str::lower(Str::random(12));

                while ($connection->table('stores')
                    ->where('store_hash', $storeHash)
                    ->exists()) {
                    $storeHash = 'store-' . Str::lower(Str::random(12));
                }

                $updates['store_hash'] = $storeHash;
            }

            if (
                empty($existing->tenant_database)
                && in_array(
                    $existing->provisioning_status,
                    [null, '', 'pending'],
                    true
                )
            ) {
                $updates['provisioning_status'] = 'pending';
                $connection->table('stores')
                    ->where('id', $existing->id)
                    ->update($updates);

                return $connection->table('stores')
                    ->where('id', $existing->id)
                    ->first();
            }

            // Never reset an already provisioned or active store.
            if (
                !empty($existing->tenant_database)
                || $existing->provisioning_status === 'active'
            ) {
                return $existing;
            }

            throw new RuntimeException(
                'Existing store is not eligible for provisioning.'
            );
        }

        throw new RuntimeException(
            'The store created by the Store model could not be found in the central registry.'
        );
    }

}
