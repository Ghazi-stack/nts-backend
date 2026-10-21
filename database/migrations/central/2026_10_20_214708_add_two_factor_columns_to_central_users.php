<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->table('users', function (Blueprint $table) {
            $table->boolean('two_factor_enabled')->default(false);
            $table->string('two_factor_type')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->string('two_factor_otp')->nullable();
            $table->timestamp('two_factor_otp_expires_at')->nullable();
            $table->boolean('two_factor_confirmed')->default(false);
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_enabled',
                'two_factor_type',
                'two_factor_secret',
                'two_factor_otp',
                'two_factor_otp_expires_at',
                'two_factor_confirmed',
            ]);
        });
    }
};
