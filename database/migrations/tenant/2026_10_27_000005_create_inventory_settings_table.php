<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create inventory settings table
     */
    public function up(): void
    {
        Schema::create('inventory_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->unique();

            // General
            $table->string('product_no_longer_sold')->default('do_nothing');
            $table->string('option_no_longer_sold')->default('do_nothing');
            $table->string('update_stock_levels')->default('when_order_placed');
            $table->boolean('auto_adjust_stock_on_refund_cancel')->default(false);

            // Stock level
            $table->string('stock_level_display')->default('dont_show');
            $table->string('out_of_stock_message')->default('Out of stock');
            $table->boolean('show_oos_on_listing')->default(false);

            // Backorder display
            $table->boolean('show_quantity_on_backorder')->default(false);
            $table->boolean('show_backorder_message')->default(false);
            $table->json('backorder_messages')->nullable(); // [{id, name, message, is_default}]

            // Advanced display options
            $table->boolean('show_quantity_on_hand')->default(false);
            $table->boolean('show_backorder_availability_prompt')->default(false);
            $table->string('more_available_for_backorder')->nullable();
            $table->boolean('show_shipping_expectation')->default(false);
            $table->string('shipping_expectation_message')->nullable();

            // Email notifications
            $table->string('low_stock_email')->nullable();
            $table->string('out_of_stock_email')->nullable();

            $table->timestamps();
            $table->index('store_id');
        });
    }

    /**
     * Rollback
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_settings');
    }
};
