<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BannerResource extends JsonResource
{
    public function toArray($request)
    {
        $location = is_array($this->location) ? $this->location : [];

        $type = $this->resolved_location_type
            ?? ($location['key'] ?? null)
            ?? $this->location_type
            ?? ($location['type'] ?? null)
            ?? ($location['locationType'] ?? null);

        $id = $this->resolved_location_id
            ?? ($location['id'] ?? null)
            ?? $this->location_id
            ?? ($location['locationId'] ?? null);

        $type = $type ? strtolower((string) $type) : null;
        $id   = $id ? (int) $id : null;

        [$dateType, $startDate, $endDate] = $this->resolveDateRange($this->date_range);

        return [
            'id'           => $this->id,
            'storeId'      => $this->store_id,
            'title'        => $this->title,
            'pageContent'  => $this->page_content,
            'locationType' => $type,
            'locationId'   => $id,
            'dateType'     => $dateType,
            'startDate'    => $startDate,
            'endDate'      => $endDate,
            'visible'      => (bool) $this->visible,
            'placement'    => $this->placement,
            'createdAt'    => $this->created_at,
            'updatedAt'    => $this->updated_at,

            'category' => $type === 'category' && $this->location_category
                ? [
                    'id'   => $this->location_category->id,
                    'name' => $this->location_category->name,
                    'slug' => $this->location_category->slug ?: \Illuminate\Support\Str::slug($this->location_category->name),
                ]
                : null,

            'brand' => $type === 'brand' && $this->location_brand
                ? [
                    'id'   => $this->location_brand->id,
                    'name' => $this->location_brand->name,
                    'slug' => $this->location_brand->slug ?: \Illuminate\Support\Str::slug($this->location_brand->name),
                ]
                : null,
        ];
    }

    private function resolveDateRange($dateRange): array
    {
        if ($dateRange === 0 || $dateRange === '0' || $dateRange === null || $dateRange === '') {
            return ['always', null, null];
        }

        if (is_string($dateRange)) {
            $decoded = json_decode($dateRange, true);
            $dateRange = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($dateRange)) {
            return ['always', null, null];
        }

        $start = $dateRange['startDate'] ?? $dateRange['start'] ?? null;
        $end   = $dateRange['endDate'] ?? $dateRange['end'] ?? null;

        if (!$start && !$end) {
            return ['always', null, null];
        }

        return ['range', $start, $end];
    }
}
