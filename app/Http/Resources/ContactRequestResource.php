<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'storeId' => $this->store_id,
            'company' => $this->company,
            'email' => $this->email,
            'fullName' => $this->full_name,
            'partNumber' => $this->part_number,
            'phoneNumber' => $this->phone_number,
            'requiredQty' => $this->required_qty,
            // 'createdAt' => $this->created_at->toDateTimeString(),
            // 'updatedAt' => $this->updated_at->toDateTimeString(),
        ];
    }
}
