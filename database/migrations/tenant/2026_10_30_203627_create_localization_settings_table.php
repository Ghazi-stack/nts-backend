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
        Schema::create('localization_settings', function (Blueprint $table) {
            $table->id();
            // Tenant legacy store ID
            $table->unsignedBigInteger('store_id')->unique();

            $table->json('country')->nullable();
            $table->json('physical_dimensions')->nullable();
            $table->json('date_timezone')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('localization_settings');
    }
};
