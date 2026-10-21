<?php

namespace App\Models;

use App\Models\User;
use App\Models\Store;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Review extends Model
{
    use HasFactory;
    protected $connection = 'tenant';
    protected $fillable = ['user_id', 'product_id', 'rating', 'name', 'email', 'subject', 'comment', 'approved', 'store_id'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
