<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CopyStoresToCentralRegistry extends Command
{
    protected $signature = 'stores:copy-to-central';

    protected $description =
        'Copy existing store records into the central registry';

    public function handle(): int
    {
        $legacyConnection = DB::connection('mysql');
        $centralConnection = DB::connection('central');

        if (
            !Schema::connection('mysql')->hasTable('stores') ||
            !Schema::connection('central')->hasTable('stores')
        ) {
            $this->error(
                'The legacy or central stores table does not exist.'
            );

            return self::FAILURE;
        }

        $legacyDatabase = $legacyConnection->getDatabaseName();
        $centralDatabase = $centralConnection->getDatabaseName();

        if ($legacyDatabase === $centralDatabase) {
            $this->error(
                'Legacy and central connections point to the same database.'
            );

            return self::FAILURE;
        }

        $legacyColumns = Schema::connection('mysql')
            ->getColumnListing('stores');

        foreach (['id', 'store_hash', 'name', 'base_url', 'user_id'] as $column) {
            if (!in_array($column, $legacyColumns, true)) {
                $this->error(
                    "Legacy stores table is missing column: {$column}"
                );

                return self::FAILURE;
            }
        }

        $this->info("Source database: {$legacyDatabase}");
        $this->info("Central database: {$centralDatabase}");

        $count = 0;

        $legacyConnection->table('stores')
            ->orderBy('id')
            ->chunk(100, function ($stores) use (
                $centralConnection,
                &$count
            ) {
                foreach ($stores as $store) {
                    $hash = $store->store_hash;

                    if (
                        !is_string($hash) ||
                        !preg_match('/^store-[a-z0-9]{12}$/', $hash)
                    ) {
                        throw new \RuntimeException(
                            "Invalid store hash for legacy store ID {$store->id}."
                        );
                    }

                    $existingByLegacyId = $centralConnection
                        ->table('stores')
                        ->where('legacy_store_id', $store->id)
                        ->first();

                    $existingByHash = $centralConnection
                        ->table('stores')
                        ->where('store_hash', $hash)
                        ->first();

                    if ($existingByLegacyId || $existingByHash) {
                        if (
                            $existingByLegacyId &&
                            $existingByHash &&
                            $existingByLegacyId->id === $existingByHash->id &&
                            (int) $existingByLegacyId->legacy_store_id ===
                                (int) $store->id &&
                            $existingByLegacyId->store_hash === $hash
                        ) {
                            // Safe to rerun: this record was already copied.
                            continue;
                        }

                        throw new \RuntimeException(
                            "Central registry conflict for legacy store ID {$store->id}."
                        );
                    }

                    $record = [
                        'legacy_store_id' => $store->id,
                        'user_id' => $store->user_id,
                        'store_hash' => $hash,
                        'name' => $store->name,
                        'base_url' => $store->base_url,
                        'tenant_database' => null,
                        'provisioning_status' => 'pending',
                    ];

                    $legacyValues = (array) $store;

                    foreach (['created_at', 'updated_at'] as $timestamp) {
                        if (isset($legacyValues[$timestamp])) {
                            $record[$timestamp] = $legacyValues[$timestamp];
                        }
                    }

                    $centralConnection->table('stores')->insert($record);

                    $count++;
                    $this->line(
                        "Copied legacy store ID {$store->id}."
                    );
                }
            });

        $this->info(
            "Store registry copy completed. New records copied: {$count}"
        );

        return self::SUCCESS;
    }
}
