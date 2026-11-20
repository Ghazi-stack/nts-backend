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
        Schema::create('transactional_email_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->index();
            $table->string('key')->index();           // e.g. order_email
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(true);

            // optional headers
            $table->string('from_name')->nullable();
            $table->string('from_email')->nullable();
            $table->string('reply_to')->nullable();
            $table->string('cc')->nullable();
            $table->string('bcc')->nullable();

            $table->string('subject')->nullable();
            $table->longText('body_html')->nullable();
            $table->longText('body_text')->nullable();

            $table->json('variables')->nullable();
            $table->string('locale', 12)->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();
            $table->unique(['store_id', 'key']);
        });

        Schema::create('transactional_email_phrases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('template_id');
            $table->string('phrase_name');
            $table->text('phrase_value')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->foreign('template_id')
                ->references('id')
                ->on('transactional_email_templates')
                ->cascadeOnDelete();

            $table->unique(['template_id', 'phrase_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactional_email_phrases');
        Schema::dropIfExists('transactional_email_templates');
    }
};
