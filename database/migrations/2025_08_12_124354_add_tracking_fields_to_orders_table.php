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
        Schema::table('orders', function (Blueprint $table) {
            $table->string('tracking_number')->nullable()->index();
            $table->string('tracking_carrier_code')->nullable();
            $table->string('tracking_carrier_name')->nullable();
            $table->timestamp('shipped_at')->nullable();

            // Faster lookups when order_number exists
            $table->index(['store_id', 'order_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'order_number']);
            $table->dropColumn([
                'tracking_number',
                'tracking_carrier_code',
                'tracking_carrier_name',
                'shipped_at',
            ]);
        });
    }
};
