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
        Schema::table('categories', function (Blueprint $table) {
            $table->string('image')->nullable()->after('description');
            $table->string('seo_home_title', 70)->nullable()->after('image');
            $table->string('seo_meta_keywords', 255)->nullable()->after('seo_home_title');
            $table->string('seo_meta_description', 160)->nullable()->after('seo_meta_keywords');
            $table->string('seo_search_keywords', 255)->nullable()->after('seo_meta_description');
            $table->string('template_layout', 100)->nullable()->after('seo_search_keywords');
            $table->integer('sort_order')->nullable()->after('template_layout');
            $table->string('default_product_sort', 100)->nullable()->after('sort_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
             $table->dropColumn([
                'image',
                'seo_home_title',
                'seo_meta_keywords',
                'seo_meta_description',
                'seo_search_keywords',
                'template_layout',
                'sort_order',
                'default_product_sort',
            ]);
        });
    }
};
