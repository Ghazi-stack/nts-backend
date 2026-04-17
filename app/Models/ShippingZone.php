<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShippingZone extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'zone_type',
        'countries',
        'states',
        'zip_ranges',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'countries'  => 'array',
        'states'     => 'array',
        'zip_ranges' => 'array',
        'is_active'  => 'boolean',
    ];

    // ───────────────────────────────────────
    // Relationships
    // ───────────────────────────────────────

    public function shippingMethods()
    {
        return $this->hasMany(ShippingMethod::class)->orderBy('sort_order');
    }

    public function activeShippingMethods()
    {
        return $this->hasMany(ShippingMethod::class)->where('is_active', true)->orderBy('sort_order');
    }

    // ───────────────────────────────────────
    // Scopes
    // ───────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ───────────────────────────────────────
    // Helper: Check if a country belongs to this zone
    // ───────────────────────────────────────

    /**
     * Check if destination country/state matches this zone
     *
     * @param string $countryCode  e.g. "US"
     * @param string|null $stateCode  e.g. "CA"
     * @return bool
     */
    public function matchesDestination(string $countryCode, ?string $stateCode = null): bool
    {
        $countries = $this->countries ?? [];

        // "Rest of the World" zone — matches everything
        if (in_array('*', $countries)) {
            return true;
        }

        // Check country match
        if (!in_array($countryCode, $countries)) {
            return false;
        }

        // If states are specified, check state match
        $states = $this->states ?? [];
        if (!empty($states) && $stateCode) {
            return in_array($stateCode, $states);
        }

        return true;
    }
}
