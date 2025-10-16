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
        Schema::create('web_pages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('page_name');
            $table->string('page_url')->nullable();
            $table->string('page_title')->nullable();
            $table->longText('page_content')->nullable();
            $table->string('page_type')->nullable();
            $table->string('template_layout_file')->nullable();
            $table->string('meta_keywords')->nullable();
            $table->string('meta_description', 1000)->nullable();
            $table->string('search_keywords')->nullable();
            $table->boolean('display_as_home_page')->default(false);
            $table->boolean('restrict_to_customers_only')->default(false);
            $table->boolean('show_in_navigation')->default(false);
            $table->string('sort_order')->nullable();
            $table->string('parent_page')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('web_pages');
    }
};
