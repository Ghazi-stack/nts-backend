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
        Schema::table('carts', function (Blueprint $table) {
            // Track karo kaunsi email send ho chuki hai
            $table->timestamp('email_1hr_sent_at')->nullable()->after('quantity');   // 1 hour wali
            $table->timestamp('email_24hr_sent_at')->nullable()->after('email_1hr_sent_at'); // 24 hour wali
            $table->timestamp('email_48hr_sent_at')->nullable()->after('email_24hr_sent_at'); // 48 hour wali
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
              $table->dropColumn(['email_1hr_sent_at', 'email_24hr_sent_at', 'email_48hr_sent_at']);
        });
    }
};
