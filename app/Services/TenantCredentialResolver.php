<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use InvalidArgumentException;

class TenantCredentialResolver
{
    public function __construct(
        private TenantCredentialCrypt $crypt
    ) {}

    /**
     * Resolve a registered store and configure its tenant connection.
     *
     * The store hash is an identifier, not an authorization credential.
     * The caller must enforce customer/staff authorization separately.
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
            throw new RuntimeException('Store not found.');
        }

        if (($store->provisioning_status ?? null) !== 'active') {
            throw new RuntimeException(
                'The store is not active for tenant database access.'
            );
        }

        $database = $store->tenant_database ?? null;
        $host = $store->db_host ?? null;
        $username = $store->db_username ?? null;
        $encryptedPassword = $store->db_password_encrypted ?? null;
        $port = (int) ($store->db_port ?? 3306);

        if (
            !is_string($database) ||
            !preg_match('/^[a-zA-Z0-9_]{1,64}$/', $database)
        ) {
            throw new RuntimeException(
                'The tenant database name is missing or invalid.'
            );
        }

        if (!is_string($host) || $host === '') {
            throw new RuntimeException('The tenant database host is missing.');
        }

        if (!is_string($username) || $username === '') {
            throw new RuntimeException('The tenant database username is missing.');
        }

        if (!is_string($encryptedPassword) || $encryptedPassword === '') {
            throw new RuntimeException('The tenant database password is missing.');
        }

        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('The tenant database port is invalid.');
        }

        $password = $this->crypt->decrypt($encryptedPassword);

        // Apply trusted configuration from the central registry.
        config([
            'database.connections.tenant.host' => $host,
            'database.connections.tenant.port' => $port,
            'database.connections.tenant.database' => $database,
            'database.connections.tenant.username' => $username,
            'database.connections.tenant.password' => $password,
        ]);

        // Discard any cached connection from a previous tenant.
        DB::purge('tenant');

        // Verify the database connection before returning the store.
        DB::connection('tenant')->getPdo();

        return $store;
    }

    /**
     * Disconnect tenant access and clear runtime credentials.
     */
    public function disconnect(): void
    {
        DB::purge('tenant');

        config([
            'database.connections.tenant.host' => null,
            'database.connections.tenant.database' => null,
            'database.connections.tenant.username' => null,
            'database.connections.tenant.password' => null,
            'database.connections.tenant.port' => null,
        ]);
    }
}
