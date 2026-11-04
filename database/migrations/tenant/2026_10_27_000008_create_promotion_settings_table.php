<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create promotion settings table
     */
    public function up(): void
    {
        Schema::create('promotion_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->unique();

            $table->boolean('calculate_independently')->default(true);
            $table->boolean('calculate_cumulatively')->default(false);
            $table->unsignedInteger('number_of_coupons_allowed')->default(1);
            $table->boolean('allow_zero_price_products')->default(false);
            $table->boolean('allow_custom_price_overrides')->default(false);

            $table->timestamps();
            $table->index('store_id');
        });
    }

    /**
     * Rollback
     */
    public function down(): void
    {
        Schema::dropIfExists('promotion_settings');
    }
};
