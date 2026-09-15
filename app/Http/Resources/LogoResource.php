<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LogoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'logoText' => $this->logo_text,
            'logoType' => $this->logo_type,
            'logoUrl' => $this->logo_url,
            'faviconUrl' => $this->favicon_url,
            'logoSize' => $this->logo_size,
            'dimensions' => $this->dimensions ?? ['width' => null, 'height' => null],
            'createdAt' => $this->created_at?->toDateString(),
            'updatedAt' => $this->updated_at?->toDateString(),
        ];
    }
}
