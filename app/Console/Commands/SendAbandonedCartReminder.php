<?php

namespace App\Console\Commands;

use App\Jobs\SendAbandonedCartEmailJob;
use App\Models\Cart;
use App\Models\User;
use App\Services\TenantCredentialResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendAbandonedCartReminder extends Command
{
    protected $signature = 'cart:send-abandoned-reminders';

    protected $description = 'Queue eligible abandoned cart reminder emails across all active tenants';

    public function handle(TenantCredentialResolver $tenantResolver): int
    {
        $queued = 0;
        $skipped = 0;
        $failedStores = 0;

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

        $stores = DB::connection('central')
            ->table('stores')
            ->where('provisioning_status', 'active')
            ->whereNotNull('store_hash')
            ->pluck('store_hash');

        $this->info("Found {$stores->count()} active stores.");

        foreach ($stores as $storeHash) {
            try {
                $store = $tenantResolver->connectByStoreHash($storeHash);

                // Tenant cart store_id may use the legacy store ID.
                $tenantStoreId = (int) (
                    $store->legacy_store_id ?? $store->id
                );

                $this->info("Processing store: {$storeHash}");

                foreach ($reminders as $emailType => $reminder) {
                    $column = $reminder['column'];

                    $query = Cart::query()
                        ->whereNull('deleted_at')
                        ->whereNull($column)
                        ->where('store_id', $tenantStoreId)
                        ->whereNotNull('user_id')
                        ->where(
                            'updated_at',
                            '<=',
                            now()->subHours($reminder['inactive_hours'])
                        );

                    if (isset($reminder['previous_column'])) {
                        $previousColumn = $reminder['previous_column'];

                        $query
                            ->whereNotNull($previousColumn)
                            ->where(
                                $previousColumn,
                                '<=',
                                now()->subHours(
                                    $reminder['previous_sent_hours']
                                )
                            );
                    }

                    $query->orderBy('id')->chunkById(
                        200,
                        function ($carts) use (
                            $emailType,
                            $column,
                            $storeHash,
                            $tenantStoreId,
                            &$queued,
                            &$skipped
                        ) {
                            // Customers/users are stored in this tenant DB.
                            $users = User::on('tenant')
                                ->whereIn(
                                    'id',
                                    $carts->pluck('user_id')->unique()
                                )
                                ->get()
                                ->keyBy('id');

                            $groups = $carts->groupBy('user_id');

                            foreach ($groups as $userId => $group) {
                                $user = $users->get($userId);

                                if (!$user || !$user->email) {
                                    $skipped += $group->count();
                                    continue;
                                }

                                $cartIds = $group->pluck('id')->all();

                                try {
                                    $reservationQuery = DB::connection('tenant')
                                        ->table('carts')
                                        ->whereIn('id', $cartIds)
                                        ->where('store_id', $tenantStoreId)
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

                                    $reservedCarts = DB::connection('tenant')
                                        ->table('carts')
                                        ->whereIn('id', $cartIds)
                                        ->where('store_id', $tenantStoreId)
                                        ->where($column, '>=', now()->subMinute())
                                        ->pluck('id')
                                        ->all();

                                    if (empty($reservedCarts)) {
                                        continue;
                                    }

                                    SendAbandonedCartEmailJob::dispatch(
                                        $storeHash,
                                        $tenantStoreId,
                                        $reservedCarts,
                                        $user->email,
                                        $user->name ?? '',
                                        $emailType,
                                        $column
                                    );

                                    $queued++;

                                    $this->line(
                                        "Queued {$emailType} reminder for {$user->email} " .
                                        "(store {$storeHash}, " .
                                        count($reservedCarts) . " carts)"
                                    );
                                } catch (Throwable $e) {
                                    DB::connection('tenant')
                                        ->table('carts')
                                        ->whereIn('id', $cartIds)
                                        ->where('store_id', $tenantStoreId)
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
            } catch (Throwable $e) {
                $failedStores++;

                report($e);

                $this->error(
                    "Store {$storeHash} failed: {$e->getMessage()}"
                );
            } finally {
                $tenantResolver->disconnect();
            }
        }

        $this->newLine();
        $this->info("Reminder jobs queued: {$queued}");
        $this->info("Carts skipped: {$skipped}");
        $this->info("Stores failed: {$failedStores}");

        return $failedStores > 0 ? self::FAILURE : self::SUCCESS;
    }
}