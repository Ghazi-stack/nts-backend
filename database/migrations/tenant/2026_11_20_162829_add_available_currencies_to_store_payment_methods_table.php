<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table(
            'store_payment_methods',
            function (Blueprint $table) {
                $table->json('available_currencies')->nullable();
            }
        );
    }

    public function down(): void
    {
        Schema::connection('tenant')->table(
            'store_payment_methods',
            function (Blueprint $table) {
                $table->dropColumn('available_currencies');
            }
        );
    }
};
