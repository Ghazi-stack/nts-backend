<?php

namespace App\Mail;

use App\Models\Store;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Mail\StoreMailable;

class ResetPasswordMail extends StoreMailable
{
    use Queueable, SerializesModels;

    public $token;
    public Store $store;
    public int $storeId;

    public function __construct($token, int $storeId)
    {
        $this->token = $token;
        $this->storeId = $storeId;

        $this->store = $this->findStoreByLegacyId($storeId);
    }

    public function build()
    {
        $resetUrl = url('/reset-password?token=' . $this->token);

        return $this->setStoreSender($this->store)->subject('Reset Your Password')
                    ->view('emails.reset_password')
                    ->with([
                        'resetUrl' => $resetUrl,
                    ]);
    }
}
