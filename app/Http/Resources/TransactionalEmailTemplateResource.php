<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TransactionalEmailTemplateResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'          => $this->id,
            'storeId'     => $this->store_id,
            'key'         => $this->key,
            'name'        => $this->name,
            'description' => $this->description,
            'enabled'     => $this->enabled,
            // true when real emails use this template; false = the store still
            // has the old demo template and real emails use the built-in design.
            'isLive'      => \App\Services\TransactionalEmailService::isLiveTemplate($this->body_html),
            // false for templates whose email the platform does not send yet.
            'sendsEmail'  => in_array($this->key, \App\Services\TransactionalEmailContext::SENDING_KEYS, true),
            'headers'     => [
                'fromName'  => $this->from_name,
                'fromEmail' => $this->from_email,
                'replyTo'   => $this->reply_to,
                'cc'        => $this->cc,
                'bcc'       => $this->bcc,
            ],
            'subject'     => $this->subject,
            'bodyHtml'    => $this->body_html,
            'bodyText'    => $this->body_text,
            'variables'   => $this->variables,
            'phrases'     => $this->whenLoaded('phrases', function () {
                return $this->phrases->map(fn($p) => [
                    'id'          => $p->id,
                    'phraseName'  => $p->phrase_name,
                    'phraseValue' => $p->phrase_value,
                    'isSystem'    => $p->is_system,
                ]);
            }),
            'locale'      => $this->locale,
            'updatedBy'   => $this->whenLoaded('updater', fn() => [
                'id'    => $this->updater?->id,
                'name'  => $this->updater?->name,
                'email' => $this->updater?->email,
            ]),
            'createdAt'   => $this->created_at,
            'updatedAt'   => $this->updated_at,
        ];
    }
}
