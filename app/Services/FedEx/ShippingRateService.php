<?php

namespace App\Services\FedEx;

use App\Models\ShippingZone;
use App\Models\ShippingMethod;
use App\Models\FedexConfiguration;
use Illuminate\Support\Facades\Log;

class ShippingRateService
{
    protected FedExRateService $fedexRateService;

    public function __construct(FedExRateService $fedexRateService)
    {
        $this->fedexRateService = $fedexRateService;
    }

    /**
     * Get all available shipping rates for a checkout destination
     * This is the main method called at checkout — exactly like the BigCommerce screenshot
     *
     * @param array $destination [
     *     'country_code' => 'US',
     *     'state'        => 'FL',
     *     'city'         => 'Miami Beach',
     *     'postal_code'  => '33139',
     * ]
     * @param array $packageInfo [
     *     'total_weight'    => 5.0,
     *     'weight_unit'     => 'LB',
     *     'package_length'  => 12,
     *     'package_width'   => 8,
     *     'package_height'  => 6,
     *     'dimension_unit'  => 'IN',
     *     'order_total'     => 150.00,
     *     'item_count'      => 3,
     *     'package_value'   => 150.00,
     * ]
     * @param array|null $origin  Override store origin (optional, defaults from .env)
     * @return array
     */
    public function getCheckoutShippingRates(array $destination, array $packageInfo, ?array $origin = null): array
    {
        $allRates = [];

        // Default origin from .env (store's warehouse/origin address)
        $origin = $origin ?? [
            'postal_code'  => config('shipping.origin_postal_code', '33139'),
            'country_code' => config('shipping.origin_country_code', 'US'),
            'state'        => config('shipping.origin_state', 'FL'),
            'city'         => config('shipping.origin_city', 'Miami Beach'),
        ];

        // 1. Find matching shipping zone for the destination
        $zone = $this->findMatchingZone($destination['country_code'], $destination['state'] ?? null);

        if (!$zone) {
            return [
                'success' => false,
                'error'   => 'No shipping zone configured for this destination.',
                'rates'   => [],
            ];
        }

        // 2. Get all active shipping methods for this zone
        $methods = $zone->activeShippingMethods()->with('fedexConfiguration')->get();

        foreach ($methods as $method) {

            // ── Static methods (Free, Flat Rate, Weight-based, Custom Label) ──
            if (!$method->isFedexRealtime()) {
                $staticRate = $method->calculateStaticRate(
                    (float) ($packageInfo['order_total'] ?? 0),
                    (float) ($packageInfo['total_weight'] ?? 0),
                    (int) ($packageInfo['item_count'] ?? 1)
                );

                if ($staticRate !== null) {
                    $allRates[] = [
                        'method_id'     => $method->id,
                        'method_type'   => $method->method_type,
                        'display_name'  => $method->display_name,
                        'total_charge'  => round($staticRate, 2),
                        'currency'      => 'USD',
                        'transit_days'  => null,
                        'delivery_date' => null,
                        'service_type'  => $method->method_type,
                        'is_fedex'      => false,
                    ];
                }

                continue;
            }

            // ── FedEx Real-time Rates ──
            $fedexConfig = $method->fedexConfiguration;
            if (!$fedexConfig || !$fedexConfig->is_connected) {
                Log::warning('FedEx method found but not connected', ['method_id' => $method->id]);
                continue;
            }

            $shipmentData = [
                'origin_postal_code'       => $origin['postal_code'],
                'origin_country_code'      => $origin['country_code'],
                'origin_state'             => $origin['state'] ?? null,
                'origin_city'              => $origin['city'] ?? null,
                'destination_postal_code'  => $destination['postal_code'],
                'destination_country_code' => $destination['country_code'],
                'destination_state'        => $destination['state'] ?? null,
                'destination_city'         => $destination['city'] ?? null,
                'total_weight'             => $packageInfo['total_weight'] ?? 1.0,
                'weight_unit'              => $packageInfo['weight_unit'] ?? 'LB',
                'package_length'           => $packageInfo['package_length'] ?? null,
                'package_width'            => $packageInfo['package_width'] ?? null,
                'package_height'           => $packageInfo['package_height'] ?? null,
                'dimension_unit'           => $packageInfo['dimension_unit'] ?? 'IN',
                'package_value'            => $packageInfo['package_value'] ?? $packageInfo['order_total'] ?? null,
            ];

            $fedexResult = $this->fedexRateService->getRates($fedexConfig, $shipmentData);

            if ($fedexResult['success']) {
                foreach ($fedexResult['rates'] as $rate) {
                    $allRates[] = array_merge($rate, [
                        'method_id'   => $method->id,
                        'method_type' => 'fedex_realtime',
                        'is_fedex'    => true,
                    ]);
                }
            } else {
                Log::warning('FedEx rate fetch failed', [
                    'method_id' => $method->id,
                    'error'     => $fedexResult['error'],
                ]);
            }
        }

        // 3. Sort: cheapest first, but keep static methods before FedEx
        usort($allRates, function ($a, $b) {
            // Static methods first (custom_label at $0), then by price
            if (!$a['is_fedex'] && $b['is_fedex']) return -1;
            if ($a['is_fedex'] && !$b['is_fedex']) return 1;
            return $a['total_charge'] <=> $b['total_charge'];
        });

        return [
            'success' => true,
            'zone'    => [
                'id'   => $zone->id,
                'name' => $zone->name,
            ],
            'rates'   => $allRates,
        ];
    }

    /**
     * Find the best matching shipping zone for a destination
     * More specific zones (country+state) take priority over wildcard zones
     */
    protected function findMatchingZone(string $countryCode, ?string $stateCode = null): ?ShippingZone
    {
        $zones = ShippingZone::active()->orderBy('sort_order')->get();

        $bestMatch    = null;
        $bestPriority = -1;

        foreach ($zones as $zone) {
            if (!$zone->matchesDestination($countryCode, $stateCode)) {
                continue;
            }

            $countries = $zone->countries ?? [];

            // Priority: specific country+state > specific country > wildcard
            if (in_array('*', $countries)) {
                $priority = 0; // wildcard = lowest priority
            } elseif (!empty($zone->states)) {
                $priority = 2; // country + state = highest
            } else {
                $priority = 1; // specific country
            }

            if ($priority > $bestPriority) {
                $bestPriority = $priority;
                $bestMatch    = $zone;
            }
        }

        return $bestMatch;
    }
}
