<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->table('stores', function (Blueprint $table) {
            $table->string('store_hash', 18)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Keep the column nullable to protect existing data.
    }
};