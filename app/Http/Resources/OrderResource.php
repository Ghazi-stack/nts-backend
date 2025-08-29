<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'orderNumber'       => $this->order_number,
            'status'            => $this->status,
            'billingInformation'=> new BillingInformationResource($this->whenLoaded('billingInformation')),
            'shippingCost'      => $this->shipping_cost,
            'comments'          => $this->comments,
            'staffNotes'        => $this->staff_notes,
            'isDraft'           => (bool) $this->is_draft,
            'totalAmount'       => $this->total_amount,

            // Tracking info
            'trackingNumber'    => $this->tracking_number,
            'carrierCode'       => $this->tracking_carrier_code,
            'carrierName'       => $this->tracking_carrier_name,
            'shippedAt'         => $this->shipped_at?->toDateTimeString(),

            // ✅ Products (flattened with product, quantity, price)
            'products' => $this->whenLoaded('items', function () {
                return $this->items->map(function ($item) {
                    return [
                        'id'       => $item->id,
                        'product'  => new ProductResource($item->product),
                        'quantity' => $item->quantity,
                        'price'    => $item->price,
                        'total'    => $item->total ?? $item->price * $item->quantity,
                    ];
                });
            }),

            // Customer details
            'customer' => new CustomerResource($this->whenLoaded('customer')),

            // Timestamps
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
