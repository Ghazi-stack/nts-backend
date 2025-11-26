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
            'heading' => $this->heading,
            'text' => $this->text,
            'buttonText' => $this->button_text,
            'link' => $this->link,
            'altText' => $this->alt_text,
            'image' => $this->image_url,
            'order' => $this->order,
        ];

    }
}
