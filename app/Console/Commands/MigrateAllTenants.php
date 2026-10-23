<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\TenantDatabaseManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class MigrateAllTenants extends Command
{
    protected $signature = 'app:migrate-all-tenants
                            {--tenant= : Migrate only one central registry store ID}';

    protected $description = 'Run pending tenant migrations across all tenant databases';

    public function handle(TenantDatabaseManager $tenantDatabaseManager): int
    {
        $migrationPath = database_path('migrations/tenant');

        if (!is_dir($migrationPath)) {
            $this->error("Tenant migrations directory not found: {$migrationPath}");

            return self::FAILURE;
        }

        /*
         * Fetch registry records explicitly from the central database.
         * Adjust the model/query if your registry uses another model.
         */
        $storesQuery = DB::connection('central')
        ->table('stores')
        ->where('provisioning_status', 'active');

        if ($this->option('tenant')) {
            $storesQuery->where('id', $this->option('tenant'));
        }

        $stores = $storesQuery->get();

        if ($stores->isEmpty()) {
            $this->warn('No tenant registry records found.');

            return self::SUCCESS;
        }

        $succeeded = 0;
        $failed = 0;

        foreach ($stores as $store) {
            $this->newLine();
            $this->info("Migrating store ID {$store->id}: {$store->name}");

            try {
                /*
                 * Reuse the application's existing tenant connection logic.
                 * This method must configure the "tenant" connection for
                 * the supplied central registry store.
                 */
                $tenantDatabaseManager->connectForStore($store);

                // Verify that a tenant database is actually selected.
                $connection = DB::connection('tenant');
                $databaseName = $connection->getDatabaseName();

                if (!$databaseName) {
                    throw new \RuntimeException(
                        'Tenant connection has no selected database.'
                    );
                }

                $this->line("Database: {$databaseName}");

                /*
                 * Run pending migrations for this tenant only.
                 * Each tenant must maintain its own migrations table.
                 */
                $exitCode = Artisan::call('migrate', [
                    '--database' => 'tenant',
                    '--path' => 'database/migrations/tenant',
                    '--realpath' => false,
                    '--force' => true,
                ]);

                $output = Artisan::output();

                if ($output !== '') {
                    $this->line($output);
                }

                if ($exitCode !== self::SUCCESS) {
                    throw new \RuntimeException(
                        "Migration command exited with code {$exitCode}."
                    );
                }

                $succeeded++;

                $this->info("SUCCESS: Store ID {$store->id}");

                Log::info('Tenant migrations completed', [
                    'store_id' => $store->id,
                    'database' => $databaseName,
                ]);
            } catch (Throwable $e) {
                $failed++;

                $this->error(
                    "FAILED: Store ID {$store->id}: {$e->getMessage()}"
                );

                Log::error('Tenant migration failed', [
                    'store_id' => $store->id,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]);
            } finally {
                /*
                 * Disconnect before switching to the next tenant.
                 * Keep the central connection available for the registry.
                 */
                try {
                    $tenantDatabaseManager->disconnect();
                } catch (Throwable $disconnectError) {
                    Log::warning('Tenant disconnect failed after migration', [
                        'store_id' => $store->id,
                        'message' => $disconnectError->getMessage(),
                    ]);
                }
            }
        }

        $this->newLine();
        $this->info("Migration run finished. Successful: {$succeeded}; Failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}