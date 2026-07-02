<?php

namespace App\Models;

use App\Models\User;
use App\Models\Brand;
use App\Models\Store;
use App\Models\Review;
use App\Models\Category;
use App\Models\Condition;
use App\Models\ProductSeo;
use App\Models\ProductExtra;
use App\Models\ProductImage;
use App\Models\ProductSearch;
use App\Models\ProductContent;
use App\Models\ProductAttribute;
use App\Models\ProductIdentifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'slug',
        'sku',
        'price',
        'brand_id',
        'category_id',
        'dimensions',
        'track_inventory',
        'current_stock',
        'low_stock',
        'is_featured',
        'show_condition',
        'show_shipping',
        'sort_order',
        'search_keywords',
        'visible_on_storefront',
        'custom_fields',
        'free_shipping',
        'price',
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
        'tax_code',
        'discount_type',
        'bulk_pricing_tiers',
        'item_type',
        'store_id',
        'msrp',
        'call_pricing',
        'purchasability_status',
        'min_purchase_quantity',
        'max_purchase_quantity',
        'related_products_enabled',
        'related_products',
        'condition_id',
        'quantity',
        'sold_count',
        'object_type',
        'use_product_name',
        'graph_description',
        'use_thumbnail',
        'dont_use',
        'manage_customs',
        'hs_codes',
        'commodity_description',
        'country_of_origin',
        'condition',
        'faq'
    ];

    protected $casts = [
        'custom_fields' => 'array',
        'bulk_pricing_tiers' => 'array',
        'visible_on_storefront' => 'boolean',
        'call_pricing' => 'boolean',
        'is_featured' => 'boolean',
        'track_inventory' => 'boolean',
        'show_condition' => 'boolean',
        'show_shipping' => 'boolean',
        'allow_purchase' => 'boolean',
        'stop_processing_rules' => 'boolean',
        'free_shipping' => 'boolean',
        'dimensions' => 'array',
        // 'msrp' => 'decimal:2',
        'related_products_enabled' => 'boolean',
        'min_purchase_quantity' => 'integer',
        'max_purchase_quantity' => 'integer',
        'hs_codes' => 'array',
        'use_product_name' => 'boolean',
        'graph_description' => 'boolean',
        'use_thumbnail' => 'boolean',
        'dont_use' => 'boolean',
        'manage_customs' => 'boolean',
    ];

    // Relationships
    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class, 'category_product');
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class);
    }

    public function content()
    {
        return $this->hasOne(ProductContent::class);
    }

    public function identifiers()
    {
        return $this->hasOne(ProductIdentifier::class);
    }

    public function seo()
    {
        return $this->hasOne(ProductSeo::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
    public function categoriesForCurrentStore()
    {
        return $this->belongsToMany(Category::class, 'category_product', 'product_id', 'category_id')
            ->where('categories.store_id', current_store_id());
    }

    public function searchStats()
    {
        return $this->hasOne(ProductSearch::class, 'product_id')->where('store_id', current_store_id());
    }
    public function reviews()
    {
        return $this->hasMany(Review::class)
            ->where('approved', 1); // only approved reviews
    }
    public function extra()
    {
        return $this->hasOne(ProductExtra::class, 'product_id');
    }
}
