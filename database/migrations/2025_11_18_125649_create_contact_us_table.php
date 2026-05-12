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
        Schema::create('contact_requests', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('store_id');

            // Form Fields
            $table->string('full_name');
            $table->string('phone_number');
            $table->string('email');
            $table->string('order_number')->nullable();
            $table->string('company_name')->nullable();
            $table->string('rma_number')->nullable();
            $table->text('comments')->nullable();

            $table->timestamps();

            // Foreign Key
            $table->foreign('store_id')
                ->references('id')
                ->on('stores')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_requests');
    }
};
