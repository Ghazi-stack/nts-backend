<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        $legacy = DB::connection('mysql');
        $central = DB::connection('central');

        $addMembership = function (int $userId, int $storeId) use ($central) {
            $exists = $central->table('store_user')
                ->where('user_id', $userId)
                ->where('store_id', $storeId)
                ->exists();

            if (!$exists) {
                $central->table('store_user')->insert([
                    'user_id' => $userId,
                    'store_id' => $storeId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        };

        // 1. Migrate ownership memberships using email matching.
        $legacy->table('stores as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->where('u.userRole', '!=', 2)
            ->whereNotNull('u.email')
            ->select('s.id as legacy_store_id', 'u.email')
            ->orderBy('s.id')
            ->get()
            ->each(function ($row) use ($central, $addMembership) {
                $user = $central->table('users')
                    ->where('email', $row->email)
                    ->where('userRole', '!=', 2)
                    ->first();

                $store = $central->table('stores')
                    ->where('legacy_store_id', $row->legacy_store_id)
                    ->first();

                if ($user && $store) {
                    $addMembership((int) $user->id, (int) $store->id);
                }
            });

        // 2. Migrate permission-based memberships.
        $legacy->table('user_permissions as up')
            ->join('users as u', 'u.id', '=', 'up.user_id')
            ->where('u.userRole', '!=', 2)
            ->whereNotNull('u.email')
            ->select('up.store_id as legacy_store_id', 'u.email')
            ->distinct()
            ->get()
            ->each(function ($row) use ($central, $addMembership) {
                $user = $central->table('users')
                    ->where('email', $row->email)
                    ->where('userRole', '!=', 2)
                    ->first();

                $store = $central->table('stores')
                    ->where('legacy_store_id', $row->legacy_store_id)
                    ->first();

                if ($user && $store) {
                    $addMembership((int) $user->id, (int) $store->id);
                }
            });
    }

    public function down(): void
    {
        // Intentionally non-destructive.
    }
};
