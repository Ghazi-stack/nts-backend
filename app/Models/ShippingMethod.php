<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShippingMethod extends Model
{
    use HasFactory;

    protected $fillable = ['shipping_zone_id', 'method_type', 'display_name', 'is_active', 'sort_order', 'flat_rate_amount', 'flat_rate_type', 'weight_threshold', 'weight_rate_amount', 'free_shipping_min_order', 'custom_description', 'charge_shipping_by', 'default_shipping_cost', 'rate_display_type', 'ranges'];

    protected $casts = ['is_active' => 'boolean', 'flat_rate_amount' => 'decimal:2', 'weight_threshold' => 'decimal:2', 'weight_rate_amount' => 'decimal:2', 'free_shipping_min_order' => 'decimal:2', 'default_shipping_cost' => 'decimal:2', 'ranges' => 'array'];

    public function shippingZone()
    {
        return $this->belongsTo(ShippingZone::class);
    }
    public function fedexConfiguration()
    {
        return $this->hasOne(FedexConfiguration::class);
    }
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
    public function isFedexRealtime(): bool
    {
        return $this->method_type === 'fedex_realtime';
    }

    public function calculateStaticRate(float $orderTotal, float $totalWeight, int $itemCount = 1): ?float
    {
        switch ($this->method_type) {
            case 'free_shipping':
                if ($this->free_shipping_min_order && $orderTotal < $this->free_shipping_min_order) return null;
                return 0.00;
            case 'flat_rate':
                return $this->flat_rate_type === 'per_item' ? $this->flat_rate_amount * $itemCount : $this->flat_rate_amount;
            case 'flat_rate_weight':
                // Agar ranges defined hain to unke hisaab se calculate karo
                if (!empty($this->ranges) && is_array($this->ranges)) {
                    $value = $this->charge_shipping_by === 'by_order_total' ? $orderTotal : $totalWeight;
                    foreach ($this->ranges as $range) {
                        $from  = isset($range['from']) ? (float) $range['from'] : 0;
                        $upTo  = isset($range['up_to']) ? (float) $range['up_to'] : 0;
                        // "Up to (but not inc.)" => value < up_to
                        if ($value >= $from && $value < $upTo) {
                            return (float) $range['cost'];
                        }
                    }
                    // Koi range match nahi hui to default cost (agar set hai)
                    return $this->default_shipping_cost !== null ? (float) $this->default_shipping_cost : null;
                }
                // Purana behaviour (single threshold) fallback
                return ($this->weight_threshold && $totalWeight <= $this->weight_threshold) ? $this->weight_rate_amount : null;
            case 'custom_label':
                return 0.00;
            default:
                return null;
        }
    }
}
