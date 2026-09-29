<?php

namespace App\Http\Resources;

use App\Models\Promotion;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class PromotionResource extends JsonResource
{
    public function toArray($request)
    {
        if ($this->resource instanceof Promotion) {
            return $this->mapOne($this->resource);
        }

        $all = $this->resource instanceof Collection
            ? $this->resource
            : collect($this->resource);

        $grouped = $all->groupBy(function ($p) {
            return $p->kind ?: 'automatic';
        });

        return [
            'automatic' => $grouped->get('automatic', collect())->map(fn($p) => $this->mapOne($p))->values(),
            'coupon'    => $grouped->get('coupon', collect())->map(fn($p) => $this->mapOne($p))->values(),
        ];
    }

    private function mapOne($p): array
    {
        return [
            'id'              => $p->id,
            'kind'            => $p->kind,
            'type'            => $p->type,
            'name'            => $p->name,
            'displayName'     => $p->display_name,
            'status'          => $p->status,
            'priority'        => (int) $p->priority,
            'currency'        => $p->currency,
            'channel'         => $p->channel,
            'isFeatured'      => (bool) $p->is_featured,
            'featuredMessage' => $p->featured_message,
            'maxRedemptions'  => $p->max_redemptions,
            'redemptions'     => $p->redemptions,
            'canCombine'      => (bool) $p->can_combine,
            'conditions'      => $p->conditions,
            'actions'         => $p->actions,
            'startsAt'        => optional($p->starts_at)->toDateTimeString(),
            'endsAt'          => optional($p->ends_at)->toDateTimeString(),
            'schedule'        => $p->schedule,
            'targeting'       => $p->targeting,
            'banners'         => $p->banners,
            'createdAt'       => optional($p->created_at)->toDateTimeString(),
            'updatedAt'       => optional($p->updated_at)->toDateTimeString(),
        ];
    }
}
