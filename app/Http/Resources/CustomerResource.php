<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\AddressResource;
use App\Http\Resources\BillingInformationResource;

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
            'id'                  => $this->id,
            'storeId'             => $this->store_id,
            'firstName'           => $this->first_name,
            'lastName'            => $this->last_name,
            'companyName'         => $this->company_name,
            'email'               => $this->email,
            'customerGroup'       => $this->customer_group,
            'phone'               => $this->phone,
            'storeCredit'         => $this->store_credit,
            'receiveReviewEmails' => (bool) $this->receive_review_emails,
            'forcePasswordReset'  => (bool) $this->force_password_reset,
            'taxExemptCode'       => $this->tax_exempt_code,
            'country'             => $this->country,
            'addressLine1'        => $this->address,
            'addressLine2'        => $this->address_line_2,
            'state'               => $this->state,
            'analytics'           => $this->analytics,
            'functional'          => $this->functional,
            'targeting'           => $this->targeting,
            'notes'               => $this->notes,
            'userType'            => $this->user_type,
            'createdAt'           => $this->created_at,
            'updatedAt'           => $this->updated_at,

            'totalOrders'         => (int) (
                $this->total_orders
                ?? ($this->relationLoaded('orders')
                    ? $this->orders->where('is_draft', 0)->count()
                    : $this->orders()->where('is_draft', 0)->count())
            ),
            'joinDate'            => optional($this->created_at)->format('M d, Y'),

            'addresses'           => AddressResource::collection($this->whenLoaded('addresses')),
            'billingInformation'  => BillingInformationResource::collection($this->whenLoaded('billingInformations')),
        ];
    }
}
