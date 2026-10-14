<?php
namespace App\Jobs;

use App\Mail\AbandonedCartMail;
use App\Models\Cart;
use App\Models\Store;
use App\Models\User;
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
    ) {
        // Only allow the known reminder timestamp columns.
        if (!in_array($this->timestampColumn, [
            'email_1hr_sent_at',
            'email_24hr_sent_at',
            'email_48hr_sent_at',
        ], true)) {
            throw new \InvalidArgumentException(
                'Invalid abandoned-cart reminder timestamp column.'
            );
        }
    }

    public function handle(): void
    {
        $cartIds = $this->cartItems->pluck('id')->all();

        try {
            $store = Store::findOrFail($this->storeId);

            // Find the user using the cart's user ID when possible.
            $userId = $this->cartItems->first()?->user_id;

            $userQuery = User::query()
                ->where('email', $this->userEmail);

            if ($userId !== null) {
                $userQuery->where('id', $userId);
            }

            $user = $userQuery->firstOrFail();

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

            $cartModels = Cart::with(['product.images'])
                ->whereIn('id', $cartIds)
                ->where('store_id', $this->storeId)
                ->whereNull('deleted_at')
                ->get();

            $formattedCartItems = $cartModels->map(function ($cart) {
                $product = $cart->product;

            \Log::info('Abandoned cart email product details', [
                'cart_ids' => $cartIds,
                'products' => $formattedCartItems,
            ]);    

                // Get the first product image, if available.
                $thumbnail = $product?->images
                    ->first()
                    ?->url;

                // Replace image_url with the actual image field
                // used by your ProductImage model if different.
                if ($thumbnail && !preg_match('#^https?://#i', $thumbnail)) {
                    $thumbnail = url(ltrim($thumbnail, '/'));
                }

                return [
                    'name'      => $product?->name ?? 'Product',
                    'sku'       => $product?->sku ?? '',
                    'quantity'  => $cart->quantity,
                    'thumbnail' => $thumbnail,
                ];
            })->values()->all();

            Mail::to($this->userEmail)->send(
                new AbandonedCartMail(
                    $formattedCartItems,
                    $user,
                    $this->storeId,
                    $this->emailType,
                    $checkoutLink
                )
            );

            // Keep the reminder timestamp after the send call succeeds.
            Cart::whereIn('id', $cartIds)
                ->where('store_id', $this->storeId)
                ->update([
                    $this->timestampColumn => now(),
                ]);
        } catch (Throwable $e) {
            // Release this reminder's reservation so it can be retried.
            Cart::whereIn('id', $cartIds)
                ->where('store_id', $this->storeId)
                ->where($this->timestampColumn, '>=', now()->subHours(24))
                ->update([
                    $this->timestampColumn => null,
                ]);

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        Cart::whereIn('id', $this->cartItems->pluck('id')->all())
            ->where('store_id', $this->storeId)
            ->where($this->timestampColumn, '>=', now()->subHours(24))
            ->update([
                $this->timestampColumn => null,
            ]);
    }
}