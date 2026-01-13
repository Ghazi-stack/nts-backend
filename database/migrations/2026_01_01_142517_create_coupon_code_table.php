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
        Schema::create('coupon_code', function (Blueprint $table) {

            $table->id();

            $table->unsignedBigInteger('store_id');
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');

            $table->string('appliesToCategories')->nullable();
            $table->string('appliesToProducts')->nullable();
            $table->string('categoryIds')->nullable();
            $table->string('productIds')->nullable();
            $table->string('couponCode')->nullable();
            $table->string('couponName')->nullable();
            $table->string('discountAmount')->nullable();
            $table->string('discountType')->nullable();
            $table->string('enabled')->nullable();
            $table->string('excludeCartDiscounts')->nullable();
            $table->string('expiration')->nullable();
            $table->string('limitByLocation')->nullable();
            $table->string('limitByShipping')->nullable();
            $table->string('limitTotalUses')->nullable();
            $table->string('limitUsesPerCustomer')->nullable();
            $table->string('minimumPurchase')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coupon_code');
    }
};
