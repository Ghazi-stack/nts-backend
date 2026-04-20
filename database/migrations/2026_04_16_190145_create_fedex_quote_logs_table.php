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
        Schema::create('fedex_quote_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fedex_configuration_id')->constrained('fedex_configurations')->onDelete('cascade');
            $table->string('origin_postal_code')->nullable();
            $table->string('origin_country_code')->nullable();
            $table->string('destination_postal_code');
            $table->string('destination_country_code');
            $table->string('destination_state')->nullable();
            $table->string('destination_city')->nullable();
            $table->decimal('total_weight', 10, 2);
            $table->string('weight_unit')->default('LB');
            $table->decimal('package_length', 10, 2)->nullable();
            $table->decimal('package_width', 10, 2)->nullable();
            $table->decimal('package_height', 10, 2)->nullable();
            $table->string('dimension_unit')->default('IN');
            $table->decimal('package_value', 10, 2)->nullable();
            $table->json('rate_response')->nullable();
            $table->string('status')->default('success');
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fedex_quote_logs');
    }
};
