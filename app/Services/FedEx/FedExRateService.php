<?php

namespace App\Services\FedEx;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\FedexConfiguration;
use App\Models\FedexQuoteLog;

class FedExRateService
{
    protected FedExAuthService $authService;

    public function __construct(FedExAuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Get real-time FedEx rates for a shipment
     */
    public function getRates(FedexConfiguration $config, array $shipmentData): array
    {
        $token = $this->authService->getAccessToken($config);
        if (!$token) {
            return [
                'success' => false,
                'error'   => 'Failed to authenticate with FedEx API.',
                'rates'   => [],
            ];
        }

        $payload = $this->buildRateRequestPayload($config, $shipmentData);

        try {

        Log::info(
            "FedEx Rate API request payload\n" .
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
            $response = Http::withToken($token)
                ->withHeaders([
                    'Content-Type'              => 'application/json',
                    'X-locale'                  => 'en_US',
                    'x-customer-transaction-id'  => 'rate_' . uniqid(),
                ])
                ->timeout(30)
                ->post($config->api_base_url . '/rate/v1/rates/quotes', $payload);

            $responseData = $response->json();

            Log::info('FedEx Rate API response', [
                'config_id' => $config->id,
                'http_status' => $response->status(),
                'successful' => $response->successful(),
                'response' => $responseData,
            ]);

            $this->logQuote($config, $shipmentData, $responseData, $response->successful());

            if ($response->successful() && isset($responseData['output']['rateReplyDetails'])) {
                $rates = $this->parseRateResponse($responseData, $config);
                return ['success' => true, 'error' => null, 'rates' => $rates];
            }

            $errorMessage = $this->extractErrorMessage($responseData);
            Log::error('FedEx Rate API error', ['config_id' => $config->id, 'errors' => $errorMessage]);

            return ['success' => false, 'error' => $errorMessage, 'rates' => []];

        } catch (\Exception $e) {
            Log::error('FedEx Rate API exception', ['config_id' => $config->id, 'error' => $e->getMessage()]);
            $this->logQuote($config, $shipmentData, null, false, $e->getMessage());

            return ['success' => false, 'error' => 'FedEx API request failed: ' . $e->getMessage(), 'rates' => []];
        }
    }

    /**
     * Build the FedEx Rate API request payload
     */
    protected function buildRateRequestPayload(FedexConfiguration $config, array $data): array
    {
        // Map pickup type — CRITICAL: FedEx REST API only accepts these 3 values
        $pickupType = $this->mapPickupType($config->dropoff_type);

        $payload = [
            'accountNumber' => ['value' => $config->account_number],
            'rateRequestControlParameters' => [
                'returnTransitTimes'           => true,
                'servicesNeededOnRateFailure'   => true,
            ],
            'requestedShipment' => [
                'shipper' => [
                    'address' => [
                        'postalCode'  => $data['origin_postal_code'] ?? '',
                        'countryCode' => $data['origin_country_code'] ?? 'US',
                    ],
                ],
                'recipient' => [
                    'address' => [
                        'postalCode'  => $data['destination_postal_code'],
                        'countryCode' => $data['destination_country_code'] ?? 'US',
                        'residential' => ($config->destination_type === 'RESIDENTIAL'),
                    ],
                ],
                'pickupType'      => $pickupType,
                'rateRequestType' => [$config->rate_type === 'ACCOUNT' ? 'ACCOUNT' : 'LIST'],
                'requestedPackageLineItems' => $this->buildPackageLineItems($config, $data),
            ],
        ];

        if (!empty($data['origin_state'])) {
            $payload['requestedShipment']['shipper']['address']['stateOrProvinceCode'] = $data['origin_state'];
        }
        if (!empty($data['origin_city'])) {
            $payload['requestedShipment']['shipper']['address']['city'] = $data['origin_city'];
        }
        if (!empty($data['destination_state'])) {
            $payload['requestedShipment']['recipient']['address']['stateOrProvinceCode'] = $data['destination_state'];
        }
        if (!empty($data['destination_city'])) {
            $payload['requestedShipment']['recipient']['address']['city'] = $data['destination_city'];
        }

        if ($config->include_package_value && !empty($data['package_value'])) {
            $payload['requestedShipment']['requestedPackageLineItems'][0]['declaredValue'] = [
                'amount'   => (float) $data['package_value'],
                'currency' => 'USD',
            ];
        }

        return $payload;
    }

    /**
     * Map legacy/old dropoff_type values to FedEx REST API valid pickupType
     *
     * FedEx REST API ONLY accepts these 3 values:
     * - USE_SCHEDULED_PICKUP
     * - CONTACT_FEDEX_TO_SCHEDULE
     * - DROPOFF_AT_FEDEX_LOCATION
     */
    protected function mapPickupType(?string $dropoffType): string
    {
        // Valid REST API values — return as-is
        $validValues = [
            'USE_SCHEDULED_PICKUP',
            'CONTACT_FEDEX_TO_SCHEDULE',
            'DROPOFF_AT_FEDEX_LOCATION',
        ];

        if ($dropoffType && in_array($dropoffType, $validValues)) {
            return $dropoffType;
        }

        // Legacy SOAP API values — map to REST equivalents
        $legacyMap = [
            'REGULAR_PICKUP'          => 'USE_SCHEDULED_PICKUP',
            'REQUEST_COURIER'         => 'CONTACT_FEDEX_TO_SCHEDULE',
            'DROP_BOX'                => 'DROPOFF_AT_FEDEX_LOCATION',
            'BUSINESS_SERVICE_CENTER' => 'DROPOFF_AT_FEDEX_LOCATION',
            'STATION'                 => 'DROPOFF_AT_FEDEX_LOCATION',
        ];

        if ($dropoffType && isset($legacyMap[$dropoffType])) {
            return $legacyMap[$dropoffType];
        }

        // Fallback — always return a valid value
        return 'DROPOFF_AT_FEDEX_LOCATION';
    }

    /**
     * Build package line items
     */
    protected function buildPackageLineItems(FedexConfiguration $config, array $data): array
    {
        $weightUnit = strtoupper($data['weight_unit'] ?? 'LB');
        $dimUnit    = strtoupper($data['dimension_unit'] ?? 'IN');

        $package = [
            'weight' => [
                'units' => $weightUnit,
                'value' => (float) ($data['total_weight'] ?? 1.0),
            ],
            'groupPackageCount' => 1,
        ];

        if (!empty($data['package_length']) && !empty($data['package_width']) && !empty($data['package_height'])) {
            $package['dimensions'] = [
                'length' => (int) $data['package_length'],
                'width'  => (int) $data['package_width'],
                'height' => (int) $data['package_height'],
                'units'  => $dimUnit,
            ];
        }

        return [$package];
    }

    /**
     * Parse FedEx rate response and filter by enabled services
     */
    protected function parseRateResponse(array $responseData, FedexConfiguration $config): array
    {
        $rates           = [];
        $enabledServices = $config->enabled_services ?? [];
        $rateDetails     = $responseData['output']['rateReplyDetails'] ?? [];
        $allServices     = FedexConfiguration::allServiceCodes();

        foreach ($rateDetails as $detail) {
            $serviceType = $detail['serviceType'] ?? '';
            $serviceName = $detail['serviceName'] ?? ($allServices[$serviceType] ?? $serviceType);

            if (!empty($enabledServices) && !in_array($serviceType, $enabledServices)) {
                continue;
            }

            $ratedDetails = $detail['ratedShipmentDetails'] ?? [];
            $bestRate     = null;

            foreach ($ratedDetails as $rated) {
                $rateType = $rated['rateType'] ?? '';
                if ($rateType === 'PAYOR_ACCOUNT_SHIPMENT' || $rateType === 'PAYOR_LIST_SHIPMENT') {
                    $bestRate = $rated;
                    if ($rateType === 'PAYOR_ACCOUNT_SHIPMENT') break;
                }
            }

            if (!$bestRate) $bestRate = $ratedDetails[0] ?? null;
            if (!$bestRate) continue;

            $totalCharge = $bestRate['totalNetCharge'] ?? $bestRate['totalNetFedExCharge'] ?? 0;
            $currency    = $bestRate['currency'] ?? 'USD';

            $surcharges = [];
            foreach ($bestRate['shipmentRateDetail']['surCharges'] ?? [] as $surcharge) {
                $surcharges[] = [
                    'type'        => $surcharge['type'] ?? '',
                    'description' => $surcharge['description'] ?? '',
                    'amount'      => $surcharge['amount'] ?? 0,
                ];
            }

            $transitDays  = null;
            $deliveryDate = null;
            $deliveryDay  = null;

            if (isset($detail['commit'])) {
                $commit       = $detail['commit'];
                $transitDays  = $commit['transitDays']['description'] ?? ($commit['transitDays'] ?? null);
                $deliveryDate = $commit['dateDetail']['dayFormat'] ?? null;
                $deliveryDay  = $commit['dateDetail']['dayOfWeek'] ?? null;
                $transitDays  = $this->parseTransitDays($transitDays);
            }

            $rates[] = [
                'service_type'  => $serviceType,
                'service_name'  => $serviceName,
                'display_name'  => $config->display_name . ' (' . $serviceName . ')',
                'total_charge'  => round((float) $totalCharge, 2),
                'currency'      => $currency,
                'transit_days'  => $transitDays,
                'delivery_date' => $deliveryDate,
                'delivery_day'  => $deliveryDay,
                'surcharges'    => $surcharges,
                'rate_type'     => $bestRate['rateType'] ?? '',
            ];
        }

        usort($rates, fn($a, $b) => $a['total_charge'] <=> $b['total_charge']);

        return $rates;
    }

    /**
     * Parse transit days from FedEx response
     */
    protected function parseTransitDays($value): ?int
    {
        if (is_numeric($value)) return (int) $value;

        if (is_string($value)) {
            $map = [
                'ONE_DAY' => 1, 'TWO_DAYS' => 2, 'THREE_DAYS' => 3, 'FOUR_DAYS' => 4,
                'FIVE_DAYS' => 5, 'SIX_DAYS' => 6, 'SEVEN_DAYS' => 7, 'EIGHT_DAYS' => 8,
                'NINE_DAYS' => 9, 'TEN_DAYS' => 10, 'ELEVEN_DAYS' => 11, 'TWELVE_DAYS' => 12,
                'THIRTEEN_DAYS' => 13, 'FOURTEEN_DAYS' => 14,
            ];
            return $map[strtoupper($value)] ?? null;
        }

        return null;
    }

    /**
     * Extract error message from FedEx response
     */
    protected function extractErrorMessage(array $responseData): string
    {
        if (isset($responseData['errors'])) {
            $messages = [];
            foreach ($responseData['errors'] as $error) {
                $messages[] = ($error['code'] ?? '') . ': ' . ($error['message'] ?? 'Unknown error');
            }
            return implode(' | ', $messages);
        }

        if (isset($responseData['output']['alerts'])) {
            $messages = [];
            foreach ($responseData['output']['alerts'] as $alert) {
                $messages[] = $alert['message'] ?? '';
            }
            return implode(' | ', $messages);
        }

        return 'Unknown FedEx API error';
    }

    /**
     * Log quote to database
     */
    protected function logQuote(FedexConfiguration $config, array $shipmentData, ?array $responseData, bool $success, ?string $errorMessage = null): void
    {
        try {
            FedexQuoteLog::create([
                'fedex_configuration_id'  => $config->id,
                'origin_postal_code'      => $shipmentData['origin_postal_code'] ?? null,
                'origin_country_code'     => $shipmentData['origin_country_code'] ?? null,
                'destination_postal_code' => $shipmentData['destination_postal_code'] ?? '',
                'destination_country_code'=> $shipmentData['destination_country_code'] ?? 'US',
                'destination_state'       => $shipmentData['destination_state'] ?? null,
                'destination_city'        => $shipmentData['destination_city'] ?? null,
                'total_weight'            => $shipmentData['total_weight'] ?? 0,
                'weight_unit'             => $shipmentData['weight_unit'] ?? 'LB',
                'package_length'          => $shipmentData['package_length'] ?? null,
                'package_width'           => $shipmentData['package_width'] ?? null,
                'package_height'          => $shipmentData['package_height'] ?? null,
                'dimension_unit'          => $shipmentData['dimension_unit'] ?? 'IN',
                'package_value'           => $shipmentData['package_value'] ?? null,
                'rate_response'           => $responseData,
                'status'                  => $success ? 'success' : 'error',
                'error_message'           => $errorMessage ?? ($success ? null : $this->extractErrorMessage($responseData ?? [])),
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to log FedEx quote', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Test connection to FedEx API
     */
    public function testConnection(FedexConfiguration $config): array
    {
        $token = $this->authService->getAccessToken($config);

        if ($token) {
            return ['success' => true, 'message' => 'Successfully connected to FedEx API.'];
        }

        return ['success' => false, 'message' => 'Failed to connect. Please verify your credentials.'];
    }
}
