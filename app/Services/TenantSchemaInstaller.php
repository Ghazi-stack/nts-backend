<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class TenantSchemaInstaller
{
    public function __construct(
        private TenantCredentialCrypt $crypt
    ) {}

    public function install(int $registryStoreId): object
    {
        $central = DB::connection('central');

        $store = $central->table('stores')
            ->where('id', $registryStoreId)
            ->first();

        if (!$store) {
            throw new RuntimeException('Store registry record not found.');
        }

        if ($store->provisioning_status !== 'provisioning') {
            throw new RuntimeException(
                'Only stores in provisioning status can be installed.'
            );
        }

        foreach ([
            'tenant_database',
            'db_host',
            'db_username',
            'db_password_encrypted',
        ] as $field) {
            if (empty($store->{$field})) {
                throw new RuntimeException(
                    "Missing tenant database setting: {$field}"
                );
            }
        }

        $password = $this->crypt->decrypt(
            $store->db_password_encrypted
        );

        // Preserve the caller's connection configuration.
        $previousConfig = config('database.connections.tenant');

        try {
            config([
                'database.connections.tenant.host' => $store->db_host,
                'database.connections.tenant.port' => (int) $store->db_port,
                'database.connections.tenant.database' => $store->tenant_database,
                'database.connections.tenant.username' => $store->db_username,
                'database.connections.tenant.password' => $password,
            ]);

            DB::purge('tenant');

            $connection = DB::connection('tenant');
            $connection->getPdo();

            if ($connection->getDatabaseName() !== $store->tenant_database) {
                throw new RuntimeException(
                    'Tenant connection does not point to the registered database.'
                );
            }

            $path = database_path('migrations/tenant');
            $files = glob($path . DIRECTORY_SEPARATOR . '*.php') ?: [];

            if (!$files) {
                throw new RuntimeException(
                    'No tenant migrations exist. Store remains inactive.'
                );
            }

            $exitCode = Artisan::call('migrate', [
                '--database' => 'tenant',
                '--path' => 'database/migrations/tenant',
                '--force' => true,
            ]);

            if ($exitCode !== 0) {
                throw new RuntimeException(
                    'Tenant migration command failed: ' . Artisan::output()
                );
            }

            // Confirm the migration command left the connection on
            // the intended tenant database.
            DB::purge('tenant');

            $connection = DB::connection('tenant');
            $connection->getPdo();

            if ($connection->getDatabaseName() !== $store->tenant_database) {
                throw new RuntimeException(
                    'Tenant schema installation used the wrong database.'
                );
            }

            return $central->table('stores')
                ->where('id', $registryStoreId)
                ->first();

        } catch (Throwable $e) {
            report($e);

            throw new RuntimeException(
                'Tenant schema installation failed. The store remains inactive.',
                0,
                $e
            );
        } finally {
            DB::purge('tenant');

            if (is_array($previousConfig)) {
                config([
                    'database.connections.tenant' => $previousConfig,
                ]);
            }

            DB::purge('tenant');
        }
    }
}
