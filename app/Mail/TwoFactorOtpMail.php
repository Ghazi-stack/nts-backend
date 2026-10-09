<?php

namespace App\Mail;

use App\Models\Store;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Mail\StoreMailable;

class TwoFactorOtpMail extends StoreMailable
{
    use Queueable, SerializesModels;

    public string $otp;
    public string $userName;
    public Store $store;
    public int $storeId;

    public function __construct(
        string $otp,
        string $userName,
        int $storeId
    ) {
        $this->otp = $otp;
        $this->userName = $userName;
        $this->storeId = $storeId;

        $this->store = Store::findOrFail($storeId);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Login Verification Code - ' . $this->store->name
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.two-factor-otp',
            with: [
                'otp'      => $this->otp,
                'userName' => $this->userName,
                'store'    => $this->store,
            ]
        );
    }

    public function build()
    {
        return $this->setStoreSender($this->store);
    }
}
