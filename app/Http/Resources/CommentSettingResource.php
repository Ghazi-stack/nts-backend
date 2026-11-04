<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommentSettingResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'                            => $this->id,
            'storeId'                       => $this->store_id,
            'commentSystem'                 => $this->comment_system,
            'enableProductReviews'          => (bool) $this->enable_product_reviews,
            'onlyAcceptFromPastCustomers'   => (bool) $this->only_accept_from_past_customers,
            'createdAt'                     => $this->created_at,
            'updatedAt'                     => $this->updated_at,
        ];
    }
}
