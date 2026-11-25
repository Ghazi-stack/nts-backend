<?php

namespace App\Jobs;

use App\Mail\AbandonedCartMail;
use App\Models\Cart;
use App\Models\Store;
use App\Models\User;
use App\Services\TenantCredentialResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendAbandonedCartEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public $tries = 3;

    public function __construct(
        public string $storeHash,
        public int $storeId,
        public array $cartIds,
        public string $userEmail,
        public string $userName,
        public string $emailType,
        public string $timestampColumn
    ) {
        if (!in_array($this->timestampColumn, [
            'email_1hr_sent_at',
            'email_24hr_sent_at',
            'email_48hr_sent_at',
        ], true)) {
            throw new \InvalidArgumentException(
                'Invalid abandoned-cart reminder timestamp column.'
            );
        }

        if (!in_array($this->emailType, ['1hr', '24hr', '48hr'], true)) {
            throw new \InvalidArgumentException(
                'Invalid abandoned-cart reminder type.'
            );
        }
    }

    public function handle(
        TenantCredentialResolver $tenantResolver
    ): void {
        $connected = false;

        try {
            // Every queue job establishes its own tenant connection.
            $storeRecord = $tenantResolver->connectByStoreHash(
                $this->storeHash
            );

            $connected = true;

            // Retrieve store configuration from the central database.
            $store = Store::on('central')
                ->where('store_hash', $this->storeHash)
                ->firstOrFail();

            $expectedTenantStoreId = (int) (
                $storeRecord->legacy_store_id ?? $storeRecord->id
            );

            if ($expectedTenantStoreId !== $this->storeId) {
                throw new \RuntimeException(
                    'Tenant store ID does not match the queued job.'
                );
            }

            // Retrieve the customer from the tenant database.
            $cartModels = Cart::with(['product.images'])
                ->whereIn('id', $this->cartIds)
                ->where('store_id', $this->storeId)
                ->whereNull('deleted_at')
                ->get();

            if ($cartModels->isEmpty()) {
                Log::info('Abandoned cart email skipped: no carts found.', [
                    'store_hash' => $this->storeHash,
                    'cart_ids' => $this->cartIds,
                ]);

                return;
            }

            $userId = $cartModels->first()->user_id;

            $user = User::on('tenant')
                ->where('id', $userId)
                ->where('email', $this->userEmail)
                ->first();

            if (!$user) {
                throw new \RuntimeException(
                    'Customer could not be found in the tenant database.'
                );
            }

            $storePath = rtrim(
                $store->base_url ?: config('app.frontend_url', ''),
                '/'
            );

            if ($storePath === '') {
                throw new \RuntimeException(
                    'No frontend URL is configured for this store.'
                );
            }

            $checkoutLink = $storePath . '/checkout';

            $formattedCartItems = $cartModels->map(function ($cart) {
                $product = $cart->product;

                $thumbnailPath = $product?->images?->first()?->url;

                $awsUrl = rtrim(
                    config('filesystems.disks.s3.url', ''),
                    '/'
                );

                $thumbnail = null;

                if ($thumbnailPath && $awsUrl !== '') {
                    $thumbnail = $awsUrl . '/' . ltrim(
                        $thumbnailPath,
                        '/'
                    );
                }

                return [
                    'name' => $product?->name ?? 'Product',
                    'sku' => $product?->sku ?? '',
                    'quantity' => $cart->quantity,
                    'thumbnail' => $thumbnail,
                ];
            })->values()->all();

            Log::info('Abandoned cart email product details', [
                'store_hash' => $this->storeHash,
                'cart_ids' => $this->cartIds,
                'products' => $formattedCartItems,
            ]);

            Mail::to($this->userEmail)->send(
                new AbandonedCartMail(
                    $formattedCartItems,
                    $user,
                    $this->storeId,
                    $this->emailType,
                    $checkoutLink
                )
            );

            // Mark the reminder timestamp after successful email sending.
            Cart::whereIn('id', $this->cartIds)
                ->where('store_id', $this->storeId)
                ->whereNull('deleted_at')
                ->update([
                    $this->timestampColumn => now(),
                ]);
        } catch (Throwable $e) {
            // Release reservations only while the tenant connection is active.
            if ($connected) {
                try {
                    DB::connection('tenant')
                        ->table('carts')
                        ->whereIn('id', $this->cartIds)
                        ->where('store_id', $this->storeId)
                        ->where(
                            $this->timestampColumn,
                            '>=',
                            now()->subHours(24)
                        )
                        ->update([
                            $this->timestampColumn => null,
                        ]);
                } catch (Throwable $releaseException) {
                    report($releaseException);
                }
            }

            throw $e;
        } finally {
            $tenantResolver->disconnect();
        }
    }

    public function failed(?Throwable $exception): void
    {
        try {
            app(TenantCredentialResolver::class)
                ->connectByStoreHash($this->storeHash);

            DB::connection('tenant')
                ->table('carts')
                ->whereIn('id', $this->cartIds)
                ->where('store_id', $this->storeId)
                ->where(
                    $this->timestampColumn,
                    '>=',
                    now()->subHours(24)
                )
                ->update([
                    $this->timestampColumn => null,
                ]);
        } catch (Throwable $e) {
            report($e);
        } finally {
            app(TenantCredentialResolver::class)->disconnect();
        }

        if ($exception) {
            report($exception);
        }
    }
}