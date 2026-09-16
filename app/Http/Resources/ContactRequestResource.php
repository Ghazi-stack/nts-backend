<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ContactRequestResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'            => $this->id,
            'storeName'     => $this->store?->name ?? null,
            'fullName'      => $this->full_name,
            'phoneNumber'   => $this->phone_number,
            'email'         => $this->email,
            'orderNumber'   => $this->order_number,
            'companyName'   => $this->company_name,
            'rmaNumber'     => $this->rma_number,
            'comments'      => $this->comments,
            'createdAt'     => $this->created_at?->format('d-m-Y H:i:s'),
        ];
    }
}
