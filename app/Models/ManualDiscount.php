<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManualDiscount extends Model
{
    protected $table = 'manual_discounts';
    protected $connection = 'tenant';

    protected $fillable = [
        'store_id',
        'user_id',
        'customer_id',
        'order_id',
        'product_id',
        'discount_amount',
        'total_amount',
    ];

    protected $casts = [
        'discount_amount' => 'decimal:2',
        'total_amount'    => 'decimal:2',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
