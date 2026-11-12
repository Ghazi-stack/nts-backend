<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->index();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();

            $table->string('severity')->default('success');   // success / notice / error
            $table->string('type');                           // Shipping quote / Payment gateway
            $table->string('module')->nullable();
            $table->text('summary')->nullable();

            $table->json('log_data')->nullable();             // Saara detail data yahan

            $table->string('customer_type')->nullable();      // guest / registered
            $table->string('email')->nullable();
            $table->timestamp('logged_at')->useCurrent();
            $table->timestamps();

            $table->index(['store_id', 'type', 'logged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_logs');
    }
};
