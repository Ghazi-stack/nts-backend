<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'userRole' => fn ($table) => $table->unsignedTinyInteger('userRole')->default(1),
            'two_factor_enabled' => fn ($table) => $table->boolean('two_factor_enabled')->default(false),
            'two_factor_type' => fn ($table) => $table->string('two_factor_type')->nullable(),
            'two_factor_secret' => fn ($table) => $table->text('two_factor_secret')->nullable(),
            'two_factor_confirmed' => fn ($table) => $table->boolean('two_factor_confirmed')->default(false),
            'two_factor_otp' => fn ($table) => $table->string('two_factor_otp')->nullable(),
            'two_factor_otp_expires_at' => fn ($table) => $table->timestamp('two_factor_otp_expires_at')->nullable(),
        ];

        foreach ($columns as $name => $addColumn) {
            if (!\Illuminate\Support\Facades\Schema::connection('central')
                ->hasColumn('users', $name)) {
                \Illuminate\Support\Facades\Schema::connection('central')
                    ->table('users', function ($table) use ($addColumn) {
                        $addColumn($table);
                    });
            }
        }
    }

    public function down(): void
    {
        // Intentionally no-op to protect central authentication data.
    }
};