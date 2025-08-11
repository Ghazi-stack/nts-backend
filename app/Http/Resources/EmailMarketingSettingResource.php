<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EmailMarketingSettingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request)
    {
        return [
            'id'                        =>$this->id,
            'storeId'                    => $this->store_id,
            'allowNewsletterSubscriptions'=> (bool) $this->allow_newsletter_subscriptions,
            'checkNewsletterBoxByDefault' => (bool) $this->check_newsletter_box_by_default,
            'showNewsletterSummary'       => (bool) $this->show_newsletter_summary,
            'extra'                       => $this->extra ?? (object)[],
        ];
    }
}
