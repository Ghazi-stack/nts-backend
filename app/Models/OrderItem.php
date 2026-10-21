<?php

namespace App\Models;

use App\Models\Order;
use App\Models\Store;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class OrderItem extends Model
{
    use HasFactory;
    protected $connection = 'tenant';
    protected $fillable = [
        'order_id', 
        'product_id', 
        'quantity', 
        'price',
        'store_id' // ✅ Added 'store_id' to fillable attributes
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
