<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create(
            'store_payment_methods',
            function (Blueprint $table) {

                $table->id();

                $table->unsignedBigInteger('store_id');

                $table->string('method_code', 100);

                $table->string('category', 50);

                $table->string('display_name', 150);

                $table->boolean('is_enabled')->default(false);

                $table->boolean('is_configured')->default(false);

                $table->json('available_countries')->nullable();

                $table->json('configuration')->nullable();

                // Encrypted gateway credentials can be larger
                // than a standard VARCHAR column.
                $table->longText('credentials')->nullable();

                $table->timestamps();

                // A payment method can only exist once per store.
                $table->unique(
                    ['store_id', 'method_code'],
                    'store_payment_methods_store_method_unique'
                );

                $table->index(
                    ['store_id', 'category'],
                    'store_payment_methods_store_category_index'
                );

                $table->index(
                    ['store_id', 'is_enabled'],
                    'store_payment_methods_store_enabled_index'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists(
            'store_payment_methods'
        );
    }
};
