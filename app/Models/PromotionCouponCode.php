<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromotionCouponCode extends Model
{
    protected $table = 'promotion_coupon_codes';

    protected $fillable = [
        'store_id',
        'promotion_id',
        'code',
        'status',
        'valid_from',
        'valid_until',
        'used_at',
    ];

    protected $casts = [
        'valid_from'  => 'datetime',
        'valid_until' => 'datetime',
        'used_at'     => 'datetime',
    ];

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }
}
