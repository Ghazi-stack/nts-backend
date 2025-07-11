<?php

namespace App\Models;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;

class ProductSeo extends Model
{
    use HasFactory;
    protected $fillable = ['product_id', 'page_title', 'product_url', 'meta_description'];

    public function product() {
        return $this->belongsTo(Product::class);
    }
}
