<?php

namespace App\Models;

use App\Models\User;
use App\Models\Store;
use Illuminate\Database\Eloquent\Model;

class Shipment extends Model
{
     protected $fillable = [
        'order_id',
        'store_id',
        'shipped_to',
        'date_shipped',
        'tracking_number',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * (Optional) Shipment can be linked to a User (if needed)
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
