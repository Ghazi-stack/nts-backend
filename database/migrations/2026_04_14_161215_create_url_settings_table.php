<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Updated according to your requirements:
     * Product: title, sku, category, custom
     */
    public function up(): void
    {
        Schema::create('url_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            
            // product, category, webpage
            $table->enum('entity_type', ['product', 'category', 'webpage']);
            
            // BigCommerce ke exact names
            $table->enum('format_type', [
                'seo_optimized_short',      // Short
                'seo_optimized_long',       // Long
                'seo_optimized_category',   // Sirf Product ke liye
                'custom'
            ]);
            
            $table->text('custom_format')->nullable(); // jaise /%parent%/%categoryname%/
            
            $table->timestamps();

            // Har store ke liye ek hi entity_type ki sirf ek setting
            $table->unique(['store_id', 'entity_type']);
            
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('url_settings');
    }
};
