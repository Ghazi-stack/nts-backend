<?php

namespace App\Models;

use App\Models\Store;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ProductImage extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    
    protected $fillable = ['product_id', 'path', 'url', 'is_primary', 'alt_text', 'store_id'];


    public function product() {
        return $this->belongsTo(Product::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
