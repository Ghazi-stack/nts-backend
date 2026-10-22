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

        $resolvedStore = $request->attributes->get('resolved_store');
        $tenantStore = $request->attributes->get('tenant_store');

        $hasTenantContext = $resolvedStore !== null || $tenantStore !== null;
        $isTenantRoute = str_starts_with($request->path(), 'api/web/');

        if ($isTenantRoute && !$hasTenantContext) {
            Log::warning('Sanctum rejected tenant request: context missing', [
                'path' => $request->path(),
                'resolved_store_present' => $resolvedStore !== null,
                'tenant_store_present' => $tenantStore !== null,
            ]);

            return null;
        }

        $connection = $hasTenantContext ? 'tenant' : 'central';

        Log::info('Sanctum selecting token database', [
            'path' => $request->path(),
            'connection' => $connection,
            'tenant_context_available' => $hasTenantContext,
            'token_uses_id_prefix' => $id !== null,
        ]);

        return static::queryOnConnection($connection, $id, $hash);
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
