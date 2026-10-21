<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $schema = Schema::connection('central');

        $schema->table('stores', function (Blueprint $table) use ($schema) {
            if (!$schema->hasColumn('stores', 'db_username')) {
                $table->string('db_username', 128)->nullable();
            }

            if (!$schema->hasColumn('stores', 'db_password_encrypted')) {
                $table->text('db_password_encrypted')->nullable();
            }

            if (!$schema->hasColumn('stores', 'db_host')) {
                $table->string('db_host', 255)->nullable();
            }

            if (!$schema->hasColumn('stores', 'db_port')) {
                $table->unsignedSmallInteger('db_port')->default(3306);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('central_stores', function (Blueprint $table) {
            //
        });
    }
};
