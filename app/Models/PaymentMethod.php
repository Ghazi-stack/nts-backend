<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'shipping_destination_id',
        'method',
        'card_type',
        'card_holder_name',
        'ccv2_value',
        'email',
        'invoice',
        'expiration_month',
        'expiration_year',
    ];

    public function shippingDestination()
    {
        return $this->belongsTo(ShippingDestination::class);
    }
}
