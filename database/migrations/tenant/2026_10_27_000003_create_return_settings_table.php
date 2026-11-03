<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create return settings table
     */
    public function up(): void
    {
        Schema::create('return_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->unique();

            $table->boolean('returns_enabled')->default(false);
            $table->text('return_instructions')->nullable();
            $table->json('return_reasons')->nullable();          // array of strings
            $table->json('return_actions')->nullable();          // array of strings
            $table->boolean('use_default_reasons_actions')->default(true);
            $table->boolean('allow_store_credits')->default(false);

            // Notification Settings
            $table->boolean('notify_new_return')->default(true);
            $table->boolean('notify_customer_confirmation')->default(true);
            $table->boolean('notify_status_change')->default(true);

            $table->timestamps();
            $table->index('store_id');
        });
    }

    /**
     * Rollback
     */
    public function down(): void
    {
       Schema::dropIfExists('return_settings');
    }
};
