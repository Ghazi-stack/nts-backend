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
            $table->unsignedBigInteger('store_id'); // store id
            $table->string('company');
            $table->string('email');
            $table->string('full_name');
            $table->string('part_number');
            $table->string('phone_number');
            $table->integer('required_qty'); 
            $table->timestamps();
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade'); // optional if you have stores table
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
