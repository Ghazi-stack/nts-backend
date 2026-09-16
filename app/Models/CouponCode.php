<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CouponCode extends Model
{
    protected $table = 'coupon_code';

    protected $fillable = [
        'store_id',
        'appliesToCategories',
        'appliesToProducts',
        'categoryIds',
        'productIds',
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
