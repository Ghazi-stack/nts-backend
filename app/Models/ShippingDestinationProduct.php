<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShippingDestinationProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'shipping_destination_id',
        'product_id',
        'quantity',
        'price',
        'total',
    ];

    public function shippingDestination()
    {
        return $this->belongsTo(ShippingDestination::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
