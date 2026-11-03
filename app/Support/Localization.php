<?php

namespace App\Support;

use App\Models\LocalizationSetting;
use Carbon\Carbon;
use InvalidArgumentException;

class Localization
{
    /**
     * Get current tenant localization settings.
     */
    public function settings(): array
    {
        $settings = LocalizationSetting::getForCurrentStore();

        return [
            'country' => $settings->country,
            'physical_dimensions' => $settings->physical_dimensions,
            'date_timezone' => $settings->date_timezone,
        ];
    }

    /**
     * Get tenant timezone.
     */
    public function timezone(): string
    {
        return $this->settings()['date_timezone']['timezone']
            ?? 'UTC';
    }

    /**
     * Get tenant weight unit.
     */
    public function weightUnit(): string
    {
        return $this->settings()['physical_dimensions']['weight_unit']
            ?? 'kg';
    }

    /**
     * Get tenant length unit.
     */
    public function lengthUnit(): string
    {
        return $this->settings()['physical_dimensions']['length_unit']
            ?? 'cm';
    }

    /**
     * Format a date using tenant settings.
     */
    public function formatDate($date): string
    {
        return Carbon::parse($date)
            ->setTimezone($this->timezone())
            ->format(
                $this->settings()['date_timezone']['date_format'] ?? 'Y-m-d'
            );
    }

    /**
     * Format date and time using tenant settings.
     */
    public function formatDateTime($date): string
    {
        return Carbon::parse($date)
            ->setTimezone($this->timezone())
            ->format(
                $this->settings()['date_timezone']['extended_date_time_format']
                ?? 'Y-m-d H:i'
            );
    }

    /**
     * Get number formatting configuration.
     */
    public function numberFormat(): array
    {
        $dimensions = $this->settings()['physical_dimensions'];

        return [
            'thousands_token' => $dimensions['thousands_token'] ?? ',',
            'decimal_token' => $dimensions['decimal_token'] ?? '.',
            'decimal_places' => $dimensions['decimal_places'] ?? 2,
        ];
    }

    /**
     * Format a number using tenant settings.
     */
    public function formatNumber(float $number): string
    {
        $format = $this->numberFormat();

        return number_format(
            $number,
            $format['decimal_places'],
            $format['decimal_token'],
            $format['thousands_token']
        );
    }
}
