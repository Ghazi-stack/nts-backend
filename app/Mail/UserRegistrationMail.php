<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Store;
use App\Models\Logo;

class UserRegistrationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $store;
    public $logo;

    public function __construct($user, $storeId)
    {
        $this->user = $user;
        $this->store = Store::find($storeId);
        $this->logo = Logo::where('store_id', $storeId)->first();
    }

    public function build()
    {
        return $this->subject('Welcome to ' . ($this->store?->name ?? 'Our Store') . '!')
            ->view('emails.user_registration')
            ->with([
                'user' => $this->user,
                'store' => $this->store,
                'logo' => $this->logo,
            ]);
    }
}
