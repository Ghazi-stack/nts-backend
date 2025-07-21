<?php

namespace App\Models;

use App\Models\User;
use App\Models\Store;
use App\Models\Payment;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Order extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id', 
        'session_id',
        'name',
        'email',
        'phone',
        'address',
        'payment_method',
        'shipping_method',
        'shipping_cost',
        'comments',
        'total_amount',
        'status'
    ];

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function payment()
    {
        return $this->hasOne(Payment::class);
    }
    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
