<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderNotificationSettingResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'                 => $this->id,
            'store_id'           => $this->store_id,
            'email_address'      => $this->email_address,
            'email_enabled'      => (bool) $this->email_enabled,
            'notify_on_statuses' => $this->notify_on_statuses ?? [],
            'created_at'         => $this->created_at,
            'updated_at'         => $this->updated_at,
        ];
    }
}
