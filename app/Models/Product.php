<?php

namespace App\Models;

use App\Models\User;
use App\Models\Brand;
use App\Models\Store;
use App\Models\Category;
use App\Models\Condition;
use App\Models\ProductSeo;
use App\Models\ProductImage;
use App\Models\ProductContent;
use App\Models\ProductAttribute;
use App\Models\ProductIdentifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model
{
    use HasFactory;
    protected $fillable = [
        'name', 'slug', 'sku', 'price', 'brand_id', 'category_id',
        'dimensions', 'track_inventory', 'current_stock', 'low_stock',
        'is_featured', 'show_condition', 'sort_order', 'search_keywords',
        'visible_on_storefront', 'custom_fields', 'free_shipping',
        'product_type', 'option_set', 'option_set_align',
        'cost_price', 'sale_price', 'fixed_shipping_cost',
        'allow_purchase', 'stop_processing_rules', 'tax_class', 'tax_provider_code','item_type'
    ];

    protected $casts = [
        'custom_fields' => 'array',
        'visible_on_storefront' => 'boolean',
        'is_featured' => 'boolean',
        'track_inventory' => 'boolean',
        'show_condition' => 'boolean',
        'allow_purchase' => 'boolean',
        'stop_processing_rules' => 'boolean',
        'free_shipping' => 'boolean',
        'dimensions' => 'array'
    ];

      // Relationships
       public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
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
}
