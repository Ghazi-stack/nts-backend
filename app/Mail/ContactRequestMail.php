<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\ContactRequest;

class ContactRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public $contactRequest;

    public function __construct(ContactRequest $contactRequest)
    {
        $this->contactRequest = $contactRequest;
    }

    public function build()
    {
        return $this->subject('New Contact Request')
                    ->view('emails.contact-request')
                    ->with([
                        'contact' => $this->contactRequest,
                    ]);
    }
}
