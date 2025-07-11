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
        if (!Schema::hasTable('product_identifiers')) {
        Schema::create('product_identifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->string('mpn')->nullable();
            $table->string('upc')->nullable();
            $table->string('gtin')->nullable();
            $table->string('bpn')->nullable();
            $table->timestamps();
        });
    }
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('product_identifiers')) {
        Schema::dropIfExists('product_identifiers');
    } 
    }
};
