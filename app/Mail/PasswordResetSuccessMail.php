<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\User;

class PasswordResetSuccessMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function build()
    {
        return $this->subject('Your Password Was Successfully Reset')
            ->view('emails.password_reset_success')
            ->with([
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]);
    }
}
