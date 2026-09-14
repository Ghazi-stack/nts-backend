<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Http\Resources\ProductResource;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'productId' => $this->product_id,
            'quantity'  => $this->quantity,
            'draftData' => $this->json_decode($this->draftData),
            'product'   => new ProductResource($this->whenLoaded('product')),
        ];
    }
}
