<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBigcommerceColumnsToProductsAndProductSeo extends Migration
{
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('product_type')->nullable();
            $table->string('option_set')->nullable();
            $table->string('option_set_align')->nullable();
            $table->decimal('cost_price', 10, 2)->nullable();
            $table->decimal('sale_price', 10, 2)->nullable();
            $table->decimal('fixed_shipping_cost', 10, 2)->nullable();
            $table->boolean('allow_purchase')->default(true);
            $table->boolean('stop_processing_rules')->default(false);
            $table->string('tax_class')->nullable();
            $table->string('tax_provider_code')->nullable();
        });

        Schema::table('product_seo', function (Blueprint $table) {
            $table->text('meta_keywords')->nullable();
            $table->boolean('redirect_old_url')->default(false);
        });

        Schema::table('product_images', function (Blueprint $table) {
            $table->text('alt_text')->nullable(); // For image descriptions
        });
    }

    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'product_type',
                'option_set',
                'option_set_align',
                'cost_price',
                'sale_price',
                'fixed_shipping_cost',
                'allow_purchase',
                'stop_processing_rules',
                'tax_class',
                'tax_provider_code',
            ]);
        });

        Schema::table('product_seo', function (Blueprint $table) {
            $table->dropColumn(['meta_keywords', 'redirect_old_url']);
        });

        Schema::table('product_images', function (Blueprint $table) {
            $table->dropColumn(['alt_text']);
        });
    }
}
