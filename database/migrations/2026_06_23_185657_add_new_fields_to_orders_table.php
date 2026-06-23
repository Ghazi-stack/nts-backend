<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('ip_address')->nullable()->after('newsletter');
            $table->boolean('is_save_address_for_shipping')->default(false)->after('ip_address');
            $table->boolean('billing_same')->default(true)->after('is_save_address_for_shipping');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['ip_address', 'is_save_address_for_shipping', 'billing_same']);
        });
    }
};
