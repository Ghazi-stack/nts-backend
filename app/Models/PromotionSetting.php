<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PromotionSetting extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $fillable = [
        'store_id',
        'calculate_independently',
        'calculate_cumulatively',
        'number_of_coupons_allowed',
        'allow_zero_price_products',
        'allow_custom_price_overrides',
    ];

    protected $casts = [
        'calculate_independently'       => 'boolean',
        'calculate_cumulatively'        => 'boolean',
        'number_of_coupons_allowed'     => 'integer',
        'allow_zero_price_products'     => 'boolean',
        'allow_custom_price_overrides'  => 'boolean',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
