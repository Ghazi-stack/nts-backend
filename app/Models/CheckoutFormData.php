<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CheckoutFormData extends Model
{
    protected $fillable = [
        'store_id',
        'user_id',
        'session_id',
        'billing_form_data',
        'shipping_form_data',
    ];

    protected $casts = [
        'billing_form_data' => 'array',
        'shipping_form_data' => 'array',
    ];
}
