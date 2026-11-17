<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderCalculation extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $fillable = [
        'order_id',
        'store_id',
        'currency_id',
        'amount_decimal',
        'amount_cents',
        'shipping_cost',
        'manual_discount',
        'converted_coupon_discount',
        'currency',
        'coupon',
        'matched_rate',
        'items',
        'payload',
    ];

    protected $casts = [
        'amount_decimal'              => 'decimal:6',
        'shipping_cost'               => 'decimal:6',
        'manual_discount'             => 'decimal:6',
        'converted_coupon_discount'   => 'decimal:6',
        'currency'                    => 'array',
        'coupon'                      => 'array',
        'matched_rate'                => 'array',
        'items'                       => 'array',
        'payload'                     => 'array',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
