<?php

namespace App\Models;

use App\Models\Store;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ProductIdentifier extends Model
{
    use HasFactory;
    protected $fillable = ['product_id', 'mpn', 'upc', 'gtin', 'bpn'];

    public function product() {
        return $this->belongsTo(Product::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
