<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CartShippingRate extends Model
{
    protected $fillable = [
        'store_id',
        'user_id',
        'session_id',
        'quote_token',
        'cart_ids',
        'country',
        'state',
        'city',
        'zip',
        'service_type', // ← new
        'method_type',
        'total_charge',
    ];

    protected $casts = [
        'cart_ids'     => 'array',
        'total_charge' => 'float',
    ];
}
