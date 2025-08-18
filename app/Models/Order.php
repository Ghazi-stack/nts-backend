<?php

namespace App\Models;

use App\Models\User;
use App\Models\Store;
use App\Models\Payment;
use App\Models\Customer;
use App\Models\OrderItem;
use App\Models\OrderNote;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Order extends Model
{
    use HasFactory;
    protected $fillable = [
        'store_id', 'user_id', 'session_id', 'order_number',
        'name', 'email', 'phone', 'address', 'status',
        'is_draft', 'channel', 'payment_method', 'shipping_method',
        'shipping_cost', 'comments', 'total_amount',
          'tracking_number','tracking_carrier_code','tracking_carrier_name','shipped_at',
    ];
    protected $casts = [
        'is_draft'   => 'boolean',
        'shipped_at' => 'datetime',
    ];
    public function items()
    {
        return $this->hasMany(OrderItem::class)->with('product');
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
    public function notes()
    {
        return $this->hasMany(OrderNote::class);
    }
    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
