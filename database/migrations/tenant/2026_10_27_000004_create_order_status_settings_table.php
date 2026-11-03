<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create order status settings table
     */
    public function up(): void
    {
        Schema::create('order_status_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('status_key');          // incomplete, pending, etc.
            $table->string('custom_name')->nullable(); // agar null to default name use hoga
            $table->timestamps();

            $table->unique(['store_id', 'status_key']);
            $table->index('store_id');
        });
    }

    /**
     * Rollback
     */
    public function down(): void
    {
        Schema::dropIfExists('order_status_settings');
    }
};
