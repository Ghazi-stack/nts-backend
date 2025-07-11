<?php

namespace App\Models;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;

class ProductAttribute extends Model
{
    use HasFactory;
    protected $fillable = ['product_id', 'key', 'value'];

    public function product() {
        return $this->belongsTo(Product::class);
    }
}
