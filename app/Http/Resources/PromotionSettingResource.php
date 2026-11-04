<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromotionSettingResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'                            => $this->id,
            'storeId'                       => $this->store_id,
            'calculateIndependently'        => (bool) $this->calculate_independently,
            'calculateCumulatively'         => (bool) $this->calculate_cumulatively,
            'numberOfCouponsAllowed'        => (int) $this->number_of_coupons_allowed,
            'allowZeroPriceProducts'        => (bool) $this->allow_zero_price_products,
            'allowCustomPriceOverrides'     => (bool) $this->allow_custom_price_overrides,
            'createdAt'                     => $this->created_at,
            'updatedAt'                     => $this->updated_at,
        ];
    }
}
