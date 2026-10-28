<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use App\Models\Store;
use App\Models\Logo;
use App\Mail\StoreMailable;

class StoreAccessMail extends StoreMailable
{
    use Queueable, SerializesModels;

    public $userName;
    public $userEmail;
    public $resetLink;
    public $loginLink;
    public $logoUrl;
    public $storePath;
    public $store;
    public $storeName;

    public function __construct($userName, $userEmail, $resetLink, $storeId)
    {
        $this->userName = $userName;
        $this->userEmail = $userEmail;
        $this->resetLink = $resetLink;

        // Look up the central store using legacy_store_id.
        $this->store = $this->findStoreByLegacyId((int) $storeId);

        // Fall back to the primary ID if no legacy ID match exists.
        if (!$this->store) {
            $this->store = Store::on('central')->find($storeId);
        }

        $this->storeName = $this->store?->name ?? 'Store';

        // Do not query tenant logos until the correct tenant database
        // connection has been configured.
        $this->logoUrl = null;

        $this->storePath = rtrim(
            $this->store?->base_url ?? '#',
            '/'
        );

        $this->loginLink =
            'https://admin-dashboard-nu-hazel-12.vercel.app/login';
    }

    public function build()
    {
        return $this->setStoreSender($this->store)
            ->subject('Store Access - ' . $this->storeName)
            ->view('emails.store-access')
            ->with([
                'userName'  => $this->userName,
                'userEmail' => $this->userEmail,
                'resetLink' => $this->resetLink,
                'loginLink' => $this->loginLink,
                'logoUrl'   => $this->logoUrl,
                'storePath' => $this->storePath,
                'store'     => $this->store,
                'storeName' => $this->storeName,
            ]);
    }
}
