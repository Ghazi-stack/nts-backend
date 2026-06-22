<?php

namespace App\Mail;

use App\Models\Cart;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AbandonedCartEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User   $user,
        public array  $cartItems,   // formatted cart items
        public string $emailType,   // '1hr', '24hr', '48hr'
        public string $checkoutLink // checkout URL
    ) {}

    public function envelope(): Envelope
    {
        $subjects = [
            '1hr'  => 'You left something behind!',
            '24hr' => 'Your cart is waiting for you!',
            '48hr' => 'Last chance! Items in your cart are going fast!',
        ];

        return new Envelope(
            subject: $subjects[$this->emailType] ?? 'Your cart is waiting!',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.abandoned-cart',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
