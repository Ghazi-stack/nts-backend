<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PopularProductResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'sku'        => $this->sku,
            'price'      => $this->price,
            'msrp'       => $this->msrp,
            'brand'      => new BrandResource($this->whenLoaded('brand')),

            // Only primary image
            'image' => $this->whenLoaded('images', function () {
                $img = $this->images->first();

                return $img ? [
                    'path'     => $img->path ? asset($img->path) : null,
                    'altText'  => $img->alt_text,
                ] : null;
            }),

        ];
    }
}