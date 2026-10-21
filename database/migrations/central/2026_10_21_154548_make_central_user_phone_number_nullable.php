<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->table('users', function (Blueprint $table) {
            $table->string('phone_number')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Keep phone_number nullable to avoid rollback failures
        // when multiple central users have a NULL phone number.
    }
};