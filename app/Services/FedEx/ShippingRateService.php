<?php

namespace App\Services\FedEx;

use App\Models\ShippingZone;
use App\Models\StoreShippingAddress;
use Illuminate\Support\Facades\Log;

class ShippingRateService
{
    protected FedExRateService $fedexRateService;

    public function __construct(FedExRateService $fedexRateService)
    {
        $this->fedexRateService = $fedexRateService;
    }

    /**
     * Get all available shipping rates for checkout
     * Combines static methods (Free, Flat Rate) + FedEx real-time rates
     *
     * Origin address ab database se aata hai (store_shipping_address table)
     * Agar database mein nahi hai to .env fallback use hoga
     *
     * $storeId: har cheez isi store ke against — zones, methods, origin address
     */
    public function getCheckoutShippingRates(array $destination, array $packageInfo, ?int $storeId = null, ?array $origin = null, ?string $currency = null): array
    {
        $allRates = [];

        // Origin address: Database se lo (store ke against), nahi mila to .env fallback
        if (!$origin) {
            $storeAddress = StoreShippingAddress::getActive($storeId);

            if ($storeAddress) {
                $origin = $storeAddress->toOriginArray();
            } else {
                // Fallback to .env config
                $origin = [
                    'postal_code'  => config('shipping.origin_postal_code', '33139'),
                    'country_code' => config('shipping.origin_country_code', 'US'),
                    'state'        => config('shipping.origin_state', 'FL'),
                    'city'         => config('shipping.origin_city', 'Miami Beach'),
                ];
            }
        }

        $zone = $this->findMatchingZone($destination['country_code'], $destination['state'] ?? null, $storeId);

        if (!$zone) {
            return ['success' => false, 'error' => 'No shipping zone configured for this destination.', 'rates' => []];
        }

        $methods = $zone->activeShippingMethods()
            ->when($storeId, fn($q) => $q->where('store_id', $storeId))
            ->with('fedexConfiguration')
            ->get();

        foreach ($methods as $method) {
            // Static methods
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

            // FedEx Real-time Rates
            $fedexConfig = $method->fedexConfiguration;
            if (!$fedexConfig || !$fedexConfig->is_connected) {
                Log::warning('FedEx method not connected', ['method_id' => $method->id]);
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

            $fedexResult = $this->fedexRateService->getRates($fedexConfig, $shipmentData, $currency);

            if ($fedexResult['success']) {
                foreach ($fedexResult['rates'] as $rate) {
                    $allRates[] = array_merge($rate, [
                        'method_id'   => $method->id,
                        'method_type' => 'fedex_realtime',
                        'is_fedex'    => true,
                    ]);
                }
            } else {
                Log::warning('FedEx rate fetch failed', ['method_id' => $method->id, 'error' => $fedexResult['error']]);
            }
        }

        usort($allRates, function ($a, $b) {
            if (!$a['is_fedex'] && $b['is_fedex']) return -1;
            if ($a['is_fedex'] && !$b['is_fedex']) return 1;
            return $a['total_charge'] <=> $b['total_charge'];
        });

        return [
            'success' => true,
            'zone'    => ['id' => $zone->id, 'name' => $zone->name],
            'origin'  => $origin,
            'rates'   => $allRates,
        ];
    }

    protected function findMatchingZone(string $countryCode, ?string $stateCode = null, ?int $storeId = null): ?ShippingZone
    {
        $zones = ShippingZone::active()
            ->when($storeId, fn($q) => $q->where('store_id', $storeId))
            ->orderBy('sort_order')
            ->get();

        $bestMatch    = null;
        $bestPriority = -1;

        foreach ($zones as $zone) {
            if (!$zone->matchesDestination($countryCode, $stateCode)) continue;

            $countries = $zone->countries ?? [];

            if (in_array('*', $countries)) {
                $priority = 0;
            } elseif (!empty($zone->states)) {
                $priority = 2;
            } else {
                $priority = 1;
            }

            if ($priority > $bestPriority) {
                $bestPriority = $priority;
                $bestMatch    = $zone;
            }
        }

        return $bestMatch;
    }
}
