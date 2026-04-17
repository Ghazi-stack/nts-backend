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
        Schema::create('shipping_zones', function (Blueprint $table) {
            $table->id();
            $table->string('name');                          // e.g. "United States", "Rest of the World"
            $table->string('zone_type')->default('country'); // country, state, zip_range
            $table->json('countries')->nullable();            // ["US"], ["CA","MX"], ["*"] for rest of world
            $table->json('states')->nullable();               // ["CA","NY","TX"] - optional state-level filtering
            $table->json('zip_ranges')->nullable();           // [{"from":"90001","to":"90099"}] - optional
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipping_zones');
    }
};
