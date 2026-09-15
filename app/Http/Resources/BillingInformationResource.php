<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BillingInformationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $shippingData = $this->shipping_data;

        if (is_array($shippingData)) {
            $shippingData = $shippingData['display_name']
                ?? $shippingData['label']
                ?? $shippingData['data']
                ?? $this->shipping_method;
        }

        return [
            'id'              => $this->id,
            'storeId'         => $this->store_id,
            'customerId'      => $this->customer_id,
            'userId'          => $this->user_id,
            'firstName'       => $this->first_name,
            'lastName'        => $this->last_name,
            'companyName'     => $this->company_name,
            'email'           => $this->email,
            'phone'           => $this->phone,
            'addressLine1'    => $this->address_line_1,
            'addressLine2'    => $this->address_line_2,
            'city'            => $this->city,
            'state'           => $this->state,
            'zip'             => $this->zip,
            'country'         => $this->country,
            'paymentMethod'   => $this->payment_method,
            'shippingMethod'  => $this->shipping_method,
            'shippingData'    => $shippingData,
            'createdAt' => $this->created_at?->toDateString(),
            'updatedAt' => $this->updated_at?->toDateString(),
        ];
    }
}
