<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentMethodResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'method' => $this->method,
            'cardType' => $this->card_type,
            'cardHolderName' => $this->card_holder_name,
            'ccv2Value' => $this->ccv2_value,
            'email' => $this->email,
            'invoice' => $this->invoice,
            'expirationMonth' => $this->expiration_month,
            'expirationYear' => $this->expiration_year,
        ];
    }
}
