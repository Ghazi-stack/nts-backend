<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ShipmentResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'orderId' => $this->order_id,
            'shippedTo' => $this->shipped_to,
            'dateShipped' => $this->date_shipped,
            'trackingNumber' => $this->tracking_number,
            'createdAt' => $this->created_at->format('M d, Y'),
            'orderDate' => optional($this->order)->created_at?->format('M d, Y'),
            
            'orderProducts' => $this->order?->items->map(function ($item) {
                return [
                    'productName' => $item->product->name ?? '',
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'total' => $item->quantity * $item->price,
                ];
            }),
        ];
    }
}
