<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create(
            'store_payment_method_providers',
            function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger('store_id');

                $table->string('method_code', 100);
                $table->string('provider_code', 50);

                $table->boolean('is_enabled')->default(false);
                $table->boolean('is_available')->default(false);

                $table->json('configuration')->nullable();

                $table->timestamps();

                $table->unique(
                    ['store_id', 'method_code', 'provider_code'],
                    'store_payment_method_provider_unique'
                );

                $table->index(
                    ['store_id', 'method_code'],
                    'store_payment_method_provider_method_index'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists(
            'store_payment_method_providers'
        );
    }
};
