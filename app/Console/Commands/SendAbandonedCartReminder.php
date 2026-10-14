<?php

namespace App\Console\Commands;

use App\Jobs\SendAbandonedCartEmailJob;
use App\Models\Cart;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendAbandonedCartReminder extends Command
{
    protected $signature = 'cart:send-abandoned-reminders';

    protected $description = 'Queue eligible abandoned cart reminder emails';

    public function handle(): int
    {
        $queued = 0;
        $skipped = 0;

        $reminders = [
            '1hr' => [
                'column' => 'email_1hr_sent_at',
                'inactive_hours' => 1,
            ],
            '24hr' => [
                'column' => 'email_24hr_sent_at',
                'inactive_hours' => 24,
                'previous_column' => 'email_1hr_sent_at',
                'previous_sent_hours' => 23,
            ],
            '48hr' => [
                'column' => 'email_48hr_sent_at',
                'inactive_hours' => 48,
                'previous_column' => 'email_24hr_sent_at',
                'previous_sent_hours' => 24,
            ],
        ];

        foreach ($reminders as $emailType => $reminder) {
            $column = $reminder['column'];

            $query = Cart::query()
                ->whereNull('deleted_at')
                ->whereNull($column)
                ->whereNotNull('store_id')
                ->whereNotNull('user_id')
                ->where('updated_at', '<=', now()->subHours(
                    $reminder['inactive_hours']
                ))
                ->whereHas('user');

            /*
             * The next reminder must wait until the previous reminder
             * has been sent and the required interval has passed.
             */
            if (isset($reminder['previous_column'])) {
                $previousColumn = $reminder['previous_column'];

                $query
                    ->whereNotNull($previousColumn)
                    ->where(
                        $previousColumn,
                        '<=',
                        now()->subHours($reminder['previous_sent_hours'])
                    );
            }

            $query->orderBy('id')->chunkById(
                200,
                function ($carts) use (
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

                        try {
                            /*
                             * Reserve only carts that are still eligible.
                             * This prevents overlapping scheduler runs
                             * from queuing the same reminder twice.
                             */
                            $reservationQuery = DB::table('carts')
                                ->whereIn('id', $cartIds)
                                ->where('store_id', $storeId)
                                ->whereNull('deleted_at')
                                ->whereNull($column)
                                ->where(
                                    'updated_at',
                                    '<=',
                                    now()->subHours(
                                        $emailType === '1hr' ? 1 :
                                        ($emailType === '24hr' ? 24 : 48)
                                    )
                                );

                            if ($emailType === '24hr') {
                                $reservationQuery
                                    ->whereNotNull('email_1hr_sent_at')
                                    ->where(
                                        'email_1hr_sent_at',
                                        '<=',
                                        now()->subHours(23)
                                    );
                            } elseif ($emailType === '48hr') {
                                $reservationQuery
                                    ->whereNotNull('email_24hr_sent_at')
                                    ->where(
                                        'email_24hr_sent_at',
                                        '<=',
                                        now()->subHours(24)
                                    );
                            }

                            $reserved = $reservationQuery->update([
                                $column => now(),
                            ]);

                            if ($reserved === 0) {
                                continue;
                            }

                            $reservedCarts = Cart::query()
                                ->whereIn('id', $cartIds)
                                ->where('store_id', $storeId)
                                ->where($column, '>=', now()->subMinute())
                                ->get();

                            if ($reservedCarts->isEmpty()) {
                                continue;
                            }

                            SendAbandonedCartEmailJob::dispatch(
                                $reservedCarts,
                                $user->email,
                                $user->name ?? '',
                                $storeId,
                                $emailType,
                                $column
                            );

                            $queued++;

                            $this->line(
                                "Queued {$emailType} reminder for {$user->email} " .
                                "(store {$storeId}, {$reservedCarts->count()} carts)"
                            );
                        } catch (Throwable $e) {
                            /*
                             * Release this batch's recent reservations if
                             * dispatching fails.
                             */
                            DB::table('carts')
                                ->whereIn('id', $cartIds)
                                ->where('store_id', $storeId)
                                ->where($column, '>=', now()->subMinute())
                                ->update([$column => null]);

                            report($e);

                            $this->error(
                                "Failed to queue {$emailType} reminder: " .
                                $e->getMessage()
                            );

                            $skipped += $group->count();
                        }
                    }
                }
            );
        }

        $this->info("Reminder jobs queued: {$queued}");
        $this->info("Carts skipped: {$skipped}");

        return self::SUCCESS;
    }
}
