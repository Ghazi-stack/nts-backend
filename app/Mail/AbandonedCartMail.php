<?php
namespace App\Mail;

use App\Models\Store;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

class AbandonedCartMail extends StoreMailable
{
    use Queueable, SerializesModels;

    public $cartItems;
    public $userName;
    public Store $store;
    public string $storePath;
    public int $storeId;
    public string $emailType;

    public function __construct(
        $cartItems,
        string $userName,
        int $storeId,
        string $emailType = '1hr'
    ) {
        $this->cartItems = $cartItems;
        $this->userName = $userName;
        $this->storeId = $storeId;
        $this->emailType = $emailType;

        $this->store = Store::findOrFail($storeId);

        $this->storePath = rtrim(
            $this->store->base_url ?? config('app.frontend_url', ''),
            '/'
        );
    }

    public function build()
    {
        $subjects = [
            '1hr'  => 'You left something in your cart!',
            '24hr' => 'Your cart is waiting for you!',
            '48hr' => 'Last chance! Complete your order!',
        ];

        return $this->setStoreSender($this->store)
            ->subject(
                $subjects[$this->emailType]
                    ?? 'You left something in your cart!'
            )
            ->view('emails.abandoned-cart')
            ->with([
                'cartItems' => $this->cartItems,
                'userName'  => $this->userName,
                'store'     => $this->store,
                'storePath' => $this->storePath,
                'emailType' => $this->emailType,
            ]);
    }
}