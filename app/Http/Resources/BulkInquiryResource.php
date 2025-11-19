<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BulkInquiryResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            // 'storeId' => $this->store_id,
            'storeName' => $this->store ? $this->store->name : null, // store name instead of ID
            'email' => $this->email,
            'fullName' => $this->full_name,
            'phone' => $this->phone,
            'comments' => $this->comments,
            'quantity' => $this->quantity,
            'sku' => $this->sku,
            'productLink' => url('/product/'.$this->sku),
            // 'createdAt' => $this->created_at->toDateTimeString(),
            // 'updatedAt' => $this->updated_at->toDateTimeString(),
        ];
    }
}
