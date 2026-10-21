<?php

namespace App\Console\Commands;

use App\Services\TenantDataMigrator;
use App\Services\TenantProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProvisionTenant extends Command
{
    protected $signature = 'tenant:provision
                            {store_id : Central store registry ID}
                            {--confirm : Confirm database creation}
                            {--dry-run : Preview the data migration without changes}';

    protected $description =
        'Provision a tenant database and install its schema';

    public function handle(
        TenantProvisioningService $provisioner,
        TenantDataMigrator $migrator
    ): int {
        $storeId = (int) $this->argument('store_id');

        if ($storeId < 1) {
            $this->error('Store ID must be greater than zero.');
            return self::FAILURE;
        }

        try {
            $store = DB::connection('central')
                ->table('stores')
                ->where('id', $storeId)
                ->first();

            if (!$store) {
                $this->error('Central store registry record not found.');
                return self::FAILURE;
            }

            if (
                $store->provisioning_status !== 'pending'
                || !empty($store->tenant_database)
            ) {
                $this->error(
                    'Store is not pending or already has a tenant database.'
                );

                return self::FAILURE;
            }

            if ($this->option('dry-run')) {
                return $this->preview($store, $migrator);
            }

            if (!$this->option('confirm')) {
                $this->error(
                    'No changes made. Use --confirm to create the tenant database.'
                );

                return self::FAILURE;
            }

            if (!$this->confirm(
                "Provision registry store ID {$storeId}? "
                . 'This creates a database and MySQL user.'
            )) {
                $this->info('Cancelled. No changes made.');
                return self::SUCCESS;
            }

            $result = $provisioner->provisionDatabase($storeId);

            $this->info('Tenant provisioning completed.');
            $this->line('Registry ID: ' . $result->id);
            $this->line('Database: ' . $result->tenant_database);
            $this->line('Status: ' . $result->provisioning_status);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function preview(
        object $store,
        TenantDataMigrator $migrator
    ): int {
        $legacyStoreId = (int) ($store->legacy_store_id ?? 0);

        $this->info('DRY RUN — no records will be inserted or deleted.');
        $this->line('Registry ID: ' . $store->id);
        $this->line('Store: ' . $store->name);
        $this->line(
            'Legacy store ID: '
            . ($legacyStoreId ?: 'None (new store)')
        );

        if ($legacyStoreId < 1) {
            $this->info('No legacy data import is needed for this store.');

            return self::SUCCESS;
        }

        $preview = $migrator->previewStoreData((int) $store->id);

        $this->newLine();

        $this->table(
            ['Table', 'Rows to migrate'],
            collect($preview['table_counts'])
                ->map(fn ($count, $table) => [$table, $count])
                ->values()
                ->all()
        );

        $this->newLine();
        $this->line('Tables: ' . $preview['tables']);
        $this->line('Total rows: ' . $preview['rows']);
        $this->line('Source database: ' . $preview['source_database']);
        $this->line(
            'Preview schema: ' . $preview['target_database']
        );

        $this->warn(
            'The preview schema is the currently configured tenant connection. '
            . 'It may not be the new database that provisioning will create.'
        );

        $this->info('Dry run complete. No data was inserted.');

        return self::SUCCESS;
    }
}
