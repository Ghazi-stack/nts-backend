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
        Schema::create('product_extra', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('store_id')->constrained('stores')->onDelete('cascade');
            $table->decimal('retail_price', 10, 2)->nullable();
            $table->string('distributor_name')->nullable();
            $table->string('distributor_sku')->nullable();
            $table->tinyInteger('event_date_required')->default(0);
            $table->string('gps_gender')->nullable();
            $table->string('gps_age_group')->nullable();
            $table->tinyInteger('gps_enabled')->default(0);
            $table->string('myob_asset_acct')->nullable();
            $table->string('myob_income_acct')->nullable();
            $table->string('myob_expense_acct')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_extra');
    }
};
