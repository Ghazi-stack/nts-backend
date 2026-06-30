<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SavedAddress extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'user_id',
        'order_id',
        'shipping_address',
        'billing_address',
    ];

    protected $casts = [
        'shipping_address' => 'array',
        'billing_address'  => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
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
