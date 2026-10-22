<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    public static function findToken($token)
    {
        if (!is_string($token) || $token === '') {
            return null;
        }

        $id = null;
        $plainTextToken = $token;

        if (str_contains($token, '|')) {
            [$id, $plainTextToken] = explode('|', $token, 2);

            if ($id === '' || !ctype_digit($id)) {
                return null;
            }
        }

        $hash = hash('sha256', $plainTextToken);

        // Prefer tenant tokens when a store has been resolved.
        $hasTenantContext =
            request()->attributes->get('resolved_store') !== null
            || request()->attributes->get('tenant_store') !== null;

        if ($hasTenantContext) {
            $tenantToken = static::queryOnConnection(
                'tenant',
                $id,
                $hash
            );

            if ($tenantToken) {
                return $tenantToken;
            }
        }

        // Preserve central tokens for staff and other central authentication.
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

            if (!$token || !hash_equals($token->token, $hash)) {
                return null;
            }

            return $token;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                'Sanctum token lookup failed.',
                [
                    'connection' => $connection,
                    'message' => $e->getMessage(),
                ]
            );

            return null;
        }
    }
}