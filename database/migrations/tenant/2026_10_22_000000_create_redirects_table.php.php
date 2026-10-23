<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redirects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('entity_type')->default('product'); // product, category, webpage
            $table->string('old_url');          // product_seo.product_url se aayega (change nahi hoga)
            $table->string('new_url');          // current URL setting se generate hoga
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['store_id', 'old_url']);
            $table->unique(['store_id', 'old_url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redirects');
    }
};
