<?php

namespace App\Models;

use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    public static function findToken($token)
    {
        $request = request();

        if (!is_string($token) || $token === '') {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Parse Sanctum token
        |--------------------------------------------------------------------------
        */

        $id = null;
        $plainTextToken = $token;

        if (str_contains($token, '|')) {
            [$id, $plainTextToken] = explode('|', $token, 2);

            if (
                $id === '' ||
                !ctype_digit($id) ||
                $plainTextToken === ''
            ) {
                return null;
            }
        }

        $hash = hash('sha256', $plainTextToken);

        /*
        |--------------------------------------------------------------------------
        | Determine tenant context
        |--------------------------------------------------------------------------
        */

        $hasTenantContext =
            $request->attributes->get('resolved_store') !== null
            || $request->attributes->get('tenant_store') !== null;

        Log::info('Sanctum token context', [
            'path' => $request->path(),
            'tenant_context_available' => $hasTenantContext,
            'token_uses_id_prefix' => $id !== null,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Tenant token lookup
        |--------------------------------------------------------------------------
        */

        if ($hasTenantContext) {
            $tenantToken = static::queryOnConnection(
                'tenant',
                $id,
                $hash
            );

            if ($tenantToken) {
                Log::info('Sanctum tenant token authenticated', [
                    'path' => $request->path(),
                    'token_id' => $tenantToken->getKey(),
                    'tokenable_type' => $tenantToken->tokenable_type,
                    'tokenable_id' => $tenantToken->tokenable_id,
                ]);

                return $tenantToken;
            }

            /*
             * Do not fall back to central when a tenant has been resolved.
             * A failed tenant-token lookup must not authenticate a different
             * tenant or central identity.
             */
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Central token lookup
        |--------------------------------------------------------------------------
        |
        | Preserves existing central authentication behavior when no tenant
        | context exists. Tenant-only routes must reject missing store context
        | before reaching Sanctum.
        |--------------------------------------------------------------------------
        */

        return static::queryOnConnection(
            'central',
            $id,
            $hash
        );
    }

    protected static function queryOnConnection(
        string $connection,
        ?string $id,
        string $hash
    ): ?self {
        try {
            $model = new static();
            $model->setConnection($connection);

            $query = $model->newQuery();

            if ($id !== null) {
                $token = $query->whereKey((int) $id)->first();
            } else {
                $token = $query->where('token', $hash)->first();
            }

            $matches = $token !== null
                && is_string($token->token)
                && hash_equals($token->token, $hash);

            Log::info('Sanctum token lookup', [
                'path' => request()->path(),
                'connection' => $connection,
                'database' => $model->getConnection()->getDatabaseName(),
                'lookup_by_id' => $id !== null,
                'token_record_found' => $token !== null,
                'token_hash_matches' => $matches,
            ]);

            return $matches ? $token : null;
        } catch (\Throwable $e) {
            Log::error('Sanctum token lookup failed', [
                'path' => request()->path(),
                'connection' => $connection,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
