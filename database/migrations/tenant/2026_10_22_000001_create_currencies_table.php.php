<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();

            // Multi-tenant (aapke system jaisa)
            $table->unsignedBigInteger('store_id')->index();

            // ISO 4217 code
            $table->string('code', 3);

            $table->string('name');
            $table->string('symbol', 10)->nullable();
            $table->enum('symbol_location', ['left', 'right'])->default('left');
            $table->string('decimal_token', 1)->default('.');
            $table->string('thousands_token', 1)->default(',');
            $table->unsignedTinyInteger('decimal_places')->default(2);

            // Exchange rate (1 default = X this currency)
            $table->decimal('exchange_rate', 15, 6)->default(1.000000);

            $table->boolean('is_default')->default(false);
            $table->boolean('is_transactional')->default(false);
            $table->boolean('auto_update')->default(false);
            $table->json('default_for_countries')->nullable();
            $table->boolean('enabled')->default(true);

            $table->timestamps();

            // Same store mein same currency code duplicate nahi
            $table->unique(['store_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
