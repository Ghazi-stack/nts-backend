<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create comment settings table
     */
    public function up(): void
    {
        Schema::create('comment_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->unique();

            $table->string('comment_system')->default('Built-in');
            $table->boolean('enable_product_reviews')->default(true);
            $table->boolean('only_accept_from_past_customers')->default(true);

            $table->timestamps();
            $table->index('store_id');
        });
    }

    /**
     * Rollback
     */
    public function down(): void
    {
        Schema::dropIfExists('comment_settings');
    }
};
