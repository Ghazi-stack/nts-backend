<?php

namespace App\Models;

use App\Models\Store;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Category extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'description', 'parent_id','is_visible','store_id'];

     // 🔁 Recursive relationship for nested categories
    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function childrenRecursive()
    {
        return $this->hasMany(Category::class, 'parent_id')
            ->with(['childrenRecursive', 'products']);
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
}
