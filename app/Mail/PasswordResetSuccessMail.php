<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\User;
use App\Models\Store;
use App\Models\Logo;
use App\Mail\StoreMailable;

class PasswordResetSuccessMail extends StoreMailable
{
    use Queueable, SerializesModels;

    public $user;
    public $logoUrl;
    public $storePath;
    public $store;

    public function __construct(User $user)
    {
        $this->user = $user;

        $storeId = $user->store_id ?? null;

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
        // The "Password Reset" template is the reset-link email
        // (CustomResetPasswordNotification), so this confirmation keeps its Blade design.
        return $this->setStoreSender($this->store)->subject('Your Password Was Successfully Reset')
            ->view('emails.reset_password_successful')
            ->with([
                'name'      => $this->user->name,
                'email'     => $this->user->email,
                'logoUrl'   => $this->logoUrl,
                'storePath' => $this->storePath,
                'store'     => $this->store,
            ]);
    }
}
