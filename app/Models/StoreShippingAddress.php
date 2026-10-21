<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreShippingAddress extends Model
{
    use HasFactory;
    protected $connection = 'tenant';
    protected $table = 'store_shipping_address';
    

    protected $fillable = [
        'address_line_1',
        'address_line_2',
        'city',
        'postal_code',
        'country',
        'country_code',
        'state',
        'state_code',
        'phone_number',
        'is_active',
        'store_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the active pickup address (sirf ek hoga)
     */
    public static function getActive(?int $storeId = null): ?self
    {
        return static::query()
            ->when($storeId, fn($q) => $q->where('store_id', $storeId))
            ->where('is_active', true)
            ->first();
    }

    /**
     * Get origin data array for FedEx API
     * Ye directly ShippingRateService mein use hoga
     */
    public function toOriginArray(): array
    {
        return [
            'postal_code'  => $this->postal_code,
            'country_code' => $this->country_code,
            'state'        => $this->state_code,
            'city'         => $this->city,
        ];
    }
}
