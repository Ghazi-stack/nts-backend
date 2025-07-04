<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WhishlistResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'product_id'   => $this->product_id,
            'product_name' => $this->product->name ?? '',
            'price'        => $this->product->price ?? 0,
            'image'        => $this->product->image ?? null,
        ];
    }
}
