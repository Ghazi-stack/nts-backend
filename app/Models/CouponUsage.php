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
    ];
}
