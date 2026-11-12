<?php

namespace App\Console\Commands;

use App\Services\TenantCredentialResolver;
use Database\Seeders\PaymentMethodsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class SeedTenantPaymentMethods extends Command
{
    protected $signature = 'tenants:seed-payment-methods
                            {--store-hash= : Seed only the specified store}';

    protected $description = 'Seed default payment methods in existing tenant databases';

    public function handle(
        TenantCredentialResolver $tenantResolver,
        PaymentMethodsSeeder $paymentMethodsSeeder
    ): int {
        $this->info('Starting tenant payment methods seeding...');

        $query = DB::connection('central')
            ->table('stores')
            ->where('provisioning_status', 'active');

        // Optional: process only one store.
        $storeHash = $this->option('store-hash');

        if ($storeHash) {
            $query->where('store_hash', $storeHash);
        }

        $processed = 0;
        $successful = 0;
        $failed = 0;

        $query->chunkById(100, function ($stores) use (
            $tenantResolver,
            $paymentMethodsSeeder,
            &$processed,
            &$successful,
            &$failed
        ) {
            foreach ($stores as $store) {
                $processed++;

                $this->newLine();
                $this->line("Processing store: {$store->store_hash}");

                try {
                    // Connect to this store's tenant database.
                    $tenantStore = $tenantResolver->connectByStoreHash(
                        $store->store_hash
                    );

                    // Use the legacy store ID where available.
                    $tenantStoreId = (int) (
                        $tenantStore->legacy_store_id
                        ?? $tenantStore->id
                    );

                    if ($tenantStoreId <= 0) {
                        throw new \RuntimeException(
                            'Invalid tenant store ID.'
                        );
                    }

                    // Insert missing default payment methods.
                    $paymentMethodsSeeder->run($tenantStoreId);

                    $successful++;

                    $this->info(
                        "SUCCESS: {$store->store_hash} - "
                        . "Payment methods seeded."
                    );
                } catch (Throwable $e) {
                    $failed++;

                    $this->error(
                        "FAILED: {$store->store_hash} - {$e->getMessage()}"
                    );

                    report($e);
                } finally {
                    // Always disconnect before processing another tenant.
                    try {
                        $tenantResolver->disconnect();
                    } catch (Throwable $disconnectException) {
                        report($disconnectException);

                        $this->error(
                            "WARNING: Could not cleanly disconnect tenant "
                            . "{$store->store_hash}."
                        );
                    }
                }
            }
        }, 'id');

        $this->newLine();
        $this->info('Payment methods seeding completed.');
        $this->table(
            ['Result', 'Count'],
            [
                ['Total stores processed', $processed],
                ['Successful', $successful],
                ['Failed', $failed],
            ]
        );

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}