<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'email'      => $this->email,
            'phone'      => $this->phone_number,
            'userRole'   => $this->userRole,
            'businessSize'  => $this->business_size,   // ✅ map to DB field
            'storeName'     => $this->store_name,      // ✅ map to DB field
            'region'        => $this->region,
            'region' => $this->region,
            'created_at' => $this->created_at,
        ];
    }
}
