<?php

namespace App\Mail;

use App\Models\Store;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TwoFactorOtpMail extends StoreMailable
{
    use Queueable, SerializesModels;

    public string $otp;
    public string $userName;
    public ?Store $store;
    public ?int $storeId;

    public function __construct(
        string $otp,
        string $userName,
        ?int $storeId = null
    ) {
        $this->otp = $otp;
        $this->userName = $userName;
        $this->storeId = $storeId;

        $this->store = $storeId
            ? Store::on('central')->find($storeId)
            : null;
    }

    public function envelope(): Envelope
    {
        $storeName = $this->store?->name;

        return new Envelope(
            subject: $storeName
                ? 'Your Login Verification Code - ' . $storeName
                : 'Your Login Verification Code'
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
        if ($this->store) {
            return $this->setStoreSender($this->store);
        }

        return $this;
    }
}