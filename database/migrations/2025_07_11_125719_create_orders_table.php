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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('session_id')->nullable(); // guest checkout
            $table->string('name');
            $table->string('email');
            $table->string('phone', 20);
            $table->string('address');
            $table->string('payment_method');
            $table->string('shipping_method');
            $table->decimal('shipping_cost', 10, 2)->default(0);
            $table->decimal('total_amount', 18, 6);
            $table->text('comments')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
