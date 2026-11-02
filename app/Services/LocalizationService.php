<?php

namespace App\Services;

use App\Models\LocalizationSetting;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LocalizationService
{
    /**
     * Supported weight measurements.
     */
    public const WEIGHT_UNITS = [
        'oz',
        'lbs',
        'g',
        'kg',
        'tonnes',
    ];

    /**
     * Supported length measurements.
     */
    public const LENGTH_UNITS = [
        'in',
        'cm',
    ];

    /**
     * Supported factoring dimensions.
     */
    public const FACTORING_DIMENSIONS = [
        'product_depth',
        'product_width',
        'product_height',
    ];

    /**
     * Get current store localization settings.
     */
    public function getSettings(): LocalizationSetting
    {
        return LocalizationSetting::getForCurrentStore();
    }

    /**
     * Update country settings.
     */
    public function updateCountry(array $data): LocalizationSetting
    {
        $this->validateCountry($data);

        $settings = $this->getSettings();

        $settings->country = array_merge(
            $settings->country ?? [],
            [
                'default_country' => $data['default_country'],
                'country_code' => strtoupper($data['country_code']),
            ]
        );

        $settings->save();

        return $settings->fresh();
    }

    /**
     * Update physical dimension settings.
     */
    public function updatePhysicalDimensions(array $data): LocalizationSetting
    {
        $this->validatePhysicalDimensions($data);

        $settings = $this->getSettings();

        $settings->physical_dimensions = array_merge(
            $settings->physical_dimensions ?? [],
            $data
        );

        $settings->save();

        return $settings->fresh();
    }

    /**
     * Update date and timezone settings.
     */
    public function updateDateTimezone(array $data): LocalizationSetting
    {
        $this->validateDateTimezone($data);

        $settings = $this->getSettings();

        $settings->date_timezone = array_merge(
            $settings->date_timezone ?? [],
            $data
        );

        $settings->save();

        return $settings->fresh();
    }

    /**
     * Apply standard physical and date formats
     * based on the currently selected country.
     */
    public function resetToCountryDefaults(): LocalizationSetting
    {
        $settings = $this->getSettings();

        $countryCode = strtoupper(
            $settings->country['country_code'] ?? 'US'
        );

        $presets = $this->getCountryPresets($countryCode);

        DB::transaction(function () use ($settings, $presets) {

            $settings->physical_dimensions = array_merge(
                $settings->physical_dimensions ?? [],
                $presets['physical_dimensions']
            );

            $settings->date_timezone = array_merge(
                $settings->date_timezone ?? [],
                $presets['date_timezone']
            );

            $settings->save();
        });

        return $settings->fresh();
    }

    
    /**
     * Get standard formatting presets for a country.
     */
    protected function getCountryPresets(string $countryCode): array
    {
        $countryCode = strtoupper(trim($countryCode));

        $defaults = config("country_defaults.{$countryCode}");

        if (
            empty($defaults) ||
            empty($defaults['physical_dimensions']) ||
            empty($defaults['date_timezone'])
        ) {
            throw new InvalidArgumentException(
                'No defaults found for this country.'
            );
        }

        return [
            'physical_dimensions' => $defaults['physical_dimensions'],
            'date_timezone' => $defaults['date_timezone'],
        ];
    }


    /**
     * Validate country data.
     */
    protected function validateCountry(array $data): void
    {
        if (
            empty($data['default_country']) ||
            !is_string($data['default_country']) ||
            strlen($data['default_country']) > 100
        ) {
            throw new InvalidArgumentException('Invalid default country.');
        }

        if (
            empty($data['country_code']) ||
            !preg_match('/^[A-Za-z]{2}$/', $data['country_code'])
        ) {
            throw new InvalidArgumentException('Invalid country code.');
        }
    }

    /**
     * Validate physical dimensions data.
     */
    protected function validatePhysicalDimensions(array $data): void
    {
        $required = [
            'weight_unit',
            'length_unit',
            'thousands_token',
            'decimal_token',
            'decimal_places',
            'factoring_dimension',
        ];

        foreach ($required as $field) {
            if (!array_key_exists($field, $data)) {
                throw new InvalidArgumentException(
                    "Missing physical dimension field: {$field}"
                );
            }
        }

        if (!in_array($data['weight_unit'], self::WEIGHT_UNITS, true)) {
            throw new InvalidArgumentException('Invalid weight measurement.');
        }

        if (!in_array($data['length_unit'], self::LENGTH_UNITS, true)) {
            throw new InvalidArgumentException('Invalid length measurement.');
        }

        if (
            !in_array(
                $data['factoring_dimension'],
                self::FACTORING_DIMENSIONS,
                true
            )
        ) {
            throw new InvalidArgumentException('Invalid factoring dimension.');
        }

        if (
            !is_string($data['thousands_token']) ||
            !is_string($data['decimal_token']) ||
            strlen($data['thousands_token']) !== 1 ||
            strlen($data['decimal_token']) !== 1 ||
            $data['thousands_token'] === $data['decimal_token']
        ) {
            throw new InvalidArgumentException('Invalid number separators.');
        }

        if (
            !is_numeric($data['decimal_places']) ||
            !in_array((int) $data['decimal_places'], [0, 1, 2, 3, 4], true)
        ) {
            throw new InvalidArgumentException('Invalid decimal places.');
        }

        $data['decimal_places'] = (int) $data['decimal_places'];
    }

    /**
     * Validate date and timezone data.
     */
    protected function validateDateTimezone(array $data): void
    {
        $allowedFormats = [
            'M jS Y',
            'M j Y',
            'F j, Y',
            'm/d/Y',
            'd/m/Y',
            'Y-m-d',
        ];

        $allowedExtendedFormats = [
            'M jS Y @ g:i A',
            'M j Y @ g:i A',
            'F j, Y @ g:i A',
            'm/d/Y g:i A',
            'd/m/Y H:i',
            'Y-m-d H:i',
        ];

        if (
            !isset($data['timezone']) ||
            !in_array(
                $data['timezone'],
                DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),
                true
            )
        ) {
            if (($data['timezone'] ?? null) !== 'UTC') {
                throw new InvalidArgumentException('Invalid timezone.');
            }
        }

        if (
            !isset($data['dst_enabled']) ||
            !is_bool($data['dst_enabled'])
        ) {
            throw new InvalidArgumentException(
                'Invalid daylight savings setting.'
            );
        }

        if (
            !in_array($data['date_format'] ?? null, $allowedFormats, true)
        ) {
            throw new InvalidArgumentException('Invalid date format.');
        }

        if (
            !in_array(
                $data['extended_date_time_format'] ?? null,
                $allowedExtendedFormats,
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid extended date and time format.'
            );
        }
    }

    
    
    /**
     * Get standard physical dimensions for a country.
     */
    public function getStandardPhysicalDimensions(string $countryCode): array
    {
        $countryCode = strtoupper(trim($countryCode));

        $defaults = config("country_defaults.{$countryCode}");

        if (empty($defaults['physical_dimensions'])) {
            throw new InvalidArgumentException(
                'No defaults found for this country.'
            );
        }

        return $defaults['physical_dimensions'];
    }

    /**
     * Get standard date and timezone settings for a country.
     */
    public function getStandardDateTimezone(string $countryCode): array
    {
        $countryCode = strtoupper(trim($countryCode));

        $defaults = config("country_defaults.{$countryCode}");

        if (empty($defaults['date_timezone'])) {
            throw new InvalidArgumentException(
                'No defaults found for this country.'
            );
        }

        return $defaults['date_timezone'];
    }
    
    /**
     * Update all localization sections together.
     */
    public function updateAllSettings(array $data): LocalizationSetting
    {
        $settings = $this->getSettings();

        $settings->country = $data['country'];
        $settings->physical_dimensions = $data['physical_dimensions'];
        $settings->date_timezone = $data['date_timezone'];

        $settings->save();

        return $settings->fresh();
    }

}
