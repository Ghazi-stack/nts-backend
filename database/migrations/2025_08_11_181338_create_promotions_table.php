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
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->index();
            $table->enum('type', ['automatic','coupon'])->index();
            $table->string('name');
            $table->enum('status', ['active','inactive'])->default('inactive')->index();
            $table->unsignedInteger('priority')->default(0);
            $table->string('currency', 10)->nullable();
            $table->string('channel')->nullable();

            // coupon-specific
            $table->string('code')->nullable()->index();
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('per_customer_limit')->nullable();
            $table->unsignedInteger('redemptions')->default(0);

            // common fields
            $table->json('conditions')->nullable();
            $table->json('actions')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('extra')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
