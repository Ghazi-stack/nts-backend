<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class StorePaymentMethodProvider extends Model
{
    protected $connection = 'tenant';

    protected $table = 'store_payment_method_providers';

    protected $fillable = [
        'store_id',
        'method_code',
        'provider_code',
        'is_enabled',
        'is_available',
        'configuration',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'is_available' => 'boolean',
        'configuration' => 'array',
    ];

    public function scopeForStore(
        Builder $query,
        int $storeId
    ): Builder {
        return $query->where('store_id', $storeId);
    }

    public function scopeForMethod(
        Builder $query,
        string $methodCode
    ): Builder {
        return $query->where('method_code', $methodCode);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query
            ->where('is_enabled', true)
            ->where('is_available', true);
    }
}
