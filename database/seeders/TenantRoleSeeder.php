<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TenantRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::connection('tenant')
            ->table('roles')
            ->updateOrInsert(
                ['user_role_id' => 2],
                [
                    'name' => 'Customer',
                    'slug' => 'customer',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
    }
}
