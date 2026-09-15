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
            'customerName'  => optional($this->order?->customer)->name
                ?? trim(implode(' ', array_filter([
                    optional($this->order?->customer)->first_name,
                    optional($this->order?->customer)->last_name,
                ])))
                ?: optional($this->user)->name,
            'reason'        => $this->reason,
            'returnAction'  => $this->returnAction,
            'comments'      => $this->comments,
            'status'        => $this->status,
            'is_submit'     => (bool) $this->is_submit,
            'createdAt' => $this->created_at?->toDateString(),
            'updatedAt' => $this->updated_at?->toDateString(),

            'product' => $this->order
                ? $this->order->items->map(function ($item) {
                    return new ProductResource(
                        $item->product->load([
                            'images'
                        ])
                    );
                })->values()
                : [],
        ];
    }
}
