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
        Schema::create('coupon_usages', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('store_id');

            // Logged-in customer
            $table->unsignedBigInteger('user_id')->nullable();

            // Guest customer
            $table->string('session_id')->nullable();

            // Coupon
            $table->unsignedBigInteger('coupon_id');
            $table->string('coupon_code');

            $table->timestamps();

            $table->index(['store_id', 'user_id']);
            $table->index(['store_id', 'session_id']);
            $table->index(['store_id', 'coupon_id']);

            $table->foreign('coupon_id')
                ->references('id')
                ->on('coupon_code')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coupon_usages');
    }
};
