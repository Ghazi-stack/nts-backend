<?php

namespace App\Console\Commands;

use App\Services\TenantCredentialResolver;
use App\Services\TransactionalEmailTemplateUpgrader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Upgrades stores' untouched transactional email templates from the
 * original demo-content defaults to the v2 templates that real emails use.
 *
 * Dry run by default; pass --apply to write. Templates a store owner
 * edited are never overwritten (they are listed as "customized").
 */
class UpgradeTransactionalEmailTemplates extends Command
{
    protected $signature = 'transactional-emails:upgrade
                            {--store= : Only this store (central store_hash)}
                            {--apply : Write changes (default is a dry run)}';

    protected $description = 'Upgrade untouched transactional email templates to v2 (dry run unless --apply)';

    public function handle(TransactionalEmailTemplateUpgrader $upgrader, TenantCredentialResolver $resolver): int
    {
        $apply = (bool) $this->option('apply');

        $stores = DB::connection('central')->table('stores')
            ->where('provisioning_status', 'active')
            ->whereNotNull('store_hash')
            ->when($this->option('store'), fn($q, $hash) => $q->where('store_hash', $hash))
            ->orderBy('id')
            ->get(['id', 'legacy_store_id', 'name', 'store_hash']);

        if ($stores->isEmpty()) {
            $this->warn('No active stores found.');
            return self::SUCCESS;
        }

        $this->info($apply ? 'Applying changes.' : 'Dry run: nothing will be written. Re-run with --apply to upgrade.');

        $failed = 0;

        foreach ($stores as $store) {
            // transactional_email_templates.store_id = legacy id, else registry id (same as the seeder).
            $templateStoreId = (int) ($store->legacy_store_id ?: $store->id);

            $this->line('');
            $this->info("Store #{$store->id} {$store->name} ({$store->store_hash}), template store_id {$templateStoreId}");

            try {
                $resolver->connectByStoreHash($store->store_hash);

                $report = $upgrader->upgradeStore($templateStoreId, $apply);

                $this->table(['Template', 'Result'], collect($report)->map(fn($r, $k) => [$k, $r])->values()->all());
            } catch (\Throwable $e) {
                $failed++;
                $this->error("  Failed: {$e->getMessage()}");
            } finally {
                $resolver->disconnect();
            }
        }

        $this->line('');
        $this->line('Results: upgraded / created = switched to v2; already_v2 = nothing to do;');
        $this->line('customized = owner edited the old template, left as is (use "Reset to default" in the dashboard).');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
