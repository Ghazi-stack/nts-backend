<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Store;
use App\Models\Logo;

class StoreAccessMail extends Mailable
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

        $this->store = Store::find($storeId);
        $this->storeName = $this->store?->name ?? 'Store';

        $logo = Logo::where('store_id', $storeId)->first();
        $this->logoUrl = $logo ? $logo->logo_url : null;

        $this->storePath = rtrim($this->store?->base_url ?? '#', '/');
        $this->loginLink = 'https://admin-dashboard-nu-hazel-12.vercel.app/login';
    }

    public function build()
    {
        return $this->subject('Store Access - ' . $this->storeName)
            ->view('emails.store-access')
            ->with([
                'userName'   => $this->userName,
                'userEmail'  => $this->userEmail,
                'resetLink'  => $this->resetLink,
                'loginLink'  => $this->loginLink,
                'logoUrl'    => $this->logoUrl,
                'storePath'  => $this->storePath,
                'store'      => $this->store,
                'storeName'  => $this->storeName,
            ]);
    }
}
