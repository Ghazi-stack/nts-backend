<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AbandonedCartMail extends Mailable
{
    use Queueable, SerializesModels;

    public $cartItems;
    public $userName;

    public function __construct($cartItems, $userName)
    {
        $this->cartItems = $cartItems;
        $this->userName = $userName;
    }

    public function build()
    {
        return $this->subject('You left something in your cart!')
                    ->view('emails.abandoned_cart')
                    ->with([
                        'cartItems' => $this->cartItems,
                        'userName' => $this->userName,
                    ]);
    }
}
