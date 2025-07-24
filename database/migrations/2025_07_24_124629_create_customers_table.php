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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            // Foreign key to 'stores' table
            $table->unsignedBigInteger('store_id');
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
            
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('name')->nullable(); // Full name if needed
            $table->string('company_name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('customer_group')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->decimal('store_credit', 10, 2)->default(0);
            $table->boolean('receive_review_emails')->default(true);
            $table->boolean('force_password_reset')->default(false);
            $table->string('tax_exempt_code')->nullable();
            $table->string('password')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
