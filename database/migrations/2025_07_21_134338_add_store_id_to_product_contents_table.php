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
        Schema::table('product_contents', function (Blueprint $table) {
            $table->unsignedBigInteger('store_id')->nullable()->after('id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_contents', function (Blueprint $table) {
            // You may want to drop the column if needed
            $table->dropColumn('store_id');
        });
    }
};
