<?php

namespace App\Mail;

use App\Mail\StoreMailable;
use App\Models\Logo;
use App\Models\Store;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

class MagicLinkMail extends StoreMailable
{
    use Queueable, SerializesModels;

    public $user;
    public $store;
    public $logoUrl;
    public $storePath;
    public $loginUrl;

    public function __construct($user, $storeId, string $loginUrl)
    {
        $this->user = $user;
        $this->loginUrl = $loginUrl;
        $this->store = $this->findStoreByLegacyId($storeId);

        $logo = Logo::where('store_id', $storeId)->first();
        $this->logoUrl = $logo ? $logo->logo_url : null;

        if (!$this->store || empty($this->store->base_url)) {
            throw new \Exception('Store URL not found.');
        }

        $this->storePath = rtrim($this->store->base_url, '/');
    }

    public function build()
    {
        return $this->setStoreSender($this->store)
            ->subject('Your sign-in link for ' . ($this->store?->name ?? 'Our Store'))
            ->view('emails.magic_link')
            ->with([
                'user' => $this->user,
                'store' => $this->store,
                'logoUrl' => $this->logoUrl,
                'storePath' => $this->storePath,
                'loginUrl' => $this->loginUrl,
            ]);
    }
}
