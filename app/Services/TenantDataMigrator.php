<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class TenantDataMigrator
{
    private array $excludedTables = [
        'stores',
        'store_user',
        'staff_logs',
        'permissions',
        'user_permissions',
        'plans',
        'store_subscriptions',
        'jobs',
        'failed_jobs',
        'migrations',
    ];

    private array $parentAliases = [
        'user' => 'users',
        'customer' => 'customers',
        'product' => 'products',
        'order' => 'orders',
        'category' => 'categories',
        'brand' => 'brands',
        'role' => 'roles',
        'coupon' => 'coupon_code',
        'billing_information' => 'billing_information',
    ];

    /**
     * Read-only preview. Uses the same row-selection logic as the actual import.
     */
    public function previewStoreData(int $registryStoreId): array
    {
        $selection = $this->collectStoreData($registryStoreId);

        $tableCounts = [];
        $totalRows = 0;

        foreach ($selection['selected'] as $table => $rows) {
            $count = count($rows);

            if ($count > 0) {
                $tableCounts[$table] = $count;
                $totalRows += $count;
            }
        }

        return [
            'legacy_store_id' => $selection['legacy_store_id'],
            'tables' => count($tableCounts),
            'rows' => $totalRows,
            'table_counts' => $tableCounts,
            'source_database' => $selection['source_database'],
            'target_database' => $selection['target_database'],
        ];
    }

    /**
     * Copy all selected rows into the tenant database.
     * Source records are only read and are never modified or deleted.
     */
    public function migrateStoreData(int $registryStoreId): array
    {
        $selection = $this->collectStoreData($registryStoreId);

        if ($selection['legacy_store_id'] === null) {
            return [
                'legacy_store_id' => null,
                'tables' => 0,
                'rows' => 0,
            ];
        }

        $target = DB::connection('tenant');
        $selected = $selection['selected'];
        $sourceColumns = $selection['source_columns'];
        $targetColumns = $selection['target_columns'];

        // Refuse to mix this import with pre-existing application data.
        foreach ($selected as $table => $rows) {
            if ($rows && $target->table($table)->exists()) {
                throw new RuntimeException(
                    "Tenant table '{$table}' already contains data. "
                    . 'Import stopped to prevent duplicate records.'
                );
            }
        }

        $rowCount = 0;
        $target->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($selected as $table => $rowsByKey) {
                if (!$rowsByKey) {
                    continue;
                }

                $columns = array_values(array_intersect(
                    $sourceColumns[$table],
                    $targetColumns[$table]
                ));

                $batch = [];

                foreach ($rowsByKey as $row) {
                    $record = [];

                    foreach ($columns as $column) {
                        if (array_key_exists($column, $row)) {
                            $record[$column] = $row[$column];
                        }
                    }

                    $batch[] = $record;

                    if (count($batch) >= 500) {
                        $target->table($table)->insert($batch);
                        $rowCount += count($batch);
                        $batch = [];
                    }
                }

                if ($batch) {
                    $target->table($table)->insert($batch);
                    $rowCount += count($batch);
                }
            }
        } finally {
            $target->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        return [
            'legacy_store_id' => $selection['legacy_store_id'],
            'tables' => count(array_filter($selected)),
            'rows' => $rowCount,
        ];
    }

    /**
     * Collect all rows owned by the legacy store plus related rows discovered
     * through conventional *_id relationships. This method performs reads only.
     */
    private function collectStoreData(int $registryStoreId): array
    {
        $central = DB::connection('central');
        $source = DB::connection('mysql');
        $target = DB::connection('tenant');

        $store = $central->table('stores')
            ->where('id', $registryStoreId)
            ->first();

        if (!$store) {
            throw new RuntimeException('Central store was not found.');
        }

        $legacyStoreId = (int) ($store->legacy_store_id ?? 0);

        if ($legacyStoreId < 1) {
            return [
                'legacy_store_id' => null,
                'selected' => [],
                'source_columns' => [],
                'target_columns' => [],
                'source_database' => $source->getDatabaseName(),
                'target_database' => $target->getDatabaseName(),
            ];
        }

        $sourceDatabase = $source->getDatabaseName();
        $targetDatabase = $target->getDatabaseName();

        if (
            !$sourceDatabase
            || !$targetDatabase
            || $sourceDatabase === $targetDatabase
            || $targetDatabase === 'ecom'
        ) {
            throw new RuntimeException(
                'Invalid source or tenant database configuration.'
            );
        }

        $sourceTables = $source->select(
            'SELECT TABLE_NAME
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ?
               AND TABLE_TYPE = ?',
            [$sourceDatabase, 'BASE TABLE']
        );

        $targetTables = $target->select(
            'SELECT TABLE_NAME
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ?
               AND TABLE_TYPE = ?',
            [$targetDatabase, 'BASE TABLE']
        );

        $targetTableNames = array_fill_keys(
            array_map(fn ($row) => $row->TABLE_NAME, $targetTables),
            true
        );

        $sourceColumns = [];
        $targetColumns = [];
        $selected = [];
        $selectedIds = [];

        foreach ($sourceTables as $info) {
            $table = $info->TABLE_NAME;

            if (
                in_array($table, $this->excludedTables, true)
                || !isset($targetTableNames[$table])
            ) {
                continue;
            }

            $sourceColumns[$table] = Schema::connection('mysql')
                ->getColumnListing($table);

            $targetColumns[$table] = Schema::connection('tenant')
                ->getColumnListing($table);

            $selected[$table] = [];
        }

        // Build candidate relationships from conventional *_id columns.
        $edges = [];

        foreach ($sourceColumns as $child => $columns) {
            foreach ($columns as $column) {
                if (
                    !str_ends_with($column, '_id')
                    || in_array($column, ['id', 'store_id', 'legacy_store_id'], true)
                ) {
                    continue;
                }

                $base = substr($column, 0, -3);

                $candidates = [
                    $this->parentAliases[$base] ?? null,
                    $base,
                    $base . 's',
                ];

                foreach (array_unique(array_filter($candidates)) as $parent) {
                    if (
                        $parent !== $child
                        && isset($sourceColumns[$parent])
                        && in_array('id', $sourceColumns[$parent], true)
                        && !in_array($parent, $this->excludedTables, true)
                    ) {
                        $edges[] = [
                            'child' => $child,
                            'column' => $column,
                            'parent' => $parent,
                        ];
                        break;
                    }
                }
            }
        }

        // Seed all rows directly assigned to this store, with no row limit.
        foreach ($sourceColumns as $table => $columns) {
            if (!in_array('store_id', $columns, true)) {
                continue;
            }

            $rows = $source->table($table)
                ->where('store_id', $legacyStoreId)
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all();

            $this->addRows(
                $table,
                $rows,
                $sourceColumns,
                $targetColumns,
                $selected,
                $selectedIds
            );
        }

        /*
         * Expand until no new rows are found:
         * 1. selected parents -> related children;
         * 2. selected children -> referenced parents.
         *
         * For child tables with their own store_id, keep the store filter.
         * Parent tables that have store_id are only imported for this store.
         */
        $changed = true;
        $iterations = 0;

        while ($changed) {
            $changed = false;

            if (++$iterations > 100) {
                throw new RuntimeException(
                    'Relationship discovery exceeded its safety limit.'
                );
            }

            foreach ($edges as $edge) {
                $child = $edge['child'];
                $column = $edge['column'];
                $parent = $edge['parent'];

                // Parent -> child. Only constrain by the current edge here;
                // unrelated foreign keys must not accidentally exclude rows.
                $parentIds = array_keys($selectedIds[$parent] ?? []);

                foreach (array_chunk($parentIds, 500) as $chunk) {
                    $query = $source->table($child)->whereIn($column, $chunk);

                    if (in_array('store_id', $sourceColumns[$child], true)) {
                        $query->where('store_id', $legacyStoreId);
                    }

                    $rows = $query->get()
                        ->map(fn ($row) => (array) $row)
                        ->all();

                    $before = count($selected[$child] ?? []);

                    $this->addRows(
                        $child,
                        $rows,
                        $sourceColumns,
                        $targetColumns,
                        $selected,
                        $selectedIds
                    );

                    if (count($selected[$child] ?? []) > $before) {
                        $changed = true;
                    }
                }

                // Child -> parent. Only bring a tenant-owned parent when it
                // belongs to this store; global/shared parent rows can be copied
                // when referenced by a selected row.
                $referencedIds = [];

                foreach ($selected[$child] ?? [] as $row) {
                    if (isset($row[$column]) && $row[$column] !== null) {
                        $referencedIds[(string) $row[$column]] = true;
                    }
                }

                $missingIds = array_diff_key(
                    $referencedIds,
                    $selectedIds[$parent] ?? []
                );

                foreach (array_chunk(array_keys($missingIds), 500) as $chunk) {
                    $query = $source->table($parent)->whereIn('id', $chunk);

                    if (in_array('store_id', $sourceColumns[$parent], true)) {
                        $query->where('store_id', $legacyStoreId);
                    }

                    $rows = $query->get()
                        ->map(fn ($row) => (array) $row)
                        ->all();

                    $before = count($selected[$parent] ?? []);

                    $this->addRows(
                        $parent,
                        $rows,
                        $sourceColumns,
                        $targetColumns,
                        $selected,
                        $selectedIds
                    );

                    if (count($selected[$parent] ?? []) > $before) {
                        $changed = true;
                    }
                }
            }
        }

        // Exclude duplicate product SKUs before import. The tenant schema
        // enforces UNIQUE(store_id, sku), so only the first selected product
        // for each non-empty store_id + sku pair is imported.
        if (isset($selected['products'])) {
            $seenProductSkus = [];
            $deduplicatedProducts = [];

            foreach ($selected['products'] as $identity => $product) {
                $sku = isset($product['sku']) ? trim((string) $product['sku']) : '';
                $storeId = $product['store_id'] ?? null;

                // Keep rows with blank SKUs; the unique-key collision applies
                // only to non-empty SKU values.
                if ($sku !== '') {
                    $skuKey = (string) $storeId . "\\0" . mb_strtolower($sku);

                    if (isset($seenProductSkus[$skuKey])) {
                        // Exclude this duplicate product row from the import.
                        continue;
                    }

                    $seenProductSkus[$skuKey] = true;
                }

                $deduplicatedProducts[$identity] = $product;
            }

            $selected['products'] = $deduplicatedProducts;
        }

        return [
            'legacy_store_id' => $legacyStoreId,
            'selected' => $selected,
            'source_columns' => $sourceColumns,
            'target_columns' => $targetColumns,
            'source_database' => $sourceDatabase,
            'target_database' => $targetDatabase,
        ];
    }

    private function addRows(
        string $table,
        array $rows,
        array $sourceColumns,
        array $targetColumns,
        array &$selected,
        array &$selectedIds
    ): void {
        $commonColumns = array_values(array_intersect(
            $sourceColumns[$table],
            $targetColumns[$table]
        ));

        foreach ($rows as $row) {
            $identity = isset($row['id'])
                ? 'id:' . (string) $row['id']
                : 'row:' . hash(
                    'sha256',
                    json_encode(
                        array_intersect_key($row, array_flip($commonColumns)),
                        JSON_PARTIAL_OUTPUT_ON_ERROR
                    )
                );

            if (isset($selected[$table][$identity])) {
                continue;
            }

            $selected[$table][$identity] = $row;

            if (isset($row['id'])) {
                $selectedIds[$table][(string) $row['id']] = true;
            }
        }
    }
}
