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
        Schema::table('users', function (Blueprint $table) {
            $table->string('store_name')->after('phone_number');
            $table->string('business_size')->after('store_name');
            $table->string('region')->after('business_size');
            $table->tinyInteger('userRole')->nullable()->default(2)->after('phone_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
            'store_name',
            'business_size',
            'region',
            'userRole'
        ]);
        });
    }
};
