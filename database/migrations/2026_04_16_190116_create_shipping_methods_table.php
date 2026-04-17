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
        Schema::create('shipping_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_zone_id')->constrained('shipping_zones')->onDelete('cascade');
            $table->string('method_type');                    // free_shipping, flat_rate, flat_rate_weight, fedex_realtime, custom_label
            $table->string('display_name');                   // "Free Shipping", "Flat Rate", "FedEx", etc.
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);

            // -- Flat Rate fields --
            $table->decimal('flat_rate_amount', 10, 2)->nullable();       // e.g. 10.00
            $table->string('flat_rate_type')->nullable();                  // per_order, per_item

            // -- Flat Rate for Weight-based --
            $table->decimal('weight_threshold', 10, 2)->nullable();      // e.g. 10 LBS
            $table->decimal('weight_rate_amount', 10, 2)->nullable();    // rate for under threshold

            // -- Free Shipping conditions --
            $table->decimal('free_shipping_min_order', 10, 2)->nullable(); // minimum order for free shipping

            // -- Custom Label / "I will provide shipping" --
            $table->text('custom_description')->nullable();               // description text

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipping_methods');
    }
};
