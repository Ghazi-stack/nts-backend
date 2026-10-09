<?php

namespace App\Mail;

use App\Models\Cart;
use App\Models\User;
use App\Models\Store;
use App\Models\Logo;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Mail\StoreMailable;

class AbandonedCartEmail extends StoreMailable
{
    use Queueable, SerializesModels;

    public Store  $store;
    public ?string $logoUrl;
    public string  $storePath;

    public function __construct(
        public User   $user,
        public array  $cartItems,
        public string $emailType,
        public string $checkoutLink,
        public int    $storeId
    ) {
        // Store record
        $this->store     = Store::find($storeId);

        // Logo
        $logo            = Logo::where('store_id', $storeId)->first();
        $this->logoUrl   = $logo?->logo_url ?? null;

        // Store base URL
        $this->storePath = $this->store?->base_url ?? config('app.frontend_url');
    }

    public function build()
    {
        $subjects = [
            '1hr'  => 'You left something behind!',
            '24hr' => 'Your cart is waiting for you!',
            '48hr' => 'Last chance! Complete your order!',
        ];

        return $this->setStoreSender($this->store)
            ->subject($subjects[$this->emailType] ?? 'Your cart is waiting!')
            ->view('emails.abandoned-cart')
            ->with([
                'user'         => $this->user,
                'cartItems'    => $this->cartItems,
                'emailType'    => $this->emailType,
                'checkoutLink' => $this->checkoutLink,
                'store'        => $this->store,
                'logoUrl'      => $this->logoUrl,
                'storePath'    => $this->storePath,
            ]);
    }
}
