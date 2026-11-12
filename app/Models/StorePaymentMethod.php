<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class StorePaymentMethod extends Model
{
    protected $connection = 'tenant';

    protected $table = 'store_payment_methods';

    protected $fillable = [
        'store_id',
        'method_code',
        'category',
        'display_name',
        'is_enabled',
        'is_configured',
        'available_countries',
        'configuration',
        'credentials',
    ];

    /**
     * Never expose sensitive gateway credentials
     * through normal model serialization.
     */
    protected $hidden = [
        'credentials',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'is_configured' => 'boolean',
        'available_countries' => 'array',
        'configuration' => 'array',
        'credentials' => 'encrypted:array',
    ];

    /**
     * Scope records to a particular store.
     */
    public function scopeForStore(
        Builder $query,
        int $storeId
    ): Builder {
        return $query->where('store_id', $storeId);
    }

    /**
     * Retrieve enabled payment methods.
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Retrieve configured payment methods.
     */
    public function scopeConfigured(Builder $query): Builder
    {
        return $query->where('is_configured', true);
    }
}
