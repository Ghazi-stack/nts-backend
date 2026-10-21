<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

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

        foreach (['id', 'name', 'base_url', 'user_id'] as $column) {
            if (!in_array($column, $legacyColumns, true)) {
                $this->error(
                    "Legacy stores table is missing column: {$column}"
                );

                return self::FAILURE;
            }
        }

        $centralColumns = Schema::connection('central')
            ->getColumnListing('stores');

        foreach (
            [
                'legacy_store_id',
                'user_id',
                'store_hash',
                'name',
                'base_url',
                'tenant_database',
                'provisioning_status',
            ] as $column
        ) {
            if (!in_array($column, $centralColumns, true)) {
                $this->error(
                    "Central stores table is missing column: {$column}"
                );

                return self::FAILURE;
            }
        }

        $this->info("Source database: {$legacyDatabase}");
        $this->info("Central database: {$centralDatabase}");

        $count = 0;
        $skipped = 0;

        try {
            $legacyConnection->table('stores')
                ->orderBy('id')
                ->chunk(100, function ($stores) use (
                    $centralConnection,
                    $centralColumns,
                    &$count,
                    &$skipped
                ) {
                    foreach ($stores as $store) {
                        $legacyId = (int) $store->id;

                        // Look up the legacy ID first so reruns don't create
                        // duplicate registry records.
                        $existingByLegacyId = $centralConnection
                            ->table('stores')
                            ->where('legacy_store_id', $legacyId)
                            ->first();

                        $hash = isset($store->store_hash)
                            ? trim((string) $store->store_hash)
                            : '';

                        if ($hash !== '' &&
                            !preg_match('/^store-[a-z0-9]{12}$/', $hash)) {
                            $this->error(
                                "Invalid hash on legacy store ID {$legacyId}."
                            );

                            throw new RuntimeException(
                                "Invalid store hash for legacy store ID {$legacyId}."
                            );
                        }

                        if ($existingByLegacyId) {
                            // Preserve the existing central hash. If it is
                            // missing/invalid, the backfill command can fix it.
                            if (
                                $hash !== '' &&
                                !empty($existingByLegacyId->store_hash) &&
                                $existingByLegacyId->store_hash !== $hash
                            ) {
                                throw new RuntimeException(
                                    "Hash conflict for legacy store ID {$legacyId}."
                                );
                            }

                            $skipped++;
                            continue;
                        }

                        // Reuse a valid source hash only if it isn't already
                        // assigned to another central store.
                        if ($hash !== '') {
                            $existingByHash = $centralConnection
                                ->table('stores')
                                ->where('store_hash', $hash)
                                ->first();

                            if ($existingByHash) {
                                throw new RuntimeException(
                                    "Store hash {$hash} is already assigned to central store ID {$existingByHash->id}."
                                );
                            }
                        } else {
                            // Generate a unique hash directly for the
                            // central record. Do not change the live record.
                            do {
                                $hash = 'store-' . strtolower(
                                    Str::random(12)
                                );

                                $hashExists = $centralConnection
                                    ->table('stores')
                                    ->where('store_hash', $hash)
                                    ->exists();
                            } while ($hashExists);
                        }

                        $legacyValues = (array) $store;

                        $record = [
                            'legacy_store_id' => $legacyId,
                            'user_id' => $store->user_id,
                            'store_hash' => $hash,
                            'name' => $store->name,
                            'base_url' => $store->base_url,
                            'tenant_database' => null,
                            'provisioning_status' => 'pending',
                        ];

                        foreach (['created_at', 'updated_at'] as $timestamp) {
                            if (
                                in_array($timestamp, $centralColumns, true) &&
                                isset($legacyValues[$timestamp])
                            ) {
                                $record[$timestamp] = $legacyValues[$timestamp];
                            }
                        }

                        // A central registry insert is isolated per store.
                        // Existing live store data is never modified.
                        $centralConnection->transaction(function () use (
                            $centralConnection,
                            $record
                        ) {
                            $centralConnection->table('stores')
                                ->insert($record);
                        });

                        $count++;

                        $this->line(
                            "Copied legacy store ID {$legacyId} with hash {$hash}."
                        );
                    }
                });
        } catch (Throwable $exception) {
            report($exception);

            $this->error(
                'Store registry copy failed: ' . $exception->getMessage()
            );

            $this->warn(
                'Any previously inserted central records remain in place. ' .
                'Correct the reported issue and rerun this command.'
            );

            return self::FAILURE;
        }

        $this->info("New records copied: {$count}");
        $this->info("Existing records skipped: {$skipped}");
        $this->info('Store registry copy completed successfully.');

        return self::SUCCESS;
    }
}