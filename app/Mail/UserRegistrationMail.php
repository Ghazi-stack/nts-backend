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
    public $logoUrl;
    public $storePath;

    public function __construct($user, $storeId)
    {
        $this->user = $user;

        $this->store = Store::find($storeId);

        // Logo from logos table
        $logo = Logo::where('store_id', $storeId)->first();
        $this->logoUrl = $logo ? $logo->logo_url : null;

        // Dynamic Store URL
        if (!$this->store || empty($this->store->base_url)) {
            throw new \Exception('Store URL not found.');
        }

        $this->storePath = rtrim($this->store->base_url, '/');
    }

    public function build()
    {
        return $this->subject('Thanks for registering at ' . ($this->store?->name ?? 'Our Store'))
            ->view('emails.user_registration')
            ->with([
                'user'      => $this->user,
                'store'     => $this->store,
                'logoUrl'   => $this->logoUrl,
                'storePath' => $this->storePath,
            ]);
    }
}
