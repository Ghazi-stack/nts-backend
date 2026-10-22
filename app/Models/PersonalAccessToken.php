<?php

namespace App\Models;

use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    public static function findToken($token)
    {
        Log::info('Sanctum findToken called', [
            'path' => request()->path(),
            'token_present' => is_string($token) && $token !== '',
            'resolved_store_present' =>
                request()->attributes->get('resolved_store') !== null,
            'tenant_store_present' =>
                request()->attributes->get('tenant_store') !== null,
            'bearer_token_present' =>
                request()->bearerToken() !== null,
        ]);

        if (!is_string($token) || $token === '') {
            Log::warning('Sanctum token rejected', [
                'reason' => 'empty_or_invalid_token',
                'path' => request()->path(),
            ]);

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
                Log::warning('Sanctum token rejected', [
                    'reason' => 'invalid_token_format',
                    'path' => request()->path(),
                ]);

                return null;
            }
        }

        $hash = hash('sha256', $plainTextToken);

        /*
        |--------------------------------------------------------------------------
        | Determine whether tenant context is available
        |--------------------------------------------------------------------------
        */

        $hasTenantContext =
            request()->attributes->get('resolved_store') !== null
            || request()->attributes->get('tenant_store') !== null;

        Log::info('Sanctum token context', [
            'path' => request()->path(),
            'tenant_context_available' => $hasTenantContext,
            'token_uses_id_prefix' => $id !== null,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Prefer tenant token when tenant context is available
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
                    'path' => request()->path(),
                    'token_id' => $tenantToken->getKey(),
                    'tokenable_type' => $tenantToken->tokenable_type,
                    'tokenable_id' => $tenantToken->tokenable_id,
                ]);

                return $tenantToken;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Fall back to central tokens for central authentication
        |--------------------------------------------------------------------------
        */

        $centralToken = static::queryOnConnection(
            'central',
            $id,
            $hash
        );

        if ($centralToken) {
            Log::info('Sanctum central token authenticated', [
                'path' => request()->path(),
                'token_id' => $centralToken->getKey(),
                'tokenable_type' => $centralToken->tokenable_type,
                'tokenable_id' => $centralToken->tokenable_id,
            ]);

            return $centralToken;
        }

        Log::warning('Sanctum token not found', [
            'path' => request()->path(),
            'tenant_context_available' => $hasTenantContext,
        ]);

        return null;
    }

    protected static function queryOnConnection(
        string $connection,
        ?string $id,
        string $hash
    ): ?self {
        try {
            $model = new static();
            $model->setConnection($connection);

            $database = $model->getConnection()->getDatabaseName();

            $query = $model->newQuery();

            if ($id !== null) {
                $token = $query
                    ->whereKey((int) $id)
                    ->first();
            } else {
                $token = $query
                    ->where('token', $hash)
                    ->first();
            }

            $matches = $token !== null
                && is_string($token->token)
                && hash_equals($token->token, $hash);

            Log::info('Sanctum token lookup', [
                'path' => request()->path(),
                'connection' => $connection,
                'database' => $database,
                'lookup_by_id' => $id !== null,
                'token_record_found' => $token !== null,
                'token_hash_matches' => $matches,
            ]);

            if (!$matches) {
                return null;
            }

            return $token;
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
