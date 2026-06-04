<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\Store;

class UserRegistrationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $store;

    public function __construct($user, $storeId)
    {
        $this->user = $user;
        $this->store = Store::find($storeId);
    }

    public function build()
    {
        return $this->subject('Welcome to Our Store!')
                    ->view('emails.user_registration')
                    ->with([
                        'user' => $this->user,
                        'store' => $this->store,
                    ]);
    }
}
