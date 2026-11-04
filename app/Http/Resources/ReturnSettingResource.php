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
            'storeId'                       => $this->store_id,
            'returnsEnabled'                => (bool) $this->returns_enabled,
            'returnInstructions'            => $this->return_instructions,
            'returnReasons'                 => $this->return_reasons ?? [],
            'returnActions'                 => $this->return_actions ?? [],
            'useDefaultReasonsActions'      => (bool) $this->use_default_reasons_actions,
            'allowStoreCredits'             => (bool) $this->allow_store_credits,
            'notifyNewReturn'               => (bool) $this->notify_new_return,
            'notifyCustomerConfirmation'    => (bool) $this->notify_customer_confirmation,
            'notifyStatusChange'            => (bool) $this->notify_status_change,
            'createdAt'                     => $this->created_at,
            'updatedAt'                     => $this->updated_at,
        ];
    }
}
