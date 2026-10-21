<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create(
            'stores',
            function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('legacy_store_id')
                    ->nullable()
                    ->unique();

                $table->unsignedBigInteger('user_id')
                    ->nullable()
                    ->index();

                $table->string('store_hash', 18)->unique();
                $table->string('name')->nullable();
                $table->string('base_url')->nullable();

                $table->string('tenant_database', 64)
                    ->nullable()
                    ->unique();

                $table->string('provisioning_status', 30)
                    ->default('pending');

                $table->timestamps();
            }
        );
    }

    public function down(): void
    {
        // Preserve central registry data during rollback.
    }
};