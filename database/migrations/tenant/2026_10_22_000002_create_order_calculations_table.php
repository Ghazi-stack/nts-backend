<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_calculations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('store_id')->index();
            $table->unsignedBigInteger('currency_id')->nullable();
            $table->decimal('amount_decimal', 15, 6)->default(0);
            $table->integer('amount_cents')->default(0);
            $table->decimal('shipping_cost', 15, 6)->default(0);
            $table->decimal('manual_discount', 15, 6)->default(0);
            $table->decimal('converted_coupon_discount', 15, 6)->default(0);
            $table->json('currency')->nullable();
            $table->json('coupon')->nullable();
            $table->json('matched_rate')->nullable();
            $table->json('items')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_calculations');
    }
};
