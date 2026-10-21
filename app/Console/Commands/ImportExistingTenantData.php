<?php

namespace App\Console\Commands;

use App\Services\TenantCredentialCrypt;
use App\Services\TenantDataMigrator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ImportExistingTenantData extends Command
{
    protected $signature = 'tenant:import-existing
                            {store_id : Central store registry ID}
                            {--confirm : Confirm data import}';

    protected $description =
        'Import legacy data into an existing tenant database';

    public function handle(
        TenantCredentialCrypt $crypt,
        TenantDataMigrator $migrator
    ): int {
        if (!$this->option('confirm')) {
            $this->error('No changes made. Add --confirm to proceed.');
            return self::FAILURE;
        }

        $storeId = (int) $this->argument('store_id');

        try {
            $central = DB::connection('central');

            $store = $central->table('stores')
                ->where('id', $storeId)
                ->first();

            if (!$store) {
                throw new RuntimeException('Store registry record not found.');
            }

            if (
                $store->provisioning_status !== 'active'
                || empty($store->tenant_database)
                || empty($store->db_username)
                || empty($store->db_password_encrypted)
            ) {
                throw new RuntimeException(
                    'Store must be active and have saved tenant credentials.'
                );
            }

            if ($store->tenant_database === 'ecom') {
                throw new RuntimeException(
                    'Refusing to import into the legacy source database.'
                );
            }

            $password = $crypt->decrypt(
                $store->db_password_encrypted
            );

            config([
                'database.connections.tenant.host' =>
                    $store->db_host,
                'database.connections.tenant.port' =>
                    $store->db_port,
                'database.connections.tenant.database' =>
                    $store->tenant_database,
                'database.connections.tenant.username' =>
                    $store->db_username,
                'database.connections.tenant.password' =>
                    $password,
            ]);

            DB::purge('tenant');

            $target = DB::connection('tenant');
            $targetDatabase = $target->getDatabaseName();

            if ($targetDatabase !== $store->tenant_database) {
                throw new RuntimeException(
                    'Tenant connection does not match the registry database.'
                );
            }

            if ($targetDatabase === 'ecom') {
                throw new RuntimeException(
                    'Refusing to write to the source database.'
                );
            }

            $this->info("Target database: {$targetDatabase}");

            foreach (['products', 'customers'] as $table) {
                if (!$target->getSchemaBuilder()->hasTable($table)) {
                    throw new RuntimeException(
                        "Target table '{$table}' does not exist."
                    );
                }

                if ($target->table($table)->exists()) {
                    throw new RuntimeException(
                        "Target table '{$table}' is not empty. Import stopped."
                    );
                }
            }

            if (!$this->confirm(
                "Import legacy store ID {$store->legacy_store_id} "
                . "into {$targetDatabase}?"
            )) {
                $this->info('Cancelled. No data imported.');
                return self::SUCCESS;
            }

            $result = $migrator->migrateStoreData($storeId);

            $this->info('Import completed.');
            $this->line('Tables imported: ' . $result['tables']);
            $this->line('Rows imported: ' . $result['rows']);

            return self::SUCCESS;
        } catch (Throwable $e) {
            report($e);
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            DB::purge('tenant');
        }
    }
}
