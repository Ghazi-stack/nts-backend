<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CouponCode extends Model
{
    protected $table = 'coupon_code';
    protected $connection = 'tenant';

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

    protected $casts = [
        'appliesToCategories'  => 'boolean',
        'appliesToProducts'    => 'boolean',
        'enabled'              => 'boolean',
        'excludeCartDiscounts' => 'boolean',
        'limitByLocation'      => 'boolean',
        'limitByShipping'      => 'boolean',
        'limitTotalUses'       => 'boolean',
        'limitUsesPerCustomer' => 'boolean',
    ];
}
