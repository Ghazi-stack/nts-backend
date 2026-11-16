<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('redirects', function (Blueprint $table) {
            if (!Schema::hasColumn('redirects', 'entity_id')) {
                $table->unsignedBigInteger('entity_id')->nullable()->after('product_id');
            }
            if (!Schema::hasColumn('redirects', 'redirect_type')) {
                $table->string('redirect_type')->default('dynamic')->after('entity_type');
            }
            if (!Schema::hasColumn('redirects', 'domain')) {
                $table->string('domain')->nullable()->after('redirect_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('redirects', function (Blueprint $table) {
            $table->dropColumn(['entity_id', 'redirect_type', 'domain']);
        });
    }
};
