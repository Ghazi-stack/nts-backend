<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ShipmentResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'               => $this->id,
            'orderId'          => $this->order_id,
            'shippedTo'        => $this->shipped_to,
            'dateShipped'      => $this->date_shipped,
            'shippingMethod'   => $this->shipping_method,
            'trackingCarrier'  => $this->tracking_carrier ?? null,
            'shippedBy'        => $this->shipped_by,
            'packingSlipNotes' => $this->packing_slip_note,
            'trackingNumber'   => $this->tracking_number,
            'createdAt'        => optional($this->created_at)->format('M d, Y'),
            'orderDate'        => optional(optional($this->order)->created_at)->format('M d, Y'),

            'orderProducts'    => $this->order?->items->map(function ($item) {
                $product = $item->product;

                return [
                    'productName' => $product->name ?? '',
                    'quantity'    => $item->quantity,
                    'price'       => $item->price,
                    'total'       => $item->quantity * $item->price,
                    'productUrl'  => $product?->seo?->product_url ?? null,
                ];
            }) ?? [],

            'order' => $this->when(
                $this->relationLoaded('order') && $this->order,
                fn() => new OrderResource($this->order)
            ),
        ];
    }
}
