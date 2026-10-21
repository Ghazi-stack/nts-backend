
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('stores', 'store_hash')) {
            Schema::table('stores', function (Blueprint $table) {
                $table->string('store_hash', 64)->nullable();
            });
        }

        if (!Schema::hasColumn('stores', 'tenant_database')) {
            Schema::table('stores', function (Blueprint $table) {
                $table->string('tenant_database', 64)->nullable();
            });
        }

        if (!Schema::hasColumn('stores', 'provisioning_status')) {
            Schema::table('stores', function (Blueprint $table) {
                $table->string('provisioning_status', 30)
                    ->default('pending');
            });
        }
    }

    public function down(): void
    {
        // Intentionally retain these columns during rollback.
        // Existing data and manually managed schema must remain safe.
    }
};
