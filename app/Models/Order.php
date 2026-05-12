<?php

namespace App\Models;

use App\Models\User;
use App\Models\Store;
use App\Models\Payment;
use App\Models\Customer;
use App\Models\Shipment;
use App\Models\OrderItem;
use App\Models\OrderNote;
use App\Models\BillingInformation;
use App\Models\ShippingDestination;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Order extends Model
{
    use HasFactory;
    protected $fillable = [
        'store_id',
        'user_id',
        'session_id',
        'order_number',
        'billing_information_id', // ✅ Add this
        'name',
        'email',
        'phone',
        'address',
        'status',
        'is_draft',
        'device_type',
        'user_type',
        'channel',
        'shipping_cost',
        'shipping_provider',
        'shipping_method',
        'comments',
        'total_amount',
        'discountAmount',
        'tracking_number',
        'tracking_carrier_code',
        'tracking_carrier_name',
        'shipped_at',
        'customer_id',
        'staff_notes',
        'newsletter'
    ];
    protected $casts = [
        'is_draft'   => 'boolean',
        'shipped_at' => 'datetime',
        'newsletter' => 'boolean'
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
    public function shipments()
    {
        return $this->hasMany(Shipment::class); // Adjust class name if your model is namespaced
    }
    public function billingInformation()
    {
        return $this->belongsTo(BillingInformation::class);
    }
    public function shippingDestinations()
    {
        return $this->hasMany(ShippingDestination::class);
    }
    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }
    public function returnRequest()
    {
        return $this->hasOne(ReturnRequest::class, 'order_id');
    }
}
