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
        Schema::create('blog_posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->index();
            $table->string('title')->nullable();
            $table->string('slug')->nullable();
            $table->longText('body')->nullable();
            $table->string('author')->nullable();
            $table->string('tags')->nullable(); // comma-separated or JSON
            $table->string('thumbnail_image')->nullable();
            $table->string('post_url')->nullable()->unique();
            $table->text('meta_description')->nullable();
            $table->timestamps();
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_posts');
    }
};
