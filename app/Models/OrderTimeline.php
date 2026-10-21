<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderTimeline extends Model
{
    protected $connection = 'tenant';
    protected $fillable = [
        'order_id',
        'store_id',
        'event_type',
        'title',
        'details',
        'icon',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Helper: Add timeline entry
     */
    public static function addEvent($orderId, $storeId, $eventType, $title, $details = [], $icon = 'dot')
    {
        return self::create([
            'order_id'   => $orderId,
            'store_id'   => $storeId,
            'event_type' => $eventType,
            'title'      => $title,
            'details'    => $details,
            'icon'       => $icon,
        ]);
    }
}
