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
            $table->string('method_type');
            $table->string('display_name');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->decimal('flat_rate_amount', 10, 2)->nullable();
            $table->string('flat_rate_type')->nullable();
            $table->decimal('weight_threshold', 10, 2)->nullable();
            $table->decimal('weight_rate_amount', 10, 2)->nullable();
            $table->decimal('free_shipping_min_order', 10, 2)->nullable();
            $table->text('custom_description')->nullable();
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
