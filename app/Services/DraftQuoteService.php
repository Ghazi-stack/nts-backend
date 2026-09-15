<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Store;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class DraftQuoteService
{
    public function storeFrontBaseUrl(int $storeId): string
    {
        $store = Store::find($storeId);

        return rtrim($store?->base_url ?? 'https://staging.sparemicro.com', '/');
    }

    public function makeDraftQuoteUrl(Order $order, $user, int $storeId): string
    {
        $payload = [
            'iss'      => $this->storeFrontBaseUrl($storeId),
            'iat'      => time(),
            'exp'      => time() + (60 * 60 * 24 * 30),
            'jti'      => (string) Str::uuid(),
            'store_id' => $storeId,
            'order_id' => $order->id,
            'user_id'  => $user?->id,
            'email'    => $user?->email ?? $order->customer?->email,
            'domain'   => [
                'cart' => [
                    'id' => (string) Str::uuid(),
                ],
            ],
        ];

        $token = rtrim(strtr(base64_encode(Crypt::encryptString(json_encode($payload))), '+/', '-_'), '=');

        return $this->storeFrontBaseUrl($storeId)
            . '/cart?action=loadSavedQuote&quoteToken='
            . $token;
    }

    public function decode(string $token): array
    {
        $token = strtr($token, '-_', '+/');
        $pad   = strlen($token) % 4;
        if ($pad) {
            $token .= str_repeat('=', 4 - $pad);
        }

        $json    = Crypt::decryptString(base64_decode($token));
        $payload = json_decode($json, true);

        if (!is_array($payload) || ($payload['exp'] ?? 0) < time()) {
            throw new \RuntimeException('Quote link expired or invalid');
        }

        return $payload;
    }
}
