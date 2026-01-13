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
            'id'                        => $this->id,
            'storeId'                    => $this->store_id,
            'allowNewsletterSubscriptions' => (bool) $this->allowNewsletterSubscriptions,
            'checkNewsletterBoxByDefault' => (bool) $this->checkNewsletterBoxByDefault,
            'newsletterSummaryText'       =>  $this->newsletterSummaryText,
            'showNewsletterSummary'       => (bool) $this->showNewsletterSummary,
            // 'extra'                       => $this->extra ?? (object)[],
        ];
    }
}
