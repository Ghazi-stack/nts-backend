<?php
namespace App\Jobs;

use App\Mail\AbandonedCartMail;
use App\Models\Cart;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendAbandonedCartEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public $tries = 3;

    public function __construct(
        public Collection $cartItems,
        public string $userEmail,
        public string $userName,
        public int $storeId,
        public string $emailType,
        public string $timestampColumn
    ) {}

    public function handle(): void
    {
        try {
            Mail::to($this->userEmail)->send(
                new AbandonedCartMail(
                    $this->cartItems,
                    $this->userName,
                    $this->storeId,
                    $this->emailType
                )
            );

            // Confirm the reminder timestamp after the send call succeeds.
            Cart::whereIn('id', $this->cartItems->pluck('id'))
                ->update([
                    $this->timestampColumn => now(),
                ]);
        } catch (Throwable $e) {
            // Allow the reminder to be retried if sending fails.
            Cart::whereIn('id', $this->cartItems->pluck('id'))
                ->where($this->timestampColumn, '>=', now()->subMinutes(10))
                ->update([
                    $this->timestampColumn => null,
                ]);

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        Cart::whereIn('id', $this->cartItems->pluck('id'))
            ->where($this->timestampColumn, '>=', now()->subMinutes(10))
            ->update([
                $this->timestampColumn => null,
            ]);
    }
}