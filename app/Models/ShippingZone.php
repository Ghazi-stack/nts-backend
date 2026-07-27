<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShippingZone extends Model
{
    use HasFactory;

    protected $fillable = ['store_id', 'name', 'zone_type', 'countries', 'states', 'zip_ranges', 'is_active', 'sort_order'];

    protected $casts = ['countries' => 'array', 'states' => 'array', 'zip_ranges' => 'array', 'is_active' => 'boolean'];

    public function shippingMethods()
    {
        return $this->hasMany(ShippingMethod::class)->orderBy('sort_order');
    }

    public function activeShippingMethods()
    {
        return $this->hasMany(ShippingMethod::class)->where('is_active', true)->orderBy('sort_order');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForStore($query, $storeId)
    {
        return $query->where('store_id', $storeId);
    }

    public function matchesDestination(string $countryCode, ?string $stateCode = null): bool
    {
        $countries = $this->countries ?? [];
        if (in_array('*', $countries)) return true;
        if (!in_array($countryCode, $countries)) return false;
        $states = $this->states ?? [];
        if (!empty($states) && $stateCode) return in_array($stateCode, $states);
        return true;
    }
}
