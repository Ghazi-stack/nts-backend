<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderCartItemProduct extends Model
{
    protected $table = 'order_cart_item_products';

    protected $fillable = [
        'order_id',
        'user_id',
        'product_id',
        'cart_id',
        'price',
        'qty',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'qty'   => 'integer',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function cart()
    {
        return $this->belongsTo(Cart::class);
    }
}
