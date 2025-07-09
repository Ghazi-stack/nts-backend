<?php

namespace App\Models;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cart extends Model
{
    use SoftDeletes; // ✅ use trait here
    use HasFactory;
    protected $fillable = [ 
        'user_id', 
        'session_id', 
        'product_id', 
        'quantity'
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
