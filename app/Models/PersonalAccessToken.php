<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    public static function findToken($token)
    {
        $id = null;
        $plainTextToken = $token;

        if (str_contains($token, '|')) {
            [$id, $plainTextToken] = explode('|', $token, 2);
        }

        $hash = hash('sha256', $plainTextToken);

        // Search central first so staff tokens remain valid
        // even when the request includes tenant context.
        $centralToken = static::queryOnConnection('central', $id, $hash);

        if ($centralToken) {
            return $centralToken;
        }

        // Search tenant only after a store has been resolved.
        if (request()->attributes->get('tenant_store')) {
            return static::queryOnConnection('tenant', $id, $hash);
        }

        return null;
    }

    protected static function queryOnConnection(
        string $connection,
        ?string $id,
        string $hash
    ): ?self {
        $model = new static();
        $model->setConnection($connection);

        $query = $model->newQuery();

        if ($id !== null) {
            $token = $query->whereKey($id)->first();
        } else {
            $token = $query->where('token', $hash)->first();
        }

        if (!$token || !hash_equals($token->token, $hash)) {
            return null;
        }

        return $token;
    }
}