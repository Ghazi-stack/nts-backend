<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        $centralSchema = Schema::connection('central');

        // 1. Create the central roles table.
        if (!$centralSchema->hasTable('roles')) {
            $centralSchema->create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug');
                $table->unsignedBigInteger('user_role_id')->index();
                $table->timestamps();
            });
        }

        $source = DB::connection('mysql');
        $central = DB::connection('central');

        // 2. Copy role definitions, preserving IDs.
        $source->table('roles')
            ->orderBy('id')
            ->chunkById(200, function ($roles) use ($central) {
                foreach ($roles as $role) {
                    $data = (array) $role;

                    if ($central->table('roles')
                        ->where('id', $role->id)->exists()) {
                        continue;
                    }

                    $central->table('roles')->insert($data);
                }
            });

        // 3. Copy permission definitions.
        $source->table('permissions')
            ->orderBy('id')
            ->chunkById(200, function ($permissions) use ($central) {
                foreach ($permissions as $permission) {
                    $data = (array) $permission;

                    $existingId = $central->table('permissions')
                        ->where('id', $permission->id)
                        ->value('id');

                    if ($existingId !== null) {
                        continue;
                    }

                    // Avoid conflicts with the central unique slug.
                    if ($central->table('permissions')
                        ->where('slug', $permission->slug)->exists()) {
                        continue;
                    }

                    $central->table('permissions')->insert($data);
                }
            });

        // 4. Copy staff permission assignments.
        // Map legacy permission IDs by slug and legacy store IDs
        // to central store registry IDs.
        $source->table('user_permissions')
            ->orderBy('id')
            ->chunkById(200, function ($assignments) use ($source, $central) {
                foreach ($assignments as $assignment) {
                    $userExists = $central->table('users')
                        ->where('id', $assignment->user_id)
                        ->where('userRole', '!=', 2)
                        ->exists();

                    if (!$userExists) {
                        continue;
                    }

                    $legacyPermission = $source->table('permissions')
                        ->where('id', $assignment->permission_id)
                        ->first();

                    if (!$legacyPermission) {
                        continue;
                    }

                    $permissionId = $central->table('permissions')
                        ->where('slug', $legacyPermission->slug)
                        ->value('id');

                    if (!$permissionId) {
                        continue;
                    }

                    $store = $central->table('stores')
                        ->where('legacy_store_id', $assignment->store_id)
                        ->first();

                    if (!$store) {
                        continue;
                    }

                    $alreadyExists = $central->table('user_permissions')
                        ->where('user_id', $assignment->user_id)
                        ->where('permission_id', $permissionId)
                        ->where('store_id', $store->id)
                        ->exists();

                    if ($alreadyExists) {
                        continue;
                    }

                    $central->table('user_permissions')->insert([
                        'user_id' => $assignment->user_id,
                        'permission_id' => $permissionId,
                        'store_id' => $store->id,
                        'created_at' => $assignment->created_at,
                        'updated_at' => $assignment->updated_at,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Intentionally non-destructive.
        // Do not delete central roles, permissions or assignments.
    }
};
