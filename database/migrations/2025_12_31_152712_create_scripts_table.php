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
        Schema::create('scripts', function (Blueprint $table) {
             $table->id();
            $table->string('script_name')->nullable();
            $table->text('description')->nullable();
            $table->string('placement')->nullable();
            $table->string('location')->nullable();
            $table->string('script_category')->nullable();
            $table->string('script_type')->nullable();
            $table->longText('script_content')->nullable();
            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scripts');
    }
};
