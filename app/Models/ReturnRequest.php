<?php

namespace App\Models;

use App\Models\User;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Model;

class ReturnRequest extends Model
{
     protected $fillable = [
        'store_id',
        'order_id',
        'user_id',
        'reason',
        'returnAction',
        'comments',
        'status',
        'is_submit',
    ];

    public function order() {
        return $this->belongsTo(Order::class);
    }

    public function orderItem() {
        return $this->belongsTo(OrderItem::class);
    }

    public function user() {
        return $this->belongsTo(User::class);
    }
}
