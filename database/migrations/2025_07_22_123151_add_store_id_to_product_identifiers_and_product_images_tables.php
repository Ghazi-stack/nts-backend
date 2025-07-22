<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_identifiers', function (Blueprint $table) {
            $table->unsignedBigInteger('store_id')->nullable()->after('id');
        });

        Schema::table('product_images', function (Blueprint $table) {
            $table->unsignedBigInteger('store_id')->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('product_identifiers', function (Blueprint $table) {
            $table->dropColumn('store_id');
        });

        Schema::table('product_images', function (Blueprint $table) {
            $table->dropColumn('store_id');
        });
    }
};
