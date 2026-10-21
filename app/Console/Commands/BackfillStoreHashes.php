<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackfillStoreHashes extends Command
{
    protected $signature = 'stores:backfill-hashes';

    protected $description =
        'Generate unique store hashes for existing stores';

    public function handle(): int
    {
        $connection = DB::connection('central');

        $stores = $connection->table('stores')
            ->select('id', 'store_hash')
            ->orderBy('id')
            ->get();

        $usedHashes = [];

        // Reserve existing correctly formatted hashes.
        // These will remain unchanged.
        foreach ($stores as $store) {
            $existingHash = $store->store_hash;

            if (
                is_string($existingHash) &&
                preg_match('/^store-[a-z0-9]{12}$/', $existingHash)
            ) {
                if (isset($usedHashes[$existingHash])) {
                    $this->error(
                        "Duplicate store hash found: {$existingHash}"
                    );

                    return self::FAILURE;
                }

                $usedHashes[$existingHash] = true;
            }
        }

        // Update only hashes that are missing or incorrectly formatted.
        foreach ($stores as $store) {
            $existingHash = $store->store_hash;

            if (
                is_string($existingHash) &&
                preg_match('/^store-[a-z0-9]{12}$/', $existingHash)
            ) {
                continue;
            }

            do {
                $hash = 'store-' . strtolower(Str::random(12));
            } while (isset($usedHashes[$hash]));

            $updated = $connection->table('stores')
                ->where('id', $store->id)
                ->update(['store_hash' => $hash]);

            if ($updated === 0) {
                $this->error(
                    "Could not update store ID {$store->id}."
                );

                return self::FAILURE;
            }

            $usedHashes[$hash] = true;

            $this->info(
                "Updated store ID {$store->id}: {$hash}"
            );
        }

        $this->info('Store hash backfill completed successfully.');

        return self::SUCCESS;
    }
}