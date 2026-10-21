<?php

namespace App\Models;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ProductSearch extends Model
{
    use HasFactory;
    protected $connection = 'tenant';
    protected $fillable = ['store_id', 'product_id', 'search_count'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
    
}
