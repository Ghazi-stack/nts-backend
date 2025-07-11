<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Cart;
use App\Models\User;
use App\Jobs\SendAbandonedCartEmailJob;
use Carbon\Carbon;

class SendAbandonedCartReminder extends Command
{
    protected $signature = 'cart:send-abandoned-reminders';
    protected $description = 'Send abandoned cart email reminders';

    public function handle()
    {
        $cutoff = Carbon::now()->subHours(2); // Change to 24 if needed

        $users = User::whereHas('carts', function ($q) use ($cutoff) {
            $q->whereNull('deleted_at')
              ->where('reminder_sent', 0)
              ->where('updated_at', '<=', $cutoff);
        })->get();

        foreach ($users as $user) {
            $cartItems = $user->carts()->whereNull('deleted_at')->get();

            if ($cartItems->isNotEmpty()) {
                dispatch(new SendAbandonedCartEmailJob($cartItems, $user->email, $user->name));

                // Optionally mark as reminder_sent
                foreach ($cartItems as $item) {
                    $item->reminder_sent = 1;
                    $item->save();
                }
            }
        }

        $this->info('Abandoned cart reminders sent.');
    }
}
