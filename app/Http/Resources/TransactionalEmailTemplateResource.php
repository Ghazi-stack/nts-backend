<?php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TransactionalEmailTemplateResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'         => $this->id,
            'storeId'    => $this->store_id,
            'key'        => $this->key,
            'name'       => $this->name,
            'enabled'    => $this->enabled,
            'headers'    => [
                'from_name'  => $this->from_name,
                'from_email' => $this->from_email,
                'reply_to'   => $this->reply_to,
                'cc'         => $this->cc,
                'bcc'        => $this->bcc,
            ],
            'subject'    => $this->subject,
            'bodyHtml'   => $this->body_html,
            'bodyText'   => $this->body_text,
            'variables'  => $this->variables,
            'locale'     => $this->locale,
            'updatedBy'  => $this->whenLoaded('updater', fn()=>[
                'id' => $this->updater?->id,
                'name' => $this->updater?->name,
                'email'=> $this->updater?->email,
            ]),
            'createdAt'  => $this->created_at,
            'updatedAt'  => $this->updated_at,
        ];
    }
}
