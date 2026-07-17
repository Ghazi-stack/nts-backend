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
        Schema::create('order_timelines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('store_id');
            $table->string('event_type'); // order_created, status_changed, payment_captured, shipment_created, etc.
            $table->string('title');
            $table->json('details')->nullable();
            $table->string('icon')->default('dot'); // dot, email, payment, shipping
            $table->timestamps();

            $table->index(['order_id', 'store_id']);
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_timelines');
    }
};
