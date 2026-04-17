<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class FedexConfiguration extends Model
{
    use HasFactory;

    protected $fillable = [
        'shipping_method_id',
        'client_id',
        'client_secret',
        'account_number',
        'api_base_url',
        'ws_key',
        'ws_password',
        'meter_number',
        'display_name',
        'dropoff_type',
        'packaging_type',
        'packing_method',
        'rate_type',
        'include_package_value',
        'destination_type',
        'enabled_services',
        'is_connected',
    ];

    protected $casts = [
        'enabled_services'      => 'array',
        'include_package_value' => 'boolean',
        'is_connected'          => 'boolean',
    ];

    /**
     * Hidden from JSON responses — sensitive data
     */
    protected $hidden = [
        'client_secret',
        'ws_password',
    ];

    // ───────────────────────────────────────
    // Encrypt/Decrypt sensitive fields
    // ───────────────────────────────────────

    public function setClientSecretAttribute($value)
    {
        $this->attributes['client_secret'] = Crypt::encryptString($value);
    }

    public function getClientSecretAttribute($value)
    {
        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            return $value; // return as-is if not encrypted yet
        }
    }

    public function setWsPasswordAttribute($value)
    {
        if ($value) {
            $this->attributes['ws_password'] = Crypt::encryptString($value);
        }
    }

    public function getWsPasswordAttribute($value)
    {
        if (!$value) return null;
        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            return $value;
        }
    }

    // ───────────────────────────────────────
    // Relationships
    // ───────────────────────────────────────

    public function shippingMethod()
    {
        return $this->belongsTo(ShippingMethod::class);
    }

    public function quoteLogs()
    {
        return $this->hasMany(FedexQuoteLog::class, 'fedex_configuration_id');
    }

    // ───────────────────────────────────────
    // Helpers
    // ───────────────────────────────────────

    /**
     * Get all available FedEx service codes
     */
    public static function allServiceCodes(): array
    {
        return [
            'EUROPE_FIRST_INTERNATIONAL_PRIORITY' => 'Europe First International Priority',
            'FEDEX_1_DAY_FREIGHT'                 => 'FedEx 1 Day Freight',
            'FEDEX_2_DAY'                         => 'FedEx 2 Day',
            'FEDEX_2_DAY_FREIGHT'                 => 'FedEx 2 Day Freight',
            'FEDEX_3_DAY_FREIGHT'                 => 'FedEx 3 Day Freight',
            'FEDEX_EXPRESS_SAVER'                 => 'FedEx Express Saver',
            'FEDEX_GROUND'                        => 'FedEx Ground',
            'FIRST_OVERNIGHT'                     => 'First Overnight',
            'GROUND_HOME_DELIVERY'                => 'Ground Home Delivery',
            'INTERNATIONAL_ECONOMY'               => 'International Economy',
            'INTERNATIONAL_ECONOMY_FREIGHT'       => 'International Economy Freight',
            'INTERNATIONAL_FIRST'                 => 'International First',
            'INTERNATIONAL_PRIORITY'              => 'International Priority',
            'INTERNATIONAL_PRIORITY_FREIGHT'      => 'International Priority Freight',
            'PRIORITY_OVERNIGHT'                  => 'Priority Overnight',
            'STANDARD_OVERNIGHT'                  => 'Standard Overnight',
            'SMART_POST'                          => 'FedEx Ground Economy',
            'FEDEX_REGIONAL_ECONOMY'              => 'FedEx Regional Economy',
        ];
    }

    /**
     * Get only the enabled service labels for display
     */
    public function getEnabledServiceLabels(): array
    {
        $all      = self::allServiceCodes();
        $enabled  = $this->enabled_services ?? [];
        $labels   = [];

        foreach ($enabled as $code) {
            if (isset($all[$code])) {
                $labels[$code] = $all[$code];
            }
        }

        return $labels;
    }

    /**
     * Check if a specific service is enabled
     */
    public function isServiceEnabled(string $serviceCode): bool
    {
        return in_array($serviceCode, $this->enabled_services ?? []);
    }
}
