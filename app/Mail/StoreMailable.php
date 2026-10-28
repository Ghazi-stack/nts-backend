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

    protected function findStoreByLegacyId(int $legacyStoreId): Store
    {
        return Store::on('central')
            ->where('legacy_store_id', $legacyStoreId)
            ->firstOrFail();
    }
}