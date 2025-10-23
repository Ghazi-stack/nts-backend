<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CarouselResource extends JsonResource
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
            'altText' => $this->alt_text,
            'heading' => $this->heading,
            'buttonText' => $this->button_text,
            'text' => $this->text,
            'link' => $this->link,
            'imageUrl' => $this->image_url, // Full URL returned
            'order' => $this->order,
        ];
    }
}
