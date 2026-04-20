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
        Schema::create('fedex_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_method_id')->constrained('shipping_methods')->onDelete('cascade');
            $table->string('client_id');
            $table->text('client_secret');
            $table->string('account_number');
            $table->string('api_base_url')->default('https://apis-sandbox.fedex.com');
            $table->string('ws_key')->nullable();
            $table->text('ws_password')->nullable();
            $table->string('meter_number')->nullable();
            $table->string('display_name')->default('FedEx');
            $table->string('dropoff_type')->default('DROPOFF_AT_FEDEX_LOCATION');
            $table->string('packaging_type')->default('YOUR_PACKAGING');
            $table->string('packing_method')->default('ALL_IN_ONE');
            $table->string('rate_type')->default('LIST');
            $table->boolean('include_package_value')->default(true);
            $table->string('destination_type')->default('COMMERCIAL');
            $table->json('enabled_services')->nullable();
            $table->boolean('is_connected')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fedex_configurations');
    }
};
