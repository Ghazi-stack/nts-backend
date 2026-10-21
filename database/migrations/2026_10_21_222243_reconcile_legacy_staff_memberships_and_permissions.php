<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        $legacy = DB::connection('mysql');
        $central = DB::connection('central');

        foreach (['users', 'stores', 'user_permissions', 'permissions'] as $table) {
            if (!Schema::connection('mysql')->hasTable($table)) {
                throw new RuntimeException(
                    "Legacy table {$table} does not exist."
                );
            }
        }

        foreach (['users', 'stores', 'store_user', 'user_permissions', 'permissions'] as $table) {
            if (!Schema::connection('central')->hasTable($table)) {
                throw new RuntimeException(
                    "Central table {$table} does not exist."
                );
            }
        }

        $findCentralStaff = function ($email) use ($central) {
            if (!is_string($email) || trim($email) === '') {
                return null;
            }

            return $central->table('users')
                ->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])
                ->where('userRole', '!=', 2)
                ->first();
        };

        $findCentralStore = function ($legacyStoreId) use ($central) {
            return $central->table('stores')
                ->where('legacy_store_id', $legacyStoreId)
                ->first();
        };

        $addMembership = function (
            int $userId,
            int $storeId,
            $createdAt = null,
            $updatedAt = null
        ) use ($central) {
            $exists = $central->table('store_user')
                ->where('user_id', $userId)
                ->where('store_id', $storeId)
                ->exists();

            if (!$exists) {
                $central->table('store_user')->insert([
                    'user_id' => $userId,
                    'store_id' => $storeId,
                    'created_at' => $createdAt ?? now(),
                    'updated_at' => $updatedAt ?? now(),
                ]);
            }
        };

        /*
         * 1. Reconcile store ownership memberships.
         */
        $legacy->table('stores as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->where('u.userRole', '!=', 2)
            ->whereNotNull('u.email')
            ->select(
                's.id as legacy_store_id',
                's.created_at as store_created_at',
                's.updated_at as store_updated_at',
                'u.email'
            )
            ->orderBy('s.id')
            ->chunk(100, function ($rows) use (
                $findCentralStaff,
                $findCentralStore,
                $addMembership
            ) {
                foreach ($rows as $row) {
                    $user = $findCentralStaff($row->email);
                    $store = $findCentralStore($row->legacy_store_id);

                    if (!$user || !$store) {
                        continue;
                    }

                    $addMembership(
                        (int) $user->id,
                        (int) $store->id,
                        $row->store_created_at,
                        $row->store_updated_at
                    );
                }
            });

        /*
         * 2. Reconcile staff permission assignments.
         * Resolve user by email, permission by slug, and store by legacy ID.
         */
        $legacy->table('user_permissions')
            ->orderBy('id')
            ->chunkById(200, function ($assignments) use (
                $legacy,
                $central,
                $findCentralStaff,
                $findCentralStore,
                $addMembership
            ) {
                foreach ($assignments as $assignment) {
                    $legacyUser = $legacy->table('users')
                        ->where('id', $assignment->user_id)
                        ->where('userRole', '!=', 2)
                        ->whereNotNull('email')
                        ->first();

                    if (!$legacyUser) {
                        continue;
                    }

                    $centralUser = $findCentralStaff($legacyUser->email);

                    if (!$centralUser) {
                        continue;
                    }

                    $legacyPermission = $legacy->table('permissions')
                        ->where('id', $assignment->permission_id)
                        ->first();

                    if (!$legacyPermission) {
                        continue;
                    }

                    $centralPermission = $central->table('permissions')
                        ->where('slug', $legacyPermission->slug)
                        ->first();

                    if (!$centralPermission) {
                        continue;
                    }

                    $store = $findCentralStore($assignment->store_id);

                    if (!$store) {
                        continue;
                    }

                    $centralUserId = (int) $centralUser->id;
                    $centralStoreId = (int) $store->id;
                    $centralPermissionId = (int) $centralPermission->id;

                    // Ensure staff can access stores assigned by permissions.
                    $addMembership(
                        $centralUserId,
                        $centralStoreId,
                        $assignment->created_at ?? null,
                        $assignment->updated_at ?? null
                    );

                    $exists = $central->table('user_permissions')
                        ->where('user_id', $centralUserId)
                        ->where('permission_id', $centralPermissionId)
                        ->where('store_id', $centralStoreId)
                        ->exists();

                    if (!$exists) {
                        $central->table('user_permissions')->insert([
                            'user_id' => $centralUserId,
                            'permission_id' => $centralPermissionId,
                            'store_id' => $centralStoreId,
                            'created_at' => $assignment->created_at ?? now(),
                            'updated_at' => $assignment->updated_at ?? now(),
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Intentionally non-destructive.
        // Do not delete reconciled central relationships on rollback.
    }
};