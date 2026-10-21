<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShippingMethod extends Model
{
    use HasFactory;
    protected $connection = 'tenant';

    protected $fillable = ['store_id', 'shipping_zone_id', 'method_type', 'display_name', 'is_active', 'sort_order', 'flat_rate_amount', 'flat_rate_type', 'weight_threshold', 'weight_rate_amount', 'free_shipping_min_order', 'custom_description', 'charge_shipping_by', 'default_shipping_cost', 'rate_display_type', 'ranges'];

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

    /**
     * Static rate calculate karta hai.
     * Return null => method us order pe SHOW nahi hoga.
     */
    public function calculateStaticRate(float $orderTotal, float $totalWeight, int $itemCount = 1): ?float
    {
        switch ($this->method_type) {
            case 'free_shipping':
                if ($this->free_shipping_min_order && $orderTotal < $this->free_shipping_min_order) return null;
                return 0.00;

            case 'flat_rate':
                return $this->flat_rate_type === 'per_item' ? $this->flat_rate_amount * $itemCount : $this->flat_rate_amount;

            case 'flat_rate_weight':
                // by_weight ya by_order_total — konsi value pe range check karni hai
                $value = $this->charge_shipping_by === 'by_order_total' ? $orderTotal : $totalWeight;

                // ── Ranges defined hain to unke hisaab se ──
                if (!empty($this->ranges) && is_array($this->ranges)) {
                    foreach ($this->ranges as $range) {
                        $from = isset($range['from']) ? (float) $range['from'] : 0;
                        $upTo = isset($range['up_to']) ? (float) $range['up_to'] : 0;

                        // value range ke andar (from se up_to tak, dono inclusive)
                        if ($value >= $from && $value <= $upTo) {
                            return (float) $range['cost'];
                        }
                    }
                    // Koi range match NAHI hui => method SHOW nahi hoga
                    return null;
                }

                // ── Ranges nahi hain to single weight_threshold logic ──
                // value threshold se ZYADA => method SHOW nahi hoga
                if ($this->weight_threshold !== null && $value > (float) $this->weight_threshold) {
                    return null;
                }
                return $this->weight_rate_amount !== null ? (float) $this->weight_rate_amount : null;

            case 'custom_label':
                return 0.00;

            default:
                return null;
        }
    }
}
