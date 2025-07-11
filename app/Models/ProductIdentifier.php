<?php

namespace App\Models;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;

class ProductIdentifier extends Model
{
    use HasFactory;
    protected $fillable = ['product_id', 'mpn', 'upc', 'gtin', 'bpn'];

    public function product() {
        return $this->belongsTo(Product::class);
    }
}
