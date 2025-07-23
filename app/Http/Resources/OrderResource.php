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
            'id'              => $this->id,
            'name'            => $this->name,
            'email'           => $this->email,
            'phone'           => $this->phone,
            'status'          => $this->status,
            'address'         => $this->address,
            'paymentMethod'   => $this->payment_method,
            'shippingMethod'  => $this->shipping_method,
            'shippingCost'    => $this->shipping_cost,
            'comments'        => $this->comments,
            'totalAmount'     => $this->total_amount,
            'createdAt'       => $this->created_at,
            'items'           => OrderItemResource::collection($this->whenLoaded('items')),
            'store_id'        => $this->store_id, // Ensure store_id is included
           
        ];
    }
}
