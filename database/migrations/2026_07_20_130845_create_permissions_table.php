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
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('group');         // orders, products, customers, storefront, marketing, settings
            $table->string('name');          // all_orders, add_order, search_orders, etc.
            $table->string('label');         // All Orders, Add, Search, etc.
            $table->string('slug')->unique(); // orders.all, orders.add, etc.
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
