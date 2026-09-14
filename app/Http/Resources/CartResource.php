<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Http\Resources\ProductResource;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $draftData = $this->draftData;

        if (is_array($draftData)) {
            $draftData = $draftData['data'] ?? $draftData;
            unset($draftData['products']);
        }

        return [
            'id'        => $this->id,
            'productId' => $this->product_id,
            'quantity'  => $this->quantity,
            'draftData' => $draftData,
            'product'   => new ProductResource($this->whenLoaded('product')),
        ];
    }
}
