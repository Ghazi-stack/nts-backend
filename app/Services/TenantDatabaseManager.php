<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use InvalidArgumentException;

class TenantDatabaseManager
{
    /**
     * Find the store in the central registry and connect
     * only if the store is marked active.
     */
    public function connectByStoreHash(string $storeHash): object
    {
        if (!preg_match('/^store-[a-z0-9]{12}$/', $storeHash)) {
            throw new InvalidArgumentException(
                'The store hash format is invalid.'
            );
        }

        $store = DB::connection('central')
            ->table('stores')
            ->where('store_hash', $storeHash)
            ->first();

        if (!$store) {
            throw new RuntimeException(
                'Store not found in the central registry.'
            );
        }

        if (($store->provisioning_status ?? null) !== 'active') {
            throw new RuntimeException(
                'The store is not active for tenant database access.'
            );
        }

        $this->connectForStore($store);

        return $store;
    }

    /**
     * Configure and connect using a trusted registry record.
     */
    public function connectForStore(object $store): void
    {
        $databaseName = $store->tenant_database ?? null;

        if (!is_string($databaseName) || $databaseName === '') {
            throw new RuntimeException(
                'No tenant database is configured for this store.'
            );
        }

        if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $databaseName)) {
            throw new InvalidArgumentException(
                'The tenant database name is invalid.'
            );
        }

        config([
            'database.connections.tenant.database' => $databaseName,
        ]);

        DB::purge('tenant');

        // Verify the connection is reachable.
        DB::connection('tenant')->getPdo();
    }

    /**
     * Disconnect the tenant connection and clear its database name.
     */
    public function disconnect(): void
    {
        DB::purge('tenant');

        config([
            'database.connections.tenant.database' => null,
        ]);
    }
}
