<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'store_id'               => $this->store_id,
            'first_name'             => $this->first_name,
            'last_name'              => $this->last_name,
            'name'                   => $this->name,
            'company_name'           => $this->company_name,
            'email'                  => $this->email,
            'customer_group'         => $this->customer_group,
            'phone'                  => $this->phone,
            'address'                => $this->address,
            'store_credit'           => $this->store_credit,
            'receive_review_emails'  => (bool) $this->receive_review_emails,
            'force_password_reset'   => (bool) $this->force_password_reset,
            'tax_exempt_code'        => $this->tax_exempt_code,
            'created_at'             => $this->created_at?->toDateTimeString(),
            'updated_at'             => $this->updated_at?->toDateTimeString(),
        ];
    }
}
