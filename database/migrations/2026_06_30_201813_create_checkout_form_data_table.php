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
        Schema::create('checkout_form_data', function (Blueprint $table) {
            // Primary Key
            $table->id();

            // Current Store ID
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();

            // Logged-in User ID (NULL for guest users)
            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Guest browser/session ID (NULL for logged-in users)
            $table->string('session_id')->nullable()->index();

            // Billing form data stored as JSON
            $table->json('billing_form_data')->nullable();

            // Shipping form data stored as JSON
            $table->json('shipping_form_data')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checkout_form_data');
    }
};
