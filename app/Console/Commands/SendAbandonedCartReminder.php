<?php
namespace App\Console\Commands;

use App\Jobs\SendAbandonedCartEmailJob;
use App\Models\Cart;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendAbandonedCartReminder extends Command
{
    protected $signature = 'cart:send-abandoned-reminders';

    protected $description = 'Queue abandoned cart reminder emails';

    public function handle(): int
    {
        $now = now();

        $reminders = [
            '1hr' => [
                'hours' => 1,
                'column' => 'email_1hr_sent_at',
            ],
            '24hr' => [
                'hours' => 24,
                'column' => 'email_24hr_sent_at',
            ],
            '48hr' => [
                'hours' => 48,
                'column' => 'email_48hr_sent_at',
            ],
        ];

        $queued = 0;
        $skipped = 0;

        foreach ($reminders as $emailType => $reminder) {
            $cutoff = Carbon::parse($now)->subHours($reminder['hours']);
            $column = $reminder['column'];

            $query = Cart::query()
                ->whereNull('deleted_at')
                ->whereNull($column)
                ->whereNotNull('store_id')
                ->whereNotNull('user_id')
                ->where('updated_at', '<=', $cutoff)
                ->whereHas('user');

            // Earlier reminder stages must have been sent before later stages.
            if ($emailType === '24hr') {
                $query->whereNotNull('email_1hr_sent_at');
            } elseif ($emailType === '48hr') {
                $query->whereNotNull('email_24hr_sent_at');
            }

            $query->orderBy('id')
                ->chunkById(200, function ($carts) use (
                    $emailType,
                    $column,
                    &$queued,
                    &$skipped
                ) {
                    $groups = $carts->groupBy(
                        fn ($cart) => $cart->user_id . ':' . $cart->store_id
                    );

                    foreach ($groups as $group) {
                        $firstCart = $group->first();
                        $user = $firstCart->user;

                        if (!$user || !$user->email) {
                            $skipped += $group->count();
                            continue;
                        }

                        $storeId = (int) $firstCart->store_id;

                        $cartIds = $group->pluck('id')->all();

                        // Atomically reserve these carts so overlapping
                        // scheduler runs don't queue the same reminder twice.
                        $reserved = DB::table('carts')
                            ->whereIn('id', $cartIds)
                            ->whereNull($column)
                            ->update([
                                $column => now(),
                            ]);

                        if ($reserved === 0) {
                            continue;
                        }

                        // The timestamp is a reservation until the job
                        // succeeds. The job clears it if sending fails.
                        $reservedCarts = Cart::query()
                            ->whereIn('id', $cartIds)
                            ->where($column, '>=', now()->subMinute())
                            ->get();

                        if ($reservedCarts->isEmpty()) {
                            continue;
                        }

                        try {
                            SendAbandonedCartEmailJob::dispatch(
                                $reservedCarts,
                                $user->email,
                                $user->name ?? '',
                                $storeId,
                                $emailType,
                                $column
                            );

                            $queued++;
                        } catch (\Throwable $e) {
                            Cart::whereIn('id', $cartIds)
                                ->where($column, '>=', now()->subMinute())
                                ->update([$column => null]);

                            report($e);
                            $skipped += count($cartIds);
                        }
                    }
                });
        }

        $this->info("Reminder jobs queued: {$queued}");
        $this->info("Carts skipped: {$skipped}");

        return self::SUCCESS;
    }
}