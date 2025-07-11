<?php

namespace App\Models;

use App\Models\User;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Condition;
use App\Models\ProductSeo;
use App\Models\ProductImage;
use App\Models\ProductContent;
use App\Models\ProductAttribute;
use App\Models\ProductIdentifier;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;
    protected $fillable = [
        'name', 'slug', 'sku', 'price', 'brand_id', 'category_id', 'condition_id',
        'weight', 'width', 'height', 'depth',
        'track_inventory', 'current_stock', 'low_stock',
        'is_featured', 'show_condition', 'sort_order',
        'search_keywords', 'created_by', 'updated_by'

    ];

      // Relationships
    public function brand() {
        return $this->belongsTo(Brand::class);
    }

    public function category() {
        return $this->belongsTo(Category::class);
    }

    public function condition() {
        return $this->belongsTo(Condition::class);
    }

    public function creator() {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater() {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function images() {
        return $this->hasMany(ProductImage::class);
    }

    public function attributes() {
        return $this->hasMany(ProductAttribute::class);
    }

    public function identifiers() {
        return $this->hasOne(ProductIdentifier::class);
    }

    public function seo() {
        return $this->hasOne(ProductSeo::class);
    }

    public function content() {
        return $this->hasOne(ProductContent::class);
    }
  
}
