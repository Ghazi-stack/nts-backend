<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductContentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'description'      => $this->description,
            'warranty'         => $this->warranty,
            'availabilityText' => $this->availability_text,
            'templateLayout'   => $this->template_layout,
            'store_id'         => $this->store_id,
        ];
    }
}
