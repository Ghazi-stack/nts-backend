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
     *
     * @param FedexConfiguration $config
     * @param array $shipmentData [
     *     'origin_postal_code'      => '33139',
     *     'origin_country_code'     => 'US',
     *     'origin_state'            => 'FL',
     *     'origin_city'             => 'Miami Beach',
     *     'destination_postal_code' => '90210',
     *     'destination_country_code'=> 'US',
     *     'destination_state'       => 'CA',
     *     'destination_city'        => 'Beverly Hills',
     *     'total_weight'            => 5.0,
     *     'weight_unit'             => 'LB',
     *     'package_length'          => 12,
     *     'package_width'           => 8,
     *     'package_height'          => 6,
     *     'dimension_unit'          => 'IN',
     *     'package_value'           => 100.00,
     *     'item_count'              => 1,
     * ]
     * @return array
     */
    public function getRates(FedexConfiguration $config, array $shipmentData): array
    {
        // 1. Get OAuth token
        $token = $this->authService->getAccessToken($config);
        if (!$token) {
            return [
                'success' => false,
                'error'   => 'Failed to authenticate with FedEx API. Please check your credentials.',
                'rates'   => [],
            ];
        }

        // 2. Build request payload
        $payload = $this->buildRateRequestPayload($config, $shipmentData);

        // 3. Call FedEx Rate API
        try {
            $response = Http::withToken($token)
                ->withHeaders([
                    'Content-Type'             => 'application/json',
                    'X-locale'                 => 'en_US',
                    'x-customer-transaction-id' => 'rate_' . uniqid(),
                ])
                ->timeout(30)
                ->post($config->api_base_url . '/rate/v1/rates/quotes', $payload);

            $responseData = $response->json();

            // 4. Log the quote
            $this->logQuote($config, $shipmentData, $responseData, $response->successful());

            if ($response->successful() && isset($responseData['output']['rateReplyDetails'])) {
                $rates = $this->parseRateResponse($responseData, $config);

                return [
                    'success' => true,
                    'error'   => null,
                    'rates'   => $rates,
                ];
            }

            // Handle error response
            $errorMessage = $this->extractErrorMessage($responseData);

            Log::error('FedEx Rate API error', [
                'config_id' => $config->id,
                'status'    => $response->status(),
                'errors'    => $errorMessage,
            ]);

            return [
                'success' => false,
                'error'   => $errorMessage,
                'rates'   => [],
            ];
        } catch (\Exception $e) {
            Log::error('FedEx Rate API exception', [
                'config_id' => $config->id,
                'error'     => $e->getMessage(),
            ]);

            // Log the failed attempt
            $this->logQuote($config, $shipmentData, null, false, $e->getMessage());

            return [
                'success' => false,
                'error'   => 'FedEx API request failed: ' . $e->getMessage(),
                'rates'   => [],
            ];
        }
    }

    /**
     * Build the FedEx Rate API request payload
     */
    protected function buildRateRequestPayload(FedexConfiguration $config, array $data): array
    {
        $payload = [
            'accountNumber' => [
                'value' => $config->account_number,
            ],
            'rateRequestControlParameters' => [
                'returnTransitTimes' => true,
                'servicesNeededOnRateFailure' => true,
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
                        'postalCode'          => $data['destination_postal_code'],
                        'countryCode'         => $data['destination_country_code'] ?? 'US',
                        'residential'         => ($config->destination_type === 'RESIDENTIAL'),
                    ],
                ],
                'pickupType'    => $config->dropoff_type,
                'rateRequestType' => [$config->rate_type === 'ACCOUNT' ? 'ACCOUNT' : 'LIST'],
                'requestedPackageLineItems' => $this->buildPackageLineItems($config, $data),
            ],
        ];

        // Add state/city to shipper if available
        if (!empty($data['origin_state'])) {
            $payload['requestedShipment']['shipper']['address']['stateOrProvinceCode'] = $data['origin_state'];
        }
        if (!empty($data['origin_city'])) {
            $payload['requestedShipment']['shipper']['address']['city'] = $data['origin_city'];
        }

        // Add state/city to recipient if available
        if (!empty($data['destination_state'])) {
            $payload['requestedShipment']['recipient']['address']['stateOrProvinceCode'] = $data['destination_state'];
        }
        if (!empty($data['destination_city'])) {
            $payload['requestedShipment']['recipient']['address']['city'] = $data['destination_city'];
        }

        // Add declared value if enabled
        if ($config->include_package_value && !empty($data['package_value'])) {
            $payload['requestedShipment']['requestedPackageLineItems'][0]['declaredValue'] = [
                'amount'   => (float) $data['package_value'],
                'currency' => 'USD',
            ];
        }

        return $payload;
    }

    /**
     * Build package line items based on packing method
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
        ];

        // Add dimensions if provided
        if (!empty($data['package_length']) && !empty($data['package_width']) && !empty($data['package_height'])) {
            $package['dimensions'] = [
                'length' => (int) $data['package_length'],
                'width'  => (int) $data['package_width'],
                'height' => (int) $data['package_height'],
                'units'  => $dimUnit,
            ];
        }

        // Add subpackage count for packing method
        if ($config->packing_method === 'ALL_IN_ONE') {
            $package['groupPackageCount'] = 1;
        }

        // Set packaging type
        $package['subPackagingType'] = $config->packaging_type;

        return [$package];
    }

    /**
     * Parse FedEx rate response and filter by enabled services
     */
    protected function parseRateResponse(array $responseData, FedexConfiguration $config): array
    {
        $rates          = [];
        $enabledServices = $config->enabled_services ?? [];
        $rateDetails    = $responseData['output']['rateReplyDetails'] ?? [];
        $allServices    = FedexConfiguration::allServiceCodes();

        foreach ($rateDetails as $detail) {
            $serviceType = $detail['serviceType'] ?? '';
            $serviceName = $detail['serviceName'] ?? ($allServices[$serviceType] ?? $serviceType);

            // Filter: only include enabled services (if enabled_services is set)
            if (!empty($enabledServices) && !in_array($serviceType, $enabledServices)) {
                continue;
            }

            // Get the best rate from ratedShipmentDetails
            $ratedDetails = $detail['ratedShipmentDetails'] ?? [];
            $bestRate     = null;

            foreach ($ratedDetails as $rated) {
                $rateType = $rated['rateType'] ?? '';

                // Prefer PAYOR_ACCOUNT_SHIPMENT rate, then PAYOR_LIST_SHIPMENT
                if ($rateType === 'PAYOR_ACCOUNT_SHIPMENT' || $rateType === 'PAYOR_LIST_SHIPMENT') {
                    $bestRate = $rated;
                    if ($rateType === 'PAYOR_ACCOUNT_SHIPMENT') {
                        break; // preferred, stop looking
                    }
                }
            }

            if (!$bestRate) {
                $bestRate = $ratedDetails[0] ?? null;
            }

            if (!$bestRate) {
                continue;
            }

            // Extract total charge
            $totalCharge = $bestRate['totalNetCharge'] ?? $bestRate['totalNetFedExCharge'] ?? 0;
            $currency    = $bestRate['currency'] ?? 'USD';

            // Extract surcharges
            $surcharges = [];
            foreach ($bestRate['shipmentRateDetail']['surCharges'] ?? [] as $surcharge) {
                $surcharges[] = [
                    'type'        => $surcharge['type'] ?? '',
                    'description' => $surcharge['description'] ?? '',
                    'amount'      => $surcharge['amount'] ?? 0,
                ];
            }

            // Extract transit time
            $transitDays = null;
            $deliveryDate = null;
            $deliveryDay  = null;

            if (isset($detail['commit'])) {
                $commit = $detail['commit'];
                $transitDays  = $commit['transitDays']['description'] ?? ($commit['transitDays'] ?? null);
                $deliveryDate = $commit['dateDetail']['dayFormat'] ?? null;
                $deliveryDay  = $commit['dateDetail']['dayOfWeek'] ?? null;

                // Sometimes transitDays is like "TWO_DAYS", convert to number
                $transitDays = $this->parseTransitDays($transitDays);
            }

            $rates[] = [
                'service_type'   => $serviceType,
                'service_name'   => $serviceName,
                'display_name'   => $config->display_name . ' (' . $serviceName . ')',
                'total_charge'   => round((float) $totalCharge, 2),
                'currency'       => $currency,
                'transit_days'   => $transitDays,
                'delivery_date'  => $deliveryDate,
                'delivery_day'   => $deliveryDay,
                'surcharges'     => $surcharges,
                'rate_type'      => $bestRate['rateType'] ?? '',
            ];
        }

        // Sort by total_charge ascending (cheapest first)
        usort($rates, function ($a, $b) {
            return $a['total_charge'] <=> $b['total_charge'];
        });

        return $rates;
    }

    /**
     * Parse transit days from FedEx response (can be text like "TWO_DAYS" or number)
     */
    protected function parseTransitDays($value): ?int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        if (is_string($value)) {
            $map = [
                'ONE_DAY'    => 1,
                'TWO_DAYS'   => 2,
                'THREE_DAYS' => 3,
                'FOUR_DAYS'  => 4,
                'FIVE_DAYS'  => 5,
                'SIX_DAYS'   => 6,
                'SEVEN_DAYS' => 7,
                'EIGHT_DAYS' => 8,
                'NINE_DAYS'  => 9,
                'TEN_DAYS'   => 10,
                'ELEVEN_DAYS' => 11,
                'TWELVE_DAYS' => 12,
                'THIRTEEN_DAYS' => 13,
                'FOURTEEN_DAYS' => 14,
            ];

            return $map[strtoupper($value)] ?? null;
        }

        return null;
    }

    /**
     * Extract human-readable error message from FedEx response
     */
    protected function extractErrorMessage(array $responseData): string
    {
        // Check for errors array
        if (isset($responseData['errors'])) {
            $messages = [];
            foreach ($responseData['errors'] as $error) {
                $messages[] = ($error['code'] ?? '') . ': ' . ($error['message'] ?? 'Unknown error');
            }
            return implode(' | ', $messages);
        }

        // Check output.alerts
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
     * Log quote request/response to database
     */
    protected function logQuote(FedexConfiguration $config, array $shipmentData, ?array $responseData, bool $success, ?string $errorMessage = null): void
    {
        try {
            FedexQuoteLog::create([
                'fedex_configuration_id' => $config->id,
                'origin_postal_code'     => $shipmentData['origin_postal_code'] ?? null,
                'origin_country_code'    => $shipmentData['origin_country_code'] ?? null,
                'destination_postal_code' => $shipmentData['destination_postal_code'] ?? '',
                'destination_country_code' => $shipmentData['destination_country_code'] ?? 'US',
                'destination_state'      => $shipmentData['destination_state'] ?? null,
                'destination_city'       => $shipmentData['destination_city'] ?? null,
                'total_weight'           => $shipmentData['total_weight'] ?? 0,
                'weight_unit'            => $shipmentData['weight_unit'] ?? 'LB',
                'package_length'         => $shipmentData['package_length'] ?? null,
                'package_width'          => $shipmentData['package_width'] ?? null,
                'package_height'         => $shipmentData['package_height'] ?? null,
                'dimension_unit'         => $shipmentData['dimension_unit'] ?? 'IN',
                'package_value'          => $shipmentData['package_value'] ?? null,
                'rate_response'          => $responseData,
                'status'                 => $success ? 'success' : 'error',
                'error_message'          => $errorMessage ?? ($success ? null : $this->extractErrorMessage($responseData ?? [])),
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
            return [
                'success' => true,
                'message' => 'Successfully connected to FedEx API.',
            ];
        }

        return [
            'success' => false,
            'message' => 'Failed to connect. Please verify your Client ID, Secret Key, and Account Number.',
        ];
    }
}
