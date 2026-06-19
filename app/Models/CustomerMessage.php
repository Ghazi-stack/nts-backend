<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CustomerMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'customer_id',
        'order_id',
        'order_number',
        'subject',
        'message',
        'is_read',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
