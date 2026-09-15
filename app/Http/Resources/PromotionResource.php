<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class PromotionResource extends JsonResource
{
    public function toArray($request)
    {
        $all = $this->resource instanceof Collection
            ? $this->resource
            : collect($this->resource);

        $grouped = $all->groupBy('type');

        $map = function ($p) {
            return [
                'id'        => $p->id,
                'type'      => $p->type,        // 'automatic' | 'coupon'
                'name'      => $p->name,
                'status'    => $p->status,      // 'active' | 'inactive'
                'priority'  => (int) $p->priority,
                'currency'  => $p->currency,
                'channel'   => $p->channel,

                // coupon-only (null for automatic)
                'code'              => $p->type === 'coupon' ? $p->code : null,
                'maxRedemptions'    => $p->type === 'coupon' ? $p->max_redemptions : null,
                'perCustomerLimit'  => $p->type === 'coupon' ? $p->per_customer_limit : null,
                'redemptions'       => $p->type === 'coupon' ? $p->redemptions : null,

                'conditions' => $p->conditions,
                'actions'    => $p->actions,

                'startsAt'   => optional($p->starts_at)->toDateString(),
                'endsAt'     => optional($p->ends_at)->toDateString(),

                'extra'      => $p->extra,
                'createdAt'  => optional($p->created_at)->toDateString(),
                'updatedAt'  => optional($p->updated_at)->toDateString(),
            ];
        };

        return [
            'automatic' => $grouped->get('automatic', collect())->map($map)->values(),
            'coupon'    => $grouped->get('coupon', collect())->map($map)->values(),
        ];
    }
}
