<?php

namespace App\Models;

use App\Models\Store;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Category extends Model
{
    use HasFactory;

    protected $fillable =
    [

        'store_id',
        'name',
        'slug',
        'description',
        'parent_id',
        'is_visible',
        'channel',

        // NEW
        'image',
        'seo_home_title',
        'seo_meta_keywords',
        'seo_meta_description',
        'seo_search_keywords',
        'template_layout',
        'sort_order',
        'default_product_sort',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
        'sort_order' => 'integer',
    ];

    // 🔁 Recursive relationship for nested categories
    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id')
            ->withCount('products');
    }

    public function childrenRecursive()
    {
        return $this->children()->with('childrenRecursive');
    }
    // ✅ Many-to-many: Categories ↔ Products
    public function products()
    {
        return $this->belongsToMany(Product::class, 'category_product');
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function seo()
    {
        return $this->hasOne(ProductSeo::class);
    }
}
