<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShippingMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'shipping_zone_id',
        'method_type',
        'display_name',
        'is_active',
        'sort_order',
        'flat_rate_amount',
        'flat_rate_type',
        'weight_threshold',
        'weight_rate_amount',
        'free_shipping_min_order',
        'custom_description',
    ];

    protected $casts = [
        'is_active'              => 'boolean',
        'flat_rate_amount'       => 'decimal:2',
        'weight_threshold'       => 'decimal:2',
        'weight_rate_amount'     => 'decimal:2',
        'free_shipping_min_order' => 'decimal:2',
    ];

    /**
     * Method types — same as BigCommerce screenshot:
     * - free_shipping
     * - flat_rate
     * - flat_rate_weight   (Flat Rate for under 10 LBS item)
     * - fedex_realtime     (Real-time FedEx quotes)
     * - custom_label       (I will provide the shipping label/others)
     */

    // ───────────────────────────────────────
    // Relationships
    // ───────────────────────────────────────

    public function shippingZone()
    {
        return $this->belongsTo(ShippingZone::class);
    }

    public function fedexConfiguration()
    {
        return $this->hasOne(FedexConfiguration::class);
    }

    // ───────────────────────────────────────
    // Scopes
    // ───────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('method_type', $type);
    }

    // ───────────────────────────────────────
    // Helpers
    // ───────────────────────────────────────

    /**
     * Calculate shipping cost for static methods (non-FedEx)
     *
     * @param float $orderTotal
     * @param float $totalWeight
     * @param int $itemCount
     * @return float|null
     */
    public function calculateStaticRate(float $orderTotal, float $totalWeight, int $itemCount = 1): ?float
    {
        switch ($this->method_type) {
            case 'free_shipping':
                // Check minimum order if set
                if ($this->free_shipping_min_order && $orderTotal < $this->free_shipping_min_order) {
                    return null; // not eligible
                }
                return 0.00;

            case 'flat_rate':
                if ($this->flat_rate_type === 'per_item') {
                    return $this->flat_rate_amount * $itemCount;
                }
                return $this->flat_rate_amount; // per_order

            case 'flat_rate_weight':
                // Only apply if weight is under threshold
                if ($this->weight_threshold && $totalWeight <= $this->weight_threshold) {
                    return $this->weight_rate_amount;
                }
                return null; // weight exceeds threshold, not applicable

            case 'custom_label':
                return 0.00; // customer provides own label

            default:
                return null;
        }
    }

    /**
     * Check if this method is FedEx real-time
     */
    public function isFedexRealtime(): bool
    {
        return $this->method_type === 'fedex_realtime';
    }
}
