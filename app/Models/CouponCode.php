<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CouponCode extends Model
{
    protected $table = 'coupon_code';

    protected $fillable = [
        'appliesToCategories',
        'appliesToProducts',
        'categoryIds',
        'couponCode',
        'couponName',
        'discountAmount',
        'discountType',
        'enabled',
        'excludeCartDiscounts',
        'expiration',
        'limitByLocation',
        'limitByShipping',
        'limitTotalUses',
        'limitUsesPerCustomer',
        'minimumPurchase',
    ];
}
