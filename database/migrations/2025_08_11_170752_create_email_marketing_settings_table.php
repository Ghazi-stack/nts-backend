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
        Schema::create('email_marketing_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->unique()->index();
            $table->boolean('allow_newsletter_subscriptions')->default(true);
            $table->boolean('check_newsletter_box_by_default')->default(false);
            $table->boolean('show_newsletter_summary')->default(false);

            $table->json('extra')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_marketing_settings');
    }
};
