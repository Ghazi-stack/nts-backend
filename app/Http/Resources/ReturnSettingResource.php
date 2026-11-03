<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReturnSettingResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'                            => $this->id,
            'store_id'                      => $this->store_id,
            'returns_enabled'               => (bool) $this->returns_enabled,
            'return_instructions'           => $this->return_instructions,
            'return_reasons'                => $this->return_reasons ?? [],
            'return_actions'                => $this->return_actions ?? [],
            'use_default_reasons_actions'   => (bool) $this->use_default_reasons_actions,
            'allow_store_credits'           => (bool) $this->allow_store_credits,
            'notify_new_return'             => (bool) $this->notify_new_return,
            'notify_customer_confirmation'  => (bool) $this->notify_customer_confirmation,
            'notify_status_change'          => (bool) $this->notify_status_change,
            'created_at'                    => $this->created_at,
            'updated_at'                    => $this->updated_at,
        ];
    }
}
