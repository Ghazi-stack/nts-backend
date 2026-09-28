<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Http\Resources\StoreResource;
use Illuminate\Http\Resources\Json\JsonResource;

class BannerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $dateRange = $this->date_range;

        if ($dateRange === '0' || $dateRange === 0) {
            $dateRange = 0;
        } elseif (is_string($dateRange)) {
            $decoded = json_decode($dateRange, true);
            $dateRange = json_last_error() === JSON_ERROR_NONE ? $decoded : $dateRange;
        }

        return [
            'id'          => $this->id,
            'title'       => $this->title,
            'pageContent' => $this->page_content,
            'location'    => $this->location,
            'dateRange'   => $dateRange,
            'visible'     => (bool) $this->visible,
            'placement'   => $this->placement,
            'store'       => new StoreResource($this->whenLoaded('store')),
        ];
    }
}
