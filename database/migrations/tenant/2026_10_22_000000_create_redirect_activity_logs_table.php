<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redirect_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('type')->default('export');           // export, import
            $table->integer('total_items')->default(0);
            $table->integer('successful')->default(0);
            $table->integer('errors')->default(0);
            $table->string('status')->default('complete');       // complete, failed, processing
            $table->string('file_name')->nullable();
            $table->text('download_url')->nullable();
            $table->string('s3_path')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'type']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redirect_activity_logs');
    }
};
