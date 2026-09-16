<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WishlistResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'productId' => $this->product_id,
            'product'    => $this->whenLoaded('product'), // only includes product if loaded
            'created_at' => $this->created_at->toDateTimeString(),
        ];
    }
}
