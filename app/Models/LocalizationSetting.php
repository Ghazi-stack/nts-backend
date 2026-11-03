<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class LocalizationSetting extends Model
{
    use HasFactory;

    protected $table = 'localization_settings';
    protected $connection = 'tenant';

    protected $fillable = [
        'store_id',
        'country',
        'physical_dimensions',
        'date_timezone',
    ];

    protected $casts = [
        'country' => 'array',
        'physical_dimensions' => 'array',
        'date_timezone' => 'array',
    ];

    /**
     * Default localization settings.
     */
    public static function defaultSettings(): array
    {
        return [
            'country' => [
                'default_country' => 'United States',
                'country_code' => 'US',
            ],

            'physical_dimensions' => [
                'weight_unit' => 'oz',
                'length_unit' => 'in',
                'thousands_token' => ',',
                'decimal_token' => '.',
                'decimal_places' => 2,
                'factoring_dimension' => 'product_depth',
            ],

            'date_timezone' => [
                'timezone' => 'America/New_York',
                'dst_enabled' => false,
                'date_format' => 'M jS Y',
                'extended_date_time_format' => 'M jS Y @ g:i A',
            ],
        ];
    }

    /**
     * Get or initialize localization settings
     * for the currently resolved tenant.
     */
    public static function getForCurrentStore(): self
    {
        $storeId = current_store_id();

        if (!$storeId) {
            throw new RuntimeException(
                'Unable to resolve the current store legacy ID.'
            );
        }

        $defaults = self::defaultSettings();

        return self::firstOrCreate(
            [
                'store_id' => (int) $storeId,
            ],
            [
                'country' => $defaults['country'],
                'physical_dimensions' => $defaults['physical_dimensions'],
                'date_timezone' => $defaults['date_timezone'],
            ]
        );
    }
}