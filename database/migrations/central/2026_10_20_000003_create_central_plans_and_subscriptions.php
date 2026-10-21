<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Run this migration on the CENTRAL connection only. */
    protected $connection = 'central';

    public function up(): void
    {
        if (!Schema::connection('central')->hasTable('plans')) {
            Schema::connection('central')->create('plans', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('slug')->unique();
                $table->decimal('price', 10, 2)->default(0);
                $table->string('billing_interval', 20)->default('monthly');
                $table->json('features')->nullable();
                $table->boolean('is_active')->default(true);
                $table->boolean('is_default')->default(false);
                $table->timestamps();
            });
        }

        if (!Schema::connection('central')->hasTable('store_subscriptions')) {
            Schema::connection('central')->create('store_subscriptions', function (Blueprint $table) {
                $table->bigIncrements('id');
                // Keep store_id as a value referencing central stores; no FK is added here.
                $table->unsignedBigInteger('store_id')->index();
                $table->unsignedBigInteger('plan_id')->index();
                $table->string('status', 30)->default('active');
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->timestamp('trial_ends_at')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::connection('central')->hasTable('plans')
            && !DB::connection('central')->table('plans')->where('slug', 'free')->exists()) {
            DB::connection('central')->table('plans')->insert([
                'name' => 'Free',
                'slug' => 'free',
                'price' => 0,
                'billing_interval' => 'monthly',
                'features' => json_encode([]),
                'is_active' => true,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('store_subscriptions');
        Schema::connection('central')->dropIfExists('plans');
    }
};
