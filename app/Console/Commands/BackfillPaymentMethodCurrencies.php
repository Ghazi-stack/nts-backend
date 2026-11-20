<?php

namespace App\Console\Commands;

use App\Services\TenantCredentialResolver;
use Database\Seeders\PaymentMethodsCurrencyBackfillSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class BackfillPaymentMethodCurrencies extends Command
{
    protected $signature = 'tenants:backfill-payment-currencies';

    protected $description =
        'Backfill wildcard payment currencies for all active tenants';

    public function handle(
        TenantCredentialResolver $tenantResolver,
        PaymentMethodsCurrencyBackfillSeeder $backfillSeeder
    ): int {
        $stores = DB::connection('central')
            ->table('stores')
            ->where('provisioning_status', 'active')
            ->whereNotNull('store_hash')
            ->get([
                'id',
                'store_hash',
                'legacy_store_id',
            ]);

        if ($stores->isEmpty()) {
            $this->warn('No active tenant stores found.');

            return self::SUCCESS;
        }

        $this->info("Found {$stores->count()} active tenant stores.");

        $successful = 0;
        $failed = 0;

        foreach ($stores as $store) {
            try {
                $tenantStore = $tenantResolver->connectByStoreHash(
                    $store->store_hash
                );

                $tenantStoreId = (int) (
                    $tenantStore->legacy_store_id ?? $store->id
                );

                $backfillSeeder->run($tenantStoreId);

                $this->info(
                    "Updated payment currencies for store ID {$store->id}."
                );

                $successful++;
            } catch (Throwable $e) {
                report($e);

                $this->error(
                    "Failed store ID {$store->id}: {$e->getMessage()}"
                );

                $failed++;
            } finally {
                $tenantResolver->disconnect();
            }
        }

        $this->newLine();

        $this->info("Successful: {$successful}");
        $this->info("Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}