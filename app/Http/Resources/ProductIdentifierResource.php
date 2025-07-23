<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductIdentifierResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
          return [
            'mpn'  => $this->mpn,
            'upc'  => $this->upc,
            'gtin' => $this->gtin,
            'bpn'  => $this->bpn,
            'store_id' => $this->store_id,
        ];
    }
}
