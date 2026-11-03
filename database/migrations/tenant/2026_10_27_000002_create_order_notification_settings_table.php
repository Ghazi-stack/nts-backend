<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create order notification settings table
     */
    public function up(): void
    {
        Schema::create('order_notification_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->unique();
            $table->string('email_address')->nullable();
            $table->boolean('email_enabled')->default(true);
            $table->json('notify_on_statuses')->nullable(); // array of selected statuses
            $table->timestamps();

            $table->index('store_id');
        });
    }

    /**
     * Rollback
     */
    public function down(): void
    {
        Schema::dropIfExists('order_notification_settings');
    }
};
