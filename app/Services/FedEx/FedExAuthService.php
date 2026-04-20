<?php

namespace App\Services\FedEx;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\FedexConfiguration;

class FedExAuthService
{
    /**
     * Get OAuth2 access token (cached for ~55 minutes)
     *
     * @param FedexConfiguration $config
     * @return string|null
     */
    public function getAccessToken(FedexConfiguration $config): ?string
    {
        $cacheKey = 'fedex_token_' . $config->id;

        // Return cached token if available
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            $response = Http::asForm()->post($config->api_base_url . '/oauth/token', [
                'grant_type'    => 'client_credentials',
                'client_id'     => $config->client_id,
                'client_secret' => $config->client_secret,
            ]);

            if ($response->successful()) {
                $data        = $response->json();
                $token       = $data['access_token'];
                $expiresIn   = $data['expires_in'] ?? 3600; // default 1 hour

                // Cache token for slightly less than expiry (55 minutes)
                Cache::put($cacheKey, $token, now()->addSeconds($expiresIn - 300));

                Log::info('FedEx OAuth token obtained successfully', [
                    'config_id' => $config->id,
                    'expires_in' => $expiresIn,
                ]);

                return $token;
            }

            Log::error('FedEx OAuth token request failed', [
                'config_id' => $config->id,
                'status'    => $response->status(),
                'body'      => $response->body(),
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('FedEx OAuth token exception', [
                'config_id' => $config->id,
                'error'     => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Clear cached token (useful when credentials change)
     *
     * @param FedexConfiguration $config
     * @return void
     */
    public function clearToken(FedexConfiguration $config): void
    {
        Cache::forget('fedex_token_' . $config->id);
    }
}
