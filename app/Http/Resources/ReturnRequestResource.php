<?php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ReturnRequestResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'           => $this->id,
            'orderId'      => $this->order_id,
            'orderNumber'  => $this->order?->order_number,
            'orderItemId'  => $this->order_item_id,
            'productName'  => $this->orderItem?->product?->name,
            'customerName' => $this->user?->name,
            'reason'       => $this->reason,
            'status'       => $this->status,
            'createdAt'    => $this->created_at?->toDateTimeString(),
        ];
    }
}
