<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use App\Models\Store;

class SeedTransactionalEmailsForTenants extends Command
{
    protected $signature = 'seed:transactional-emails';
    protected $description = 'Seed transactional emails for all tenant databases';

    public function handle()
    {
        $stores = Store::all();

        if ($stores->isEmpty()) {
            $this->error('No stores found in central database.');
            return Command::FAILURE;
        }

        foreach ($stores as $store) {
            $dbName = $store->database_name ?? null;

            if (!$dbName) {
                $this->warn("Store ID {$store->id} has no database_name. Skipping...");
                continue;
            }

            $this->info("========================================");
            $this->info("Seeding Store ID: {$store->id}");
            $this->info("Database: {$dbName}");

            try {
                // Switch to tenant database
                config(['database.connections.tenant.database' => $dbName]);
                DB::purge('tenant');
                DB::reconnect('tenant');

                // Run seeder
                Artisan::call('db:seed', [
                    '--class' => 'TransactionalEmailTemplateSeeder',
                    '--force' => true,
                ]);

                $this->info(Artisan::output());
                $this->info("Completed for Store {$store->id}");
            } catch (\Exception $e) {
                $this->error("Failed for Store {$store->id}: " . $e->getMessage());
            }
        }

        $this->info("========================================");
        $this->info("All tenants processed.");
        return Command::SUCCESS;
    }
}
