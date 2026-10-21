<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TenantProvisioningService
{
    public function __construct(
        private TenantCredentialCrypt $crypt,
        private TenantSchemaInstaller $schemaInstaller,
        private TenantDataMigrator $dataMigrator
    ) {}

    public function provisionDatabase(int $registryStoreId): object
    {
        $central = DB::connection('central');
        $admin = DB::connection('provisioner');

        $store = $central->table('stores')
            ->where('id', $registryStoreId)
            ->first();

        if (!$store) {
            throw new RuntimeException('Store registry record not found.');
        }

        if (
            $store->provisioning_status !== 'pending'
            || !empty($store->tenant_database)
        ) {
            throw new RuntimeException(
                'Store is not eligible for new database provisioning.'
            );
        }

        $database = 'tenant_' . $registryStoreId . '_'
            . Str::lower(Str::random(12));

        $username = 'tn_' . Str::lower(Str::random(12));
        $password = Str::random(48);

        $host = (string) config('database.connections.provisioner.host');

        $port = (int) config('database.connections.provisioner.port', 3306);

        $accountHost = (string) config(
            'database.connections.tenant.account_host',
            '127.0.0.1'
        );

        if (
            !preg_match('/^[a-zA-Z0-9_]+$/', $database)
            || !preg_match('/^[a-zA-Z0-9_]+$/', $username)
        ) {
            throw new RuntimeException('Invalid database identifiers.');
        }

        if (
            $port < 1
            || $port > 65535
            || !preg_match('/^[a-zA-Z0-9_.:%-]+$/', $accountHost)
        ) {
            throw new RuntimeException(
                'Invalid database connection settings.'
            );
        }

        $exists = $admin->selectOne(
            'SELECT SCHEMA_NAME
             FROM information_schema.SCHEMATA
             WHERE SCHEMA_NAME = ?',
            [$database]
        );

        if ($exists) {
            throw new RuntimeException(
                'Generated tenant database already exists.'
            );
        }

        $encryptedPassword = $this->crypt->encrypt($password);

        $claimed = $central->table('stores')
            ->where('id', $registryStoreId)
            ->where('provisioning_status', 'pending')
            ->whereNull('tenant_database')
            ->update([
                'tenant_database' => $database,
                'db_host' => $host,
                'db_port' => $port,
                'db_username' => $username,
                'db_password_encrypted' => $encryptedPassword,
                'provisioning_status' => 'provisioning',
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            throw new RuntimeException(
                'Store provisioning was already claimed or changed.'
            );
        }

        try {
            $quotedDatabase = '`' . $database . '`';
            $quotedUsername = $admin->getPdo()->quote($username);
            $quotedHost = $admin->getPdo()->quote($accountHost);
            $quotedPassword = $admin->getPdo()->quote($password);

            $admin->statement(
                "CREATE DATABASE {$quotedDatabase}
                 CHARACTER SET utf8mb4
                 COLLATE utf8mb4_unicode_ci"
            );

            $admin->statement(
                "CREATE USER {$quotedUsername}@{$quotedHost}
                 IDENTIFIED BY {$quotedPassword}"
            );

            
            $admin->statement(
                "GRANT ALL PRIVILEGES ON {$quotedDatabase}.*
                TO {$quotedUsername}@{$quotedHost}"
            );

            // 1. Configure the tenant connection for the newly created database.
            config([
                'database.connections.tenant.host' => $host,
                'database.connections.tenant.port' => $port,
                'database.connections.tenant.database' => $database,
                'database.connections.tenant.username' => $username,
                'database.connections.tenant.password' => $password,
            ]);

            // Discard any cached connection to the sandbox or previous tenant.
            DB::purge('tenant');

            // Verify that the connection reaches the intended tenant database.
            $tenantConnection = DB::connection('tenant');
            $tenantConnection->getPdo();

            if ($tenantConnection->getDatabaseName() !== $database) {
                throw new RuntimeException(
                    'Tenant connection points to the wrong database. '
                    . 'Schema installation and import were stopped.'
                );
            }

            // 2. Install the tenant schema in the correct database.
            $this->schemaInstaller->install($registryStoreId);

            // Verify the schema installer did not change the target connection.
            DB::purge('tenant');
            $tenantConnection = DB::connection('tenant');
            $tenantConnection->getPdo();

            if ($tenantConnection->getDatabaseName() !== $database) {
                throw new RuntimeException(
                    'Tenant schema installation used the wrong database.'
                );
            }

            // 3. Import legacy data into this tenant.
            $result = $this->dataMigrator->migrateStoreData($registryStoreId);

            logger()->info('Tenant data migration completed.', [
                'registry_store_id' => $registryStoreId,
                'legacy_store_id' => $result['legacy_store_id'],
                'tables' => $result['tables'],
                'rows' => $result['rows'],
            ]);

            // 3. Activate only after schema installation and import succeed.
            $activated = $central->table('stores')
                ->where('id', $registryStoreId)
                ->where('provisioning_status', 'provisioning')
                ->where('tenant_database', $database)
                ->update([
                    'provisioning_status' => 'active',
                    'updated_at' => now(),
                ]);

            if ($activated !== 1) {
                throw new RuntimeException(
                    'Tenant installation completed, but activation failed.'
                );
            }

            return $central->table('stores')
                ->where('id', $registryStoreId)
                ->first();

        } catch (Throwable $e) {
            report($e);

            $central->table('stores')
                ->where('id', $registryStoreId)
                ->where('provisioning_status', 'provisioning')
                ->update([
                    'provisioning_status' => 'provisioning_failed',
                    'updated_at' => now(),
                ]);

            // Preserve resources for recovery; do not drop automatically.
            throw new RuntimeException(
                'Tenant provisioning failed. Database resources were '
                . 'retained for recovery. Check the application log.',
                0,
                $e
            );
        }
    }
}
