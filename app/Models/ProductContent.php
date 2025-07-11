<?php

namespace App\Models;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;

class ProductContent extends Model
{
    use HasFactory;
    protected $fillable = ['product_id', 'description', 'warranty', 'availability_text', 'template_layout'];

    public function product() {
        return $this->belongsTo(Product::class);
    }
}
