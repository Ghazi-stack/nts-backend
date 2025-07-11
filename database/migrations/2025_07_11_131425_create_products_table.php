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
        if (!Schema::hasTable('products')) {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

             // Basic
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('sku')->nullable();
            $table->decimal('price', 10, 2)->default(0.00);
                
            $table->foreignId('brand_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('category_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('condition_id')->nullable()->constrained()->onDelete('set null');
                
            $table->string('weight')->nullable();
            $table->string('width')->nullable();
            $table->string('height')->nullable();
            $table->string('depth')->nullable();
                
            $table->boolean('track_inventory')->default(false);
            $table->integer('current_stock')->default(0);
            $table->integer('low_stock')->default(0);
                
            $table->boolean('is_featured')->default(false);
            $table->boolean('show_condition')->default(true);
            $table->integer('sort_order')->default(0);
            $table->text('search_keywords')->nullable();

            $table->timestamps();
        });
    }
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('products')) {
        Schema::dropIfExists('products');
    }
}
};
