<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class BannerResource extends JsonResource
{
    public function toArray($request)
    {
        $rawLocation = is_array($this->location) ? $this->location : [];

        $type = $this->resolved_location_type
            ?? ($rawLocation['key'] ?? null);

        $id = $this->resolved_location_id
            ?? ($rawLocation['id'] ?? null);

        $type = $type ? strtolower((string) $type) : null;
        $id   = $id ? (int) $id : null;

        [$dateType, $startDate, $endDate] = $this->resolveDateRange($this->date_range);

        $hasLocationEntity = (bool) ($this->location_category || $this->location_brand);

        return [
            'id'           => $this->id,
            'storeId'      => $this->store_id,
            'title'        => $this->title,
            'pageContent'  => $this->page_content,
            'locationType' => $type,
            'locationId'   => $this->when(!$hasLocationEntity, $id),
            'location'     => $this->when($hasLocationEntity, function () {
                if ($this->location_category) {
                    return [
                        'id'   => $this->location_category->id,
                        'name' => $this->location_category->name,
                        'slug' => $this->location_category->slug
                            ?: Str::slug($this->location_category->name),
                    ];
                }

                return [
                    'id'   => $this->location_brand->id,
                    'name' => $this->location_brand->name,
                    'slug' => $this->location_brand->slug
                        ?: Str::slug($this->location_brand->name),
                ];
            }),
            'dateType'     => $dateType,
            'startDate'    => $startDate,
            'endDate'      => $endDate,
            'visible'      => (bool) $this->visible,
            'placement'    => $this->placement,
            'createdAt'    => $this->created_at,
            'updatedAt'    => $this->updated_at,
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
