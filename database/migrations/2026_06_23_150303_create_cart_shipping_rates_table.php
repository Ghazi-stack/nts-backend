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
        Schema::create('cart_shipping_rates', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('session_id')->nullable();

            $table->json('cart_ids')->nullable();

            $table->string('country');
            $table->string('state');
            $table->string('city');
            $table->string('zip');

            // Rate
            $table->string('service_type')->nullable(); // ← new
            $table->string('method_type');
            $table->decimal('total_charge', 10, 2);

            $table->timestamps();

            $table->index(['store_id']);
            $table->index(['user_id']);
            $table->index(['session_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cart_shipping_rates');
    }
};
