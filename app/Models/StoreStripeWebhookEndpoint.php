<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreStripeWebhookEndpoint extends Model
{
    protected $connection = 'tenant';

    protected $table = 'store_stripe_webhook_endpoints';

    protected $fillable = [
        'store_id',
        'environment',
        'stripe_endpoint_id',
        'endpoint_url',
        'signing_secret',
        'enabled_events',
        'is_enabled',
        'is_verified',
        'last_event_at',
        'last_event_type',
        'last_event_status',
        'last_error',
    ];

    protected $hidden = [
        'signing_secret',
    ];

    protected $casts = [
        'signing_secret' => 'encrypted',
        'enabled_events' => 'array',
        'is_enabled' => 'boolean',
        'is_verified' => 'boolean',
        'last_event_at' => 'datetime',
    ];

    public function scopeForStore($query, int $storeId)
    {
        return $query->where('store_id', $storeId);
    }

    public function scopeForEnvironment($query, string $environment)
    {
        return $query->where('environment', $environment);
    }
}
