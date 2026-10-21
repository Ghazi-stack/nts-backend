<?php

namespace App\Models;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Payment extends Model
{
    use HasFactory;
    protected $connection = 'tenant';
    protected $fillable = [
        'order_id',
        'stripe_session_id',
        'payment_status',
        'amount',
        'discountAmount',
        'risk_score',
        'risk_level',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
