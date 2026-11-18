<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create(
            'store_stripe_webhook_endpoints',
            function (Blueprint $table) {
                $table->id();

                // Tenant-local store identifier.
                $table->unsignedBigInteger('store_id');

                // test or live
                $table->string('environment', 10);

                // Stripe webhook endpoint ID, if registered.
                $table->string('stripe_endpoint_id')->nullable();

                // Our public Laravel webhook URL.
                $table->text('endpoint_url');

                // Encrypted using the model cast.
                $table->text('signing_secret')->nullable();

                $table->json('enabled_events')->nullable();

                $table->boolean('is_enabled')->default(false);
                $table->boolean('is_verified')->default(false);

                $table->timestamp('last_event_at')->nullable();
                $table->string('last_event_type')->nullable();
                $table->string('last_event_status')->nullable();
                $table->text('last_error')->nullable();

                $table->timestamps();

                $table->unique(
                    ['store_id', 'environment'],
                    'store_stripe_webhook_store_environment_unique'
                );

                $table->index(
                    ['store_id', 'is_enabled'],
                    'store_stripe_webhook_store_enabled_index'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::connection('tenant')
            ->dropIfExists('store_stripe_webhook_endpoints');
    }
};
