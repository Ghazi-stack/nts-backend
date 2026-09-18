<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CouponUsage extends Model
{
    protected $table = 'coupon_usages';

    protected $fillable = [
        'store_id',
        'user_id',
        'session_id',
        'coupon_id',
        'coupon_code',
        'discount_amount',
        'email',
        'user_used',
        'is_delete',
    ];

    protected $casts = [
        'user_used' => 'boolean',
        'is_delete' => 'boolean',
    ];
}
