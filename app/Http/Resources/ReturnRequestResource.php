<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ReturnRequestResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'            => $this->id,
            'orderId'       => $this->order_id,
            'orderNumber'   => optional($this->order)->order_number,
            'customerId'    => optional($this->order?->customer)->id,
            'customerName'  => optional($this->order?->customer)->name,
            'reason'        => $this->reason,
            'returnAction'  => $this->returnAction,
            'comments'      => $this->comments,
            'status'        => $this->status,
            'is_submit'     => (bool) $this->is_submit,
            'createdAt'     => $this->created_at?->format('Y-m-d H:i:s'),

            // ✅ FULL PRODUCT DATA
            'product' => $this->order && $this->order->items->first()
                ? (new ProductResource(
                    $this->order->items->first()->product->load([
                        'images',
             
                    ])
                )): null,
        ];
    }
}
