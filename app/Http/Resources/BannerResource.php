<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BannerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $location = $this->location ?? [];
        $key = strtolower($location['key'] ?? '');

        $locationType = match ($key) {
            'homepage' => 'homepage',
            'category' => 'category',
            'brand'    => 'brand',
            'search'   => 'search',
            default    => $key,
        };

        $dateRange = $this->date_range;

        if (is_string($dateRange)) {
            $decoded = json_decode($dateRange, true);
            $dateRange = json_last_error() === JSON_ERROR_NONE ? $decoded : $dateRange;
        }

        $isAlways = $dateRange === 0 || $dateRange === '0' || $dateRange === null;

        return [
            'id'           => $this->id,
            'storeId'      => $this->store_id,
            'title'        => $this->title,
            'pageContent'  => $this->page_content,
            'locationType' => $locationType,
            'locationId'   => $location['id'] ?? null,
            'dateType'     => $isAlways ? 'always' : 'range',
            'from'         => $isAlways ? null : ($dateRange['from'] ?? null),
            'through'      => $isAlways ? null : ($dateRange['through'] ?? null),
            'visible'      => (bool) $this->visible,
            'placement'    => $this->placement,
            'createdAt'    => $this->created_at,
            'updatedAt'    => $this->updated_at,
        ];
    }
}
