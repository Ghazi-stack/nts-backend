<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Http\Resources\OrderItemResource;
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
            'id'             => $this->id,
            'orderNumber'    => $this->order_number,
            // 'storeId'        => $this->store_id,
            // 'name'           => $this->name,
            // 'email'          => $this->email,
            // 'phone'          => $this->phone,
            'status'         => $this->status,
            // 'address'        => $this->address,
            'paymentMethod'  => $this->payment_method,
            'shippingMethod' => $this->shipping_method,
            'shippingCost'   => $this->shipping_cost,
            'comments'       => $this->comments,
            'staffNotes'     => $this->staff_notes,
            'isDraft'        => (bool) $this->is_draft,
            'totalAmount'    => $this->total_amount,

            // Tracking fields (used by the import feature)
            'trackingNumber' => $this->tracking_number,
            'carrierCode'    => $this->tracking_carrier_code,
            'carrierName'    => $this->tracking_carrier_name,
            'shippedAt'      => $this->shipped_at?->toDateTimeString(),

            'products'          => OrderItemResource::collection($this->whenLoaded('items')),
            'customer'       => new CustomerResource($this->whenLoaded('customer')),

            // Timestamps as strings
            'createdAt'      => $this->created_at?->toDateTimeString(),
            'updatedAt'      => $this->updated_at?->toDateTimeString(),
           
        ];
    }
}
