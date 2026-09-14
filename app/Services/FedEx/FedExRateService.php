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
     * Get real-time FedEx rates for a shipment.
     *
     * A separate request is made for each enabled FedEx service.
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

        /*
         * Get enabled services from configuration.
         *
         * Example:
         * [
         *     "FEDEX_2_DAY",
         *     "FEDEX_EXPRESS_SAVER",
         *     "FEDEX_GROUND",
         *     "FIRST_OVERNIGHT",
         *     ...
         * ]
         */
        $enabledServices = $config->enabled_services ?? [];

        if (is_string($enabledServices)) {
            $decoded = json_decode($enabledServices, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $enabledServices = $decoded;
            } else {
                $enabledServices = [];
            }
        }

        if (!is_array($enabledServices)) {
            $enabledServices = [];
        }

        /*
         * If no services are configured, there is nothing to request.
         */
        if (empty($enabledServices)) {
            Log::warning('No FedEx services configured', [
                'config_id' => $config->id,
            ]);

            return [
                'success' => false,
                'error'   => 'No FedEx services are enabled.',
                'rates'    => [],
            ];
        }

        /*
         * Respect the configured rate type.
         *
         * Configuration supports:
         * ACCOUNT
         * LIST
         */
        $rateType = strtoupper(trim((string) ($config->rate_type ?: 'LIST')));

        if (!in_array($rateType, ['ACCOUNT', 'LIST'], true)) {
            $rateType = 'LIST';
        }

        $allRates = [];
        $successfulRequests = 0;
        $failedRequests = 0;

        foreach ($enabledServices as $serviceType) {

            $serviceType = strtoupper(trim((string) $serviceType));

            if ($serviceType === '') {
                continue;
            }

            /*
             * Build payload for this specific FedEx service.
             */
            $payload = $this->buildRateRequestPayload(
                $config,
                $shipmentData,
                $serviceType,
                $rateType
            );

            Log::info(
                "FedEx Rate API request payload",
                [
                    'config_id'   => $config->id,
                    'serviceType' => $serviceType,
                    'rateType'    => $rateType,
                    'payload'     => $payload,
                ]
            );

            try {

                $response = Http::withToken($token)
                    ->withHeaders([
                        'Content-Type'              => 'application/json',
                        'X-locale'                  => 'en_US',
                        'x-customer-transaction-id' => 'rate_' . uniqid(),
                    ])
                    ->timeout(30)
                    ->post(
                        $config->api_base_url . '/rate/v1/rates/quotes',
                        $payload
                    );

                $responseData = $response->json();

                Log::info('FedEx Rate API response', [
                    'config_id'   => $config->id,
                    'serviceType' => $serviceType,
                    'http_status' => $response->status(),
                    'successful'  => $response->successful(),
                    'response'    => $responseData,
                ]);

                /*
                 * Store every FedEx quote response in the quote log.
                 */
                $this->logQuote(
                    $config,
                    $shipmentData,
                    $responseData,
                    $response->successful()
                );

                if (
                    $response->successful() &&
                    isset($responseData['output']['rateReplyDetails'])
                ) {

                    $rates = $this->parseRateResponse(
                        $responseData,
                        $config
                    );

                    if (!empty($rates)) {
                        $allRates = array_merge($allRates, $rates);
                    }

                    $successfulRequests++;

                    continue;
                }

                /*
                 * A service may not be available for the shipment.
                 *
                 * Don't fail the entire shipping calculation.
                 * Simply log it and continue with the next enabled service.
                 */
                $errorMessage = $this->extractErrorMessage($responseData);

                Log::warning('FedEx service rate fetch failed', [
                    'config_id'   => $config->id,
                    'serviceType' => $serviceType,
                    'http_status' => $response->status(),
                    'error'       => $errorMessage,
                ]);

                $failedRequests++;

            } catch (\Exception $e) {

                Log::error('FedEx Rate API exception', [
                    'config_id'   => $config->id,
                    'serviceType' => $serviceType,
                    'error'       => $e->getMessage(),
                ]);

                $this->logQuote(
                    $config,
                    $shipmentData,
                    null,
                    false,
                    $e->getMessage()
                );

                $failedRequests++;
            }
        }

        /*
         * Remove duplicate service types.
         *
         * This protects us in case the configuration accidentally
         * contains the same service more than once.
         */
        $uniqueRates = [];

        foreach ($allRates as $rate) {
            $serviceType = $rate['service_type'] ?? null;

            if (!$serviceType) {
                continue;
            }

            $uniqueRates[$serviceType] = $rate;
        }

        $allRates = array_values($uniqueRates);

        /*
         * Sort cheapest first, same as the previous implementation.
         */
        usort(
            $allRates,
            fn ($a, $b) =>
                ($a['total_charge'] ?? 0) <=> ($b['total_charge'] ?? 0)
        );

        /*
         * If at least one service produced a rate,
         * the overall FedEx calculation is successful.
         */
        if (!empty($allRates)) {
            return [
                'success' => true,
                'error'   => null,
                'rates'   => $allRates,
            ];
        }

        /*
         * No service returned a rate.
         */
        return [
            'success' => false,
            'error'   => $failedRequests > 0
                ? 'FedEx did not return any available rates.'
                : 'No FedEx rates were returned.',
            'rates'   => [],
        ];
    }

    /**
     * Build the FedEx Rate API request payload.
     *
     * A serviceType is explicitly supplied because the FedEx
     * sandbox collection request successfully uses this format.
     */
    protected function buildRateRequestPayload(
        FedexConfiguration $config,
        array $data,
        string $serviceType,
        string $rateType
    ): array {
        $pickupType = $this->mapPickupType($config->dropoff_type);

        $packagingType = strtoupper(
            trim((string) ($config->packaging_type ?: 'YOUR_PACKAGING'))
        );

        /*
         * FedEx Rate API request.
         *
         * Keep this close to the successful FedEx sandbox
         * collection payload.
         */
        $payload = [
            'accountNumber' => [
                'value' => $config->account_number,
            ],

            'requestedShipment' => [
                'shipper' => [
                    'address' => [
                        'postalCode' => $data['origin_postal_code'] ?? '',
                        'countryCode' => $data['origin_country_code'] ?? 'US',
                    ],
                ],

                'recipient' => [
                    'address' => [
                        'postalCode' => $data['destination_postal_code'] ?? '',
                        'countryCode' => $data['destination_country_code'] ?? 'US',
                        'residential' => (
                            strtoupper((string) $config->destination_type)
                            === 'RESIDENTIAL'
                        ),
                    ],
                ],

                /*
                 * Critical:
                 * FedEx's working sandbox request includes serviceType.
                 */
                'serviceType' => $serviceType,

                'pickupType' => $pickupType,

                /*
                 * Use exactly what is configured.
                 */
                'packagingType' => $packagingType,

                /*
                 * Configuration supports ACCOUNT or LIST.
                 */
                'rateRequestType' => [
                    $rateType,
                ],

                'requestedPackageLineItems' =>
                    $this->buildPackageLineItems($config, $data),
            ],
        ];

        /*
         * Add shipper state/city when available.
         */
        if (!empty($data['origin_state'])) {
            $payload['requestedShipment']['shipper']['address']
                ['stateOrProvinceCode'] = $data['origin_state'];
        }

        if (!empty($data['origin_city'])) {
            $payload['requestedShipment']['shipper']['address']
                ['city'] = $data['origin_city'];
        }

        /*
         * Add recipient state/city when available.
         */
        if (!empty($data['destination_state'])) {
            $payload['requestedShipment']['recipient']['address']
                ['stateOrProvinceCode'] = $data['destination_state'];
        }

        if (!empty($data['destination_city'])) {
            $payload['requestedShipment']['recipient']['address']
                ['city'] = $data['destination_city'];
        }

        /*
         * Add declared value only when enabled in configuration.
         */
        if (
            $config->include_package_value &&
            isset($data['package_value']) &&
            (float) $data['package_value'] > 0
        ) {
            $payload['requestedShipment']['requestedPackageLineItems'][0]
                ['declaredValue'] = [
                    'amount' => (float) $data['package_value'],
                    'currency' => 'USD',
                ];
        }

        return $payload;
    }

    /**
     * Map legacy/old dropoff_type values to FedEx REST API valid pickupType.
     */
    protected function mapPickupType(?string $dropoffType): string
    {
        $validValues = [
            'USE_SCHEDULED_PICKUP',
            'CONTACT_FEDEX_TO_SCHEDULE',
            'DROPOFF_AT_FEDEX_LOCATION',
        ];

        if (
            $dropoffType &&
            in_array($dropoffType, $validValues, true)
        ) {
            return $dropoffType;
        }

        /*
         * Legacy SOAP API values.
         */
        $legacyMap = [
            'REGULAR_PICKUP'          => 'USE_SCHEDULED_PICKUP',
            'REQUEST_COURIER'         => 'CONTACT_FEDEX_TO_SCHEDULE',
            'DROP_BOX'                => 'DROPOFF_AT_FEDEX_LOCATION',
            'BUSINESS_SERVICE_CENTER' => 'DROPOFF_AT_FEDEX_LOCATION',
            'STATION'                 => 'DROPOFF_AT_FEDEX_LOCATION',
        ];

        if (
            $dropoffType &&
            isset($legacyMap[$dropoffType])
        ) {
            return $legacyMap[$dropoffType];
        }

        return 'DROPOFF_AT_FEDEX_LOCATION';
    }

    /**
     * Build package line items.
     */
    protected function buildPackageLineItems(
        FedexConfiguration $config,
        array $data
    ): array {
        $weightUnit = strtoupper(
            $data['weight_unit'] ?? 'LB'
        );

        $dimUnit = strtoupper(
            $data['dimension_unit'] ?? 'IN'
        );

        $package = [
            'weight' => [
                'units' => $weightUnit,
                'value' => (float) ($data['total_weight'] ?? 1.0),
            ],
        ];

        /*
         * Add dimensions only when all three dimensions exist.
         *
         * Your current products can have zero dimensions, so don't
         * send dimensions when they aren't available.
         */
        if (
            !empty($data['package_length']) &&
            !empty($data['package_width']) &&
            !empty($data['package_height'])
        ) {
            $package['dimensions'] = [
                'length' => (int) $data['package_length'],
                'width'   => (int) $data['package_width'],
                'height'  => (int) $data['package_height'],
                'units'   => $dimUnit,
            ];
        }

        return [$package];
    }

    /**
     * Parse FedEx rate response.
     */
    protected function parseRateResponse(
        array $responseData,
        FedexConfiguration $config
    ): array {
        $rates = [];

        $enabledServices = $config->enabled_services ?? [];

        if (is_string($enabledServices)) {
            $decoded = json_decode($enabledServices, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $enabledServices = $decoded;
            } else {
                $enabledServices = [];
            }
        }

        if (!is_array($enabledServices)) {
            $enabledServices = [];
        }

        $rateDetails =
            $responseData['output']['rateReplyDetails'] ?? [];

        $allServices =
            FedexConfiguration::allServiceCodes();

        foreach ($rateDetails as $detail) {

            $serviceType =
                $detail['serviceType'] ?? '';

            if ($serviceType === '') {
                continue;
            }

            /*
             * If enabled_services is configured, only allow those services.
             */
            if (
                !empty($enabledServices) &&
                !in_array($serviceType, $enabledServices, true)
            ) {
                continue;
            }

            $serviceName =
                $detail['serviceName'] ??
                ($allServices[$serviceType] ?? $serviceType);

            $ratedDetails =
                $detail['ratedShipmentDetails'] ?? [];

            $bestRate = null;

            /*
             * Prefer account shipment pricing when returned.
             */
            foreach ($ratedDetails as $rated) {

                $rateType =
                    $rated['rateType'] ?? '';

                if (
                    $rateType === 'PAYOR_ACCOUNT_SHIPMENT' ||
                    $rateType === 'PAYOR_LIST_SHIPMENT'
                ) {
                    $bestRate = $rated;

                    if (
                        $rateType ===
                        'PAYOR_ACCOUNT_SHIPMENT'
                    ) {
                        break;
                    }
                }
            }

            /*
             * FedEx sandbox's successful collection response can
             * return rateType = ACCOUNT, so fall back to the first
             * rated shipment when the PAYOR_* type isn't present.
             */
            if (!$bestRate) {
                $bestRate = $ratedDetails[0] ?? null;
            }

            if (!$bestRate) {
                continue;
            }

            $totalCharge =
                $bestRate['totalNetCharge'] ??
                $bestRate['totalNetFedExCharge'] ??
                0;

            $currency =
                $bestRate['currency'] ?? 'USD';

            /*
             * Collect surcharges.
             */
            $surcharges = [];

            foreach (
                $bestRate['shipmentRateDetail']['surCharges']
                ?? []
                as $surcharge
            ) {
                $surcharges[] = [
                    'type' =>
                        $surcharge['type'] ?? '',

                    'description' =>
                        $surcharge['description'] ?? '',

                    'amount' =>
                        $surcharge['amount'] ?? 0,
                ];
            }

            $transitDays = null;
            $deliveryDate = null;
            $deliveryDay = null;

            if (isset($detail['commit'])) {

                $commit = $detail['commit'];

                $transitDays =
                    $commit['transitDays']['description']
                    ?? ($commit['transitDays'] ?? null);

                $deliveryDate =
                    $commit['dateDetail']['dayFormat']
                    ?? null;

                $deliveryDay =
                    $commit['dateDetail']['dayOfWeek']
                    ?? null;

                $transitDays =
                    $this->parseTransitDays($transitDays);
            }

            $rates[] = [
                'service_type' => $serviceType,

                'service_name' => $serviceName,

                'display_name' =>
                    $config->display_name .
                    ' (' .
                    $serviceName .
                    ')',

                'total_charge' =>
                    round((float) $totalCharge, 2),

                'currency' => $currency,

                'transit_days' => $transitDays,

                'delivery_date' => $deliveryDate,

                'delivery_day' => $deliveryDay,

                'surcharges' => $surcharges,

                'rate_type' =>
                    $bestRate['rateType'] ?? '',
            ];
        }

        usort(
            $rates,
            fn ($a, $b) =>
                $a['total_charge'] <=> $b['total_charge']
        );

        return $rates;
    }

    /**
     * Parse transit days from FedEx response.
     */
    protected function parseTransitDays($value): ?int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        if (is_string($value)) {

            $map = [
                'ONE_DAY'       => 1,
                'TWO_DAYS'      => 2,
                'THREE_DAYS'    => 3,
                'FOUR_DAYS'    => 4,
                'FIVE_DAYS'    => 5,
                'SIX_DAYS'     => 6,
                'SEVEN_DAYS'   => 7,
                'EIGHT_DAYS'   => 8,
                'NINE_DAYS'    => 9,
                'TEN_DAYS'     => 10,
                'ELEVEN_DAYS'  => 11,
                'TWELVE_DAYS'  => 12,
                'THIRTEEN_DAYS'=> 13,
                'FOURTEEN_DAYS'=> 14,
            ];

            return $map[strtoupper($value)] ?? null;
        }

        return null;
    }

    /**
     * Extract error message from FedEx response.
     */
    protected function extractErrorMessage(
        array $responseData
    ): string {
        if (isset($responseData['errors'])) {

            $messages = [];

            foreach ($responseData['errors'] as $error) {

                $messages[] =
                    ($error['code'] ?? '') .
                    ': ' .
                    ($error['message'] ?? 'Unknown error');
            }

            return implode(' | ', $messages);
        }

        if (isset($responseData['output']['alerts'])) {

            $messages = [];

            foreach (
                $responseData['output']['alerts']
                as $alert
            ) {
                $messages[] =
                    $alert['message'] ?? '';
            }

            return implode(' | ', $messages);
        }

        return 'Unknown FedEx API error';
    }

    /**
     * Log quote to database.
     */
    protected function logQuote(
        FedexConfiguration $config,
        array $shipmentData,
        ?array $responseData,
        bool $success,
        ?string $errorMessage = null
    ): void {
        try {

            FedexQuoteLog::create([
                'fedex_configuration_id' =>
                    $config->id,

                'origin_postal_code' =>
                    $shipmentData['origin_postal_code'] ?? null,

                'origin_country_code' =>
                    $shipmentData['origin_country_code'] ?? null,

                'destination_postal_code' =>
                    $shipmentData['destination_postal_code'] ?? '',

                'destination_country_code' =>
                    $shipmentData['destination_country_code'] ?? 'US',

                'destination_state' =>
                    $shipmentData['destination_state'] ?? null,

                'destination_city' =>
                    $shipmentData['destination_city'] ?? null,

                'total_weight' =>
                    $shipmentData['total_weight'] ?? 0,

                'weight_unit' =>
                    $shipmentData['weight_unit'] ?? 'LB',

                'package_length' =>
                    $shipmentData['package_length'] ?? null,

                'package_width' =>
                    $shipmentData['package_width'] ?? null,

                'package_height' =>
                    $shipmentData['package_height'] ?? null,

                'dimension_unit' =>
                    $shipmentData['dimension_unit'] ?? 'IN',

                'package_value' =>
                    $shipmentData['package_value'] ?? null,

                'rate_response' =>
                    $responseData,

                'status' =>
                    $success ? 'success' : 'error',

                'error_message' =>
                    $errorMessage ??
                    (
                        $success
                            ? null
                            : $this->extractErrorMessage(
                                $responseData ?? []
                            )
                    ),
            ]);

        } catch (\Exception $e) {

            Log::warning(
                'Failed to log FedEx quote',
                [
                    'error' => $e->getMessage(),
                ]
            );
        }
    }

    /**
     * Test connection to FedEx API.
     */
    public function testConnection(
        FedexConfiguration $config
    ): array {
        $token =
            $this->authService->getAccessToken($config);

        if ($token) {
            return [
                'success' => true,
                'message' =>
                    'Successfully connected to FedEx API.',
            ];
        }

        return [
            'success' => false,
            'message' =>
                'Failed to connect. Please verify your credentials.',
        ];
    }
}