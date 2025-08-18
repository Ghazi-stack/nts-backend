<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class UpdateOrdersUserToCustomer extends Migration
{
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            // Step 1: Drop foreign key if it exists (safe check using raw SQL)
            DB::statement('ALTER TABLE orders DROP FOREIGN KEY IF EXISTS orders_ibfk_1');

            // Step 2: Rename `user_id` → `customer_id` only if `user_id` exists
            if (Schema::hasColumn('orders', 'user_id')) {
                $table->renameColumn('user_id', 'customer_id');
            }

            // Step 3: Add foreign key to customers table (only if column exists)
            if (Schema::hasColumn('orders', 'customer_id')) {
                $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
            }
        });
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            // Rollback: drop new FK and rename back to user_id
            $table->dropForeign(['customer_id']);
            $table->renameColumn('customer_id', 'user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }
}
