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

            // -- Connection credentials --
            $table->string('client_id');                          // OAuth2 Client ID
            $table->text('client_secret');                        // OAuth2 Client Secret (encrypted)
            $table->string('account_number');                     // FedEx Account Number
            $table->string('api_base_url')->default('https://apis-sandbox.fedex.com'); // sandbox or production

            // -- Legacy Web Services credentials (BigCommerce style) --
            $table->string('ws_key')->nullable();                 // Web Services Key
            $table->text('ws_password')->nullable();              // Web Services Password (encrypted)
            $table->string('meter_number')->nullable();           // Meter Number

            // -- Settings --
            $table->string('display_name')->default('FedEx');
            $table->string('dropoff_type')->default('REGULAR_PICKUP');
            // REGULAR_PICKUP, REQUEST_COURIER, DROP_BOX, BUSINESS_SERVICE_CENTER, STATION
            $table->string('packaging_type')->default('YOUR_PACKAGING');
            // YOUR_PACKAGING, FEDEX_ENVELOPE, FEDEX_BOX, FEDEX_SMALL_BOX, FEDEX_MEDIUM_BOX, FEDEX_LARGE_BOX, FEDEX_EXTRA_LARGE_BOX, FEDEX_PAK, FEDEX_TUBE
            $table->string('packing_method')->default('ALL_IN_ONE');
            // ALL_IN_ONE, INDIVIDUAL
            $table->string('rate_type')->default('LIST');
            // LIST, ACCOUNT, PREFERRED
            $table->boolean('include_package_value')->default(true);
            $table->string('destination_type')->default('COMMERCIAL');
            // COMMERCIAL, RESIDENTIAL

            // -- Enabled delivery services (JSON array of service codes) --
            $table->json('enabled_services')->nullable();
            // e.g. ["FEDEX_GROUND","FEDEX_EXPRESS_SAVER","FEDEX_2_DAY","STANDARD_OVERNIGHT","PRIORITY_OVERNIGHT","FIRST_OVERNIGHT"]

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
