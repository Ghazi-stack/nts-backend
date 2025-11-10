<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ShippingDestinationResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'address' => [
                'firstName' => $this->first_name,
                'lastName' => $this->last_name,
                'companyName' => $this->company_name,
                'addressLine1' => $this->address_line_1,
                'addressLine2' => $this->address_line_2,
                'city' => $this->city,
                'state' => $this->state,
                'zip' => $this->zip,
                'country' => $this->country,
                'phone' => $this->phone,
            ],
            'products' => ShippingDestinationProductResource::collection($this->whenLoaded('products')),
            'paymentMethods' => PaymentMethodResource::collection($this->whenLoaded('paymentMethods')),
        ];
    }
}
