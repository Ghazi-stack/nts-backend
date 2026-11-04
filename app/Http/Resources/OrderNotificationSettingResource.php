<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderNotificationSettingResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'                => $this->id,
            'storeId'           => $this->store_id,
            'emailAddress'      => $this->email_address,
            'emailEnabled'      => (bool) $this->email_enabled,
            'notifyOnStatuses'  => $this->notify_on_statuses ?? [],
            'createdAt'         => $this->created_at,
            'updatedAt'         => $this->updated_at,
        ];
    }
}
