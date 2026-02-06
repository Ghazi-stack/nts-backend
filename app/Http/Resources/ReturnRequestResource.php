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
            // 'orderItemId'  => $this->order_item_id,
            //'productName'  => $this->orderItem?->product?->name,
            'customerId' => $this->user?->id,
            'customerName' => $this->user?->name,
            'reason'       => $this->reason,
            'returnAction'       => $this->returnAction,
            'comments'       => $this->comments,
            'status'       => $this->status,
            'is_submit'       => $this->is_submit,
            'createdAt'    => $this->created_at?->toDateTimeString(),
        ];
    }
}
