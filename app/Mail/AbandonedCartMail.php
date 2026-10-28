<?php
namespace App\Mail;

use App\Models\Logo;
use App\Models\Store;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

class AbandonedCartMail extends StoreMailable
{
    use Queueable, SerializesModels;

    public $cartItems;
    public User $user;
    public string $userName;
    public Store $store;
    public ?string $logoUrl;
    public string $storePath;
    public int $storeId;
    public string $emailType;
    public string $checkoutLink;

    public function __construct(
        $cartItems,
        User $user,
        int $storeId,
        string $emailType = '1hr',
        string $checkoutLink = ''
    ) {
        $this->cartItems = $cartItems;
        $this->user = $user;
        $this->userName = $user->name ?? '';
        $this->storeId = $storeId;
        $this->emailType = $emailType;
        $this->checkoutLink = $checkoutLink;

        $this->store = $this->findStoreByLegacyId($storeId);

        $logo = Logo::where('store_id', $storeId)->first();
        $this->logoUrl = $logo?->logo_url;

        $this->storePath = rtrim(
            $this->store->base_url
                ?: config('app.frontend_url', ''),
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
                'user'         => $this->user,
                'userName'     => $this->userName,
                'cartItems'    => $this->cartItems,
                'store'        => $this->store,
                'storePath'    => $this->storePath,
                'logoUrl'      => $this->logoUrl,
                'emailType'    => $this->emailType,
                'checkoutLink' => $this->checkoutLink,
            ]);
    }
}