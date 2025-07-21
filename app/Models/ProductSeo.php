<?php

namespace App\Models;

use App\Models\Store;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ProductSeo extends Model
{
    use HasFactory;
    protected $table = 'product_seo';

   protected $fillable = [
        'product_id',
        'page_title',
        'product_url',
        'meta_description',
        'meta_keywords',       
        'redirect_old_url',    
    ];


    protected $casts = [
        'redirect_old_url' => 'boolean',
    ];


    public function product() {
        return $this->belongsTo(Product::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
