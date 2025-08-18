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
            'storeId'               => $this->store_id,
            'firstName'             => $this->first_name,
            'lastName'              => $this->last_name,
            'companyName'           => $this->company_name,
            'email'                  => $this->email,
            'customerGroup'         => $this->customer_group,
            'phone'                  => $this->phone,
            'address'                => $this->address,
            'storeCredit'           => $this->store_credit,
            'receiveReviewEmails'  => (bool) $this->receive_review_emails,
            'forcePasswordReset'   => (bool) $this->force_password_reset,
            'taxExemptCode'        => $this->tax_exempt_code,
            'createdAt'             => $this->created_at?->toDateTimeString(),
            'updatedAt'             => $this->updated_at?->toDateTimeString(),
        ];
    }
}
