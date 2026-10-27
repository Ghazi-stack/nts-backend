<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Orders table mein currency fields add karo
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('currency_code', 3)->default('USD')->after('total_amount');
            $table->string('currency_symbol', 10)->nullable()->after('currency_code');
            $table->decimal('exchange_rate', 15, 6)->default(1)->after('currency_symbol');
        });
    }

    /**
     * Rollback
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['currency_code', 'currency_symbol', 'exchange_rate']);
        });
    }
};
