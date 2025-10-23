<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LogoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request)
    {
        return [
            'logoUrl' => $this->logo_url,
            'faviconUrl' => $this->favicon_url,
            'logoSize' => $this->logo_size,
        ];
    }
}
