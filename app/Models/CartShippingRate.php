<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CartShippingRate extends Model
{
    protected $fillable = [
        'store_id',
        'user_id',
        'session_id',
        'country',
        'state',
        'city',
        'zip',
        'method_type',
        'total_charge',
    ];
}
