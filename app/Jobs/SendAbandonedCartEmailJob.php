<?php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Mail;
use App\Mail\AbandonedCartMail;
use Illuminate\Support\Collection;

class SendAbandonedCartEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $cartItems;
    public $userEmail;
    public $userName;

    public function __construct(Collection $cartItems, string $userEmail, string $userName)
    {
        $this->cartItems = $cartItems;
        $this->userEmail = $userEmail;
        $this->userName = $userName;
    }

    public function handle()
    {
        Mail::to($this->userEmail)->send(
            new AbandonedCartMail($this->cartItems, $this->userName)
        );
    }
}
