<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Store;
use App\Models\Logo;
use App\Mail\StoreMailable;

class AdminForgotPasswordMail extends StoreMailable
{
    use Queueable, SerializesModels;

    public $userName;
    public $userEmail;
    public $resetLink;
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
    }

    public function build()
    {
        return $this->setStoreSender($this->store)->subject('Reset Your Password - ' . $this->storeName)
            ->view('emails.admin-forgot-password')
            ->with([
                'userName'  => $this->userName,
                'userEmail' => $this->userEmail,
                'resetLink' => $this->resetLink,
                'logoUrl'   => $this->logoUrl,
                'storePath' => $this->storePath,
                'store'     => $this->store,
                'storeName' => $this->storeName,
            ]);
    }
}
