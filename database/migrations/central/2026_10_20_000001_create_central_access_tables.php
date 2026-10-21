<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        // Central platform/staff identities.
        if (!$schema->hasTable('users')) {
            $schema->create('users', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('phone_number')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->rememberToken();
                $table->timestamps();
            });
        }

        // Central staff permissions.
        if (!$schema->hasTable('permissions')) {
            $schema->create('permissions', function (Blueprint $table) {
                $table->id();
                $table->string('group');
                $table->string('name');
                $table->string('label');
                $table->string('slug')->unique();
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        // Central store membership.
        // Requires the existing central stores table.
        if (!$schema->hasTable('store_user')) {
            $schema->create('store_user', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')
                    ->constrained('users')
                    ->onDelete('cascade');
                $table->foreignId('store_id')
                    ->constrained('stores')
                    ->onDelete('cascade');
                $table->timestamps();
            });
        }

        // Per-store permissions for central staff.
        if (!$schema->hasTable('user_permissions')) {
            $schema->create('user_permissions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('store_id');
                $table->timestamps();

                $table->unique([
                    'user_id',
                    'permission_id',
                    'store_id',
                ]);

                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->onDelete('cascade');

                $table->foreign('permission_id')
                    ->references('id')
                    ->on('permissions')
                    ->onDelete('cascade');
            });
        }

        // Central staff authentication tokens.
        if (!$schema->hasTable('personal_access_tokens')) {
            $schema->create('personal_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->morphs('tokenable');
                $table->string('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }

        // Central staff password resets.
        if (!$schema->hasTable('password_reset_tokens')) {
            $schema->create('password_reset_tokens', function (Blueprint $table) {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }

        // Redis remains the queue driver.
        // Failed-job storage is separate from queue execution.
        if (!$schema->hasTable('failed_jobs')) {
            $schema->create('failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive.
        // Do not drop central tables or existing data on rollback.
    }
};
