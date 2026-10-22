<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use InvalidArgumentException;
use App\Services\TenantCredentialCrypt;

class TenantDatabaseManager
{
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

    public function connectForStore(object $store): void
    {
        $databaseName = $store->tenant_database ?? null;
        $username = $store->db_username ?? null;
        $encryptedPassword = $store->db_password_encrypted ?? null;
        $host = $store->db_host ?? null;
        $port = $store->db_port ?? null;

        if (
            !is_string($databaseName) ||
            $databaseName === '' ||
            !is_string($username) ||
            $username === '' ||
            !is_string($encryptedPassword) ||
            $encryptedPassword === '' ||
            !is_string($host) ||
            $host === '' ||
            !is_numeric($port)
        ) {
            throw new RuntimeException(
                'Tenant database credentials are incomplete.'
            );
        }

        if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $databaseName)) {
            throw new InvalidArgumentException(
                'The tenant database name is invalid.'
            );
        }

        if (!preg_match('/^[a-zA-Z0-9_]{1,32}$/', $username)) {
            throw new InvalidArgumentException(
                'The tenant database username is invalid.'
            );
        }

        $password = app(TenantCredentialCrypt::class)
            ->decrypt($encryptedPassword);

        if (!is_string($password) || $password === '') {
            throw new RuntimeException(
                'Tenant database password could not be decrypted.'
            );
        }

        config([
            'database.connections.tenant.host' => $host,
            'database.connections.tenant.port' => (int) $port,
            'database.connections.tenant.database' => $databaseName,
            'database.connections.tenant.username' => $username,
            'database.connections.tenant.password' => $password,
        ]);

        DB::purge('tenant');

        DB::connection('tenant')->getPdo();
    }

    public function disconnect(): void
    {
        DB::purge('tenant');

        config([
            'database.connections.tenant.database' => null,
            'database.connections.tenant.username' => null,
            'database.connections.tenant.password' => null,
        ]);
    }
}
