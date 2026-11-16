<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->table('stores', function (Blueprint $table) {
            $table->string('stripe_account_id', 255)
                ->nullable()
                ->unique();

            $table->boolean('stripe_livemode')
                ->nullable();

            $table->timestamp('stripe_connected_at')
                ->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('stores', function (Blueprint $table) {
            $table->dropUnique(['stripe_account_id']);

            $table->dropColumn([
                'stripe_account_id',
                'stripe_livemode',
                'stripe_connected_at',
            ]);
        });
    }
};