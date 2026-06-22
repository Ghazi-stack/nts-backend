<?php

namespace App\Console\Commands;

use App\Mail\AbandonedCartEmail;
use App\Models\Cart;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendAbandonedCartEmails extends Command
{
    protected $signature   = 'cart:send-abandoned-emails';
    protected $description = 'Abandoned cart users ko email bhejo (1hr, 24hr, 48hr)';

    public function handle(): void
    {
        $this->info('Abandoned cart emails check ho rahi hain...');

        $this->sendFirstEmail();
        $this->sendSecondEmail();
        $this->sendThirdEmail();

        $this->info('Done!');
    }

    // ─────────────────────────────────────────────────────────────
    // 1 Hour Email
    // ─────────────────────────────────────────────────────────────
    private function sendFirstEmail(): void
    {
        $carts = Cart::with(['user', 'product.images'])
            ->whereNotNull('user_id')
            ->whereNull('email_1hr_sent_at')
            ->where('updated_at', '<=', now()->subHour())
            ->where('updated_at', '>=', now()->subHours(23))
            // ✅ Sirf woh users jinhon ne email receive karna allow kiya hai
            ->whereHas('user.customer', fn($q) => $q->where('receive_review_emails', 1))
            ->get()
            ->groupBy('user_id');

        foreach ($carts as $userId => $userCarts) {
            $user = $userCarts->first()->user;
            if (!$user || !$user->email) continue;

            $cartItems    = $this->formatCartItems($userCarts);
            $checkoutLink = config('app.frontend_url') . '/checkout';

            try {
                Mail::to($user->email)->send(new AbandonedCartEmail(
                    user: $user,
                    cartItems: $cartItems,
                    emailType: '1hr',
                    checkoutLink: $checkoutLink
                ));

                Cart::where('user_id', $userId)
                    ->whereNull('email_1hr_sent_at')
                    ->update(['email_1hr_sent_at' => now()]);

                $this->info("1hr email sent → {$user->email}");
            } catch (\Exception $e) {
                $this->error("1hr email failed → {$user->email}: " . $e->getMessage());
            }
        }
    }

    // ─────────────────────────────────────────────────────────────
    // 24 Hour Email
    // ─────────────────────────────────────────────────────────────
    private function sendSecondEmail(): void
    {
        $carts = Cart::with(['user', 'product.images'])
            ->whereNotNull('user_id')
            ->whereNotNull('email_1hr_sent_at')
            ->whereNull('email_24hr_sent_at')
            ->where('updated_at', '<=', now()->subHours(24))
            ->where('updated_at', '>=', now()->subHours(47))
            // ✅ Sirf woh users jinhon ne email receive karna allow kiya hai
            ->whereHas('user.customer', fn($q) => $q->where('receive_review_emails', 1))
            ->get()
            ->groupBy('user_id');

        foreach ($carts as $userId => $userCarts) {
            $user = $userCarts->first()->user;
            if (!$user || !$user->email) continue;

            $cartItems    = $this->formatCartItems($userCarts);
            $checkoutLink = config('app.frontend_url') . '/checkout';

            try {
                Mail::to($user->email)->send(new AbandonedCartEmail(
                    user: $user,
                    cartItems: $cartItems,
                    emailType: '24hr',
                    checkoutLink: $checkoutLink
                ));

                Cart::where('user_id', $userId)
                    ->whereNull('email_24hr_sent_at')
                    ->update(['email_24hr_sent_at' => now()]);

                $this->info("24hr email sent → {$user->email}");
            } catch (\Exception $e) {
                $this->error("24hr email failed → {$user->email}: " . $e->getMessage());
            }
        }
    }

    // ─────────────────────────────────────────────────────────────
    // 48 Hour Email (Last)
    // ─────────────────────────────────────────────────────────────
    private function sendThirdEmail(): void
    {
        $carts = Cart::with(['user', 'product.images'])
            ->whereNotNull('user_id')
            ->whereNotNull('email_24hr_sent_at')
            ->whereNull('email_48hr_sent_at')
            ->where('updated_at', '<=', now()->subHours(48))
            // ✅ Sirf woh users jinhon ne email receive karna allow kiya hai
            ->whereHas('user.customer', fn($q) => $q->where('receive_review_emails', 1))
            ->get()
            ->groupBy('user_id');

        foreach ($carts as $userId => $userCarts) {
            $user = $userCarts->first()->user;
            if (!$user || !$user->email) continue;

            $cartItems    = $this->formatCartItems($userCarts);
            $checkoutLink = config('app.frontend_url') . '/checkout';

            try {
                Mail::to($user->email)->send(new AbandonedCartEmail(
                    user: $user,
                    cartItems: $cartItems,
                    emailType: '48hr',
                    checkoutLink: $checkoutLink
                ));

                Cart::where('user_id', $userId)
                    ->whereNull('email_48hr_sent_at')
                    ->update(['email_48hr_sent_at' => now()]);

                $this->info("48hr email sent → {$user->email}");
            } catch (\Exception $e) {
                $this->error("48hr email failed → {$user->email}: " . $e->getMessage());
            }
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Cart items format karna
    // ─────────────────────────────────────────────────────────────
    private function formatCartItems($carts): array
    {
        return $carts->map(function ($cart) {
            $product   = $cart->product;
            $thumbnail = $product?->images?->where('is_primary', 1)->first()?->path
                ?? $product?->images?->first()?->path;

            return [
                'name'      => $product?->name ?? 'Product',
                'sku'       => $product?->sku ?? '',
                'quantity'  => $cart->quantity,
                'thumbnail' => $thumbnail ? url($thumbnail) : null,
            ];
        })->toArray();
    }
}
