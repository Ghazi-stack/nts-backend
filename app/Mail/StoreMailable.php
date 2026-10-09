<?php

namespace App\Mail;

use App\Models\Store;
use Illuminate\Mail\Mailable;

abstract class StoreMailable extends Mailable
{
    protected function setStoreSender(?Store $store): static
    {
        return $this->from(
            config('mail.from.address'),
            $store?->name ?: config('mail.from.name')
        );
    }
}