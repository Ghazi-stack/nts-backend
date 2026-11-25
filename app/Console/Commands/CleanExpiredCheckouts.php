<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\CheckoutFormData;
use App\Services\TenantCredentialResolver;
use Throwable;

class CleanExpiredCheckouts extends Command
{
    protected $signature = 'checkout:clean-expired';

    protected $description = 'Delete checkout form data older than 24 hours across all active tenants';

    public function handle(TenantCredentialResolver $tenantResolver)
    {
        $stores = DB::connection('central')
            ->table('stores')
            ->where('provisioning_status', 'active')
            ->whereNotNull('store_hash')
            ->select('store_hash')
            ->get();

        $cutoff = now()->subHours(24);

        $totalDeleted = 0;
        $processedStores = 0;
        $failedStores = 0;

        $this->info("Found {$stores->count()} active stores.");

        foreach ($stores as $store) {
            try {
                $tenantResolver->connectByStoreHash($store->store_hash);

                $deleted = CheckoutFormData::where(
                    'updated_at',
                    '<',
                    $cutoff
                )
                    ->whereNotNull('session_id')
                    ->delete();

                $totalDeleted += $deleted;
                $processedStores++;

                $this->info(
                    "Store {$store->store_hash}: Deleted {$deleted} records."
                );
            } catch (Throwable $e) {
                $failedStores++;

                $this->error(
                    "Store {$store->store_hash} failed: {$e->getMessage()}"
                );
            } finally {
                $tenantResolver->disconnect();
            }
        }

        $this->newLine();

        $this->info("Cleanup completed.");
        $this->info("Processed stores: {$processedStores}");
        $this->info("Failed stores: {$failedStores}");
        $this->info("Total deleted records: {$totalDeleted}");

        return $failedStores > 0 ? self::FAILURE : self::SUCCESS;
    }
}