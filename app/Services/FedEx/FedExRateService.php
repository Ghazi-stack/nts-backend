<?php

namespace App\Services\FedEx;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\FedexConfiguration;
use App\Models\FedexQuoteLog;
use Carbon\Carbon;

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
     * One FedEx request is made for each enabled service.
     *
     * Example:
     * GROUND_HOME_DELIVERY
     * FEDEX_2_DAY
     * STANDARD_OVERNIGHT
     * etc.
     */
    public function getRates(FedexConfiguration $config, array $shipmentData, ?string $preferredCurrency = null): array
    {
        $token = $this->authService->getAccessToken($config);

        if (!$token) {
            Log::error('FedEx authentication failed', [
                'config_id' => $config->id,
            ]);

            return [
                'success' => false,
                'error'   => 'Failed to authenticate with FedEx API.',
                'rates'   => [],
            ];
        }

        /*
         * Only ACCOUNT and LIST are supported by the configuration.
         */
        $rateType = strtoupper((string) ($config->rate_type ?? 'ACCOUNT'));

        if (!in_array($rateType, ['ACCOUNT', 'LIST'], true)) {
            $rateType = 'ACCOUNT';
        }

        /*
         * enabled_services contains the exact FedEx service codes that
         * should be requested. We intentionally make one request per
         * enabled service because this is the request pattern proven to
         * work with the current FedEx sandbox account.
         */
        $enabledServices = $config->enabled_services ?? [];

        if (is_string($enabledServices)) {
            $decoded = json_decode($enabledServices, true);
            $enabledServices = is_array($decoded) ? $decoded : [];
        }

        $enabledServices = array_values(
            array_filter(
                array_map(
                    static fn ($service) => strtoupper(trim((string) $service)),
                    $enabledServices
                )
            )
        );

        if (empty($enabledServices)) {
            Log::warning('No FedEx services are enabled', [
                'config_id' => $config->id,
            ]);

            return [
                'success' => false,
                'error'   => 'No FedEx services are enabled.',
                'rates'   => [],
            ];
        }

        $allRates = [];
        $errors = [];

        foreach ($enabledServices as $serviceType) {
            try {
                $payload = $this->buildRateRequestPayload(
                    $config,
                    $shipmentData,
                    $serviceType,
                    $preferredCurrency
                );

                $logPayload = $payload;

                if (isset($logPayload['accountNumber']['value'])) {
                    $logPayload['accountNumber']['value'] = '***';
                }

                // Log::info('FedEx Rate API request', [
                //     'config_id' => $config->id,
                //     'service' => $serviceType,
                //     'rate_type' => $rateType,
                //     'payload' => $logPayload,
                // ]);

                $url = rtrim(
                    (string) $config->api_base_url,
                    '/'
                ) . '/rate/v1/rates/quotes';

                $response = Http::withToken($token)
                    ->acceptJson()
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'X-locale' => 'en_US',
                        'x-customer-transaction-id' =>
                            'rate_' . uniqid('', true),
                    ])
                    ->timeout(30)
                    ->connectTimeout(15)
                    ->post($url, $payload);

                $responseData = $response->json();

                // Log::info('FedEx Rate API response', [
                //     'config_id' => $config->id,
                //     'service' => $serviceType,
                //     'status' => $response->status(),
                //     'response' => $responseData,
                // ]);

                $successful = $response->successful()
                    && isset($responseData['output']['rateReplyDetails']);

                $this->logQuote(
                    $config,
                    $shipmentData,
                    $responseData,
                    $successful,
                    $successful
                        ? null
                        : $this->extractErrorMessage($responseData)
                );

                if (!$successful) {
                    $errorMessage = $this->extractErrorMessage($responseData);

                    $errors[] = [
                        'service' => $serviceType,
                        'error' => $errorMessage,
                    ];

                    Log::error('FedEx Rate API error', [
                        'config_id' => $config->id,
                        'service' => $serviceType,
                        'http_status' => $response->status(),
                        'error' => $errorMessage,
                        'response' => $responseData,
                    ]);

                    /*
                     * IMPORTANT:
                     * One unavailable FedEx service must not prevent
                     * other enabled services from being returned.
                     */
                    continue;
                }

                $rates = $this->parseRateResponse(
                    $responseData,
                    $config,
                    $preferredCurrency
                );

                foreach ($rates as $rate) {
                    /*
                     * Because this request was made for one service,
                     * protect against an unexpected response containing
                     * a different service.
                     */
                    if (
                        isset($rate['service_type']) &&
                        strtoupper((string) $rate['service_type'])
                            !== $serviceType
                    ) {
                        continue;
                    }

                    $allRates[] = $rate;
                }

            } catch (\Throwable $e) {
                $errors[] = [
                    'service' => $serviceType,
                    'error' => $e->getMessage(),
                ];

                Log::error('FedEx Rate API exception', [
                    'config_id' => $config->id,
                    'service' => $serviceType,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $this->logQuote(
                    $config,
                    $shipmentData,
                    null,
                    false,
                    $e->getMessage()
                );
            }
        }

        usort(
            $allRates,
            static fn ($a, $b) =>
                ((float) ($a['total_charge'] ?? 0))
                <=>
                ((float) ($b['total_charge'] ?? 0))
        );

        /*
         * At least one FedEx service succeeded.
         */
        if (!empty($allRates)) {
            return [
                'success' => true,
                'error' => null,
                'rates' => $allRates,
            ];
        }

        /*
         * Every enabled service failed.
         */
        $errorMessage = 'FedEx did not return any shipping rates.';

        if (!empty($errors)) {
            $errorMessage = implode(
                ' | ',
                array_map(
                    static function ($item) {
                        return ($item['service'] ?? 'UNKNOWN')
                            . ': '
                            . ($item['error'] ?? 'Unknown error');
                    },
                    $errors
                )
            );
        }

        return [
            'success' => false,
            'error' => $errorMessage,
            'rates' => [],
        ];
    }

    /**
     * Build the FedEx Rate API request payload.
     *
     * This intentionally follows the payload structure proven to work
     * in the FedEx sandbox/Postman request.
     */
    protected function buildRateRequestPayload(
        FedexConfiguration $config,
        array $data,
        string $serviceType,
        ?string $preferredCurrency = null
    ): array {
        $pickupType = $this->mapPickupType(
            $config->dropoff_type
        );

        $rateType = strtoupper(
            (string) ($config->rate_type ?? 'ACCOUNT')
        );

        if (!in_array($rateType, ['ACCOUNT', 'LIST'], true)) {
            $rateType = 'ACCOUNT';
        }

        $destinationType = strtoupper(
            (string) ($config->destination_type ?? 'RESIDENTIAL')
        );

        /*
         * Match the working FedEx request:
         *
         * residential is sent as a string "true"/"false".
         */
        $residential = $destinationType === 'RESIDENTIAL'
            ? 'true'
            : 'false';

        $weightUnit = strtoupper(
            (string) ($data['weight_unit'] ?? 'LB')
        );

        $totalWeight = $data['total_weight'] ?? 0;

        /*
         * FedEx sandbox's successful request used:
         *
         * "value": "8"
         *
         * Use a string here to closely match the working request.
         */
        $weightValue = $this->formatFedexNumber($totalWeight);

        $payload = [
            'accountNumber' => [
                'value' => (string) $config->account_number,
            ],

            'rateRequestControlParameters' => [
                'returnTransitTimes' => true,
            ],

            'requestedShipment' => [
                'shipper' => [
                    'address' => [
                        'postalCode' => (string) (
                            $data['origin_postal_code'] ?? ''
                        ),
                        'countryCode' => strtoupper(
                            (string) (
                                $data['origin_country_code'] ?? 'US'
                            )
                        )
                    ],
                ],

                'recipient' => [
                    'address' => [
                        'postalCode' => (string) (
                            $data['destination_postal_code'] ?? ''
                        ),
                        'countryCode' => strtoupper(
                            (string) (
                                $data['destination_country_code'] ?? 'US'
                            )
                        ),
                        'residential' => $residential,
                    ],
                ],

                'serviceType' => $serviceType,

                'serviceType' => strtoupper($serviceType),

                'pickupType' => $pickupType,

                // 'rateRequestType' => [
                //     $rateType,
                // ],

                'rateRequestType' => $preferredCurrency
                    ? array_values(array_unique([$rateType, 'PREFERRED']))
                    : [$rateType],

                'preferredCurrency' => $preferredCurrency
                    ? strtoupper($preferredCurrency)
                    : null,

                /*
                * packagingType belongs to requestedShipment.
                */
                // 'packagingType' => strtoupper(
                //     trim((string) ($config->packaging_type ?? 'YOUR_PACKAGING'))
                // ),

                'requestedPackageLineItems' => [
                    $this->buildPackageLineItem(
                        $config,
                        $data,
                        $weightUnit,
                        $weightValue
                    ),
                ],
            ],
        ];

        if (empty($preferredCurrency)) {
            unset($payload['requestedShipment']['preferredCurrency']);
        }

        return $payload;
    }

    /**
     * Build a single package line item.
     *
     * Deliberately does NOT send groupPackageCount.
     */
    protected function buildPackageLineItem(
        FedexConfiguration $config,
        array $data,
        string $weightUnit,
        string $weightValue
    ): array {
        $package = [
            'weight' => [
                'units' => $weightUnit,
                'value' => $weightValue,
            ],
        ];

        /*
         * Dimensions are only sent when all three values are available.
         *
         * This prevents 0 x 0 x 0 dimensions from being sent for products
         * that don't have dimensional data.
         */
        $length = $data['package_length'] ?? null;
        $width  = $data['package_width'] ?? null;
        $height = $data['package_height'] ?? null;

        if (
            $this->hasPositiveDimension($length) &&
            $this->hasPositiveDimension($width) &&
            $this->hasPositiveDimension($height)
        ) {
            $dimUnit = strtoupper(
                (string) ($data['dimension_unit'] ?? 'IN')
            );

            $package['dimensions'] = [
                'length' => (int) $length,
                'width'  => (int) $width,
                'height' => (int) $height,
                'units'  => $dimUnit,
            ];
        }

        /*
         * Declared value is optional and controlled by the DB setting.
         */
        if (
            !empty($config->include_package_value) &&
            isset($data['package_value']) &&
            (float) $data['package_value'] > 0
        ) {
            $package['declaredValue'] = [
                'amount' => (float) $data['package_value'],
                'currency' => 'USD',
            ];
        }

        return $package;
    }

    /**
     * Parse FedEx rate response and filter by enabled services.
     */
    protected function parseRateResponse(
        array $responseData,
        FedexConfiguration $config,
        ?string $preferredCurrency = null
    ): array {
        $rates = [];

        $enabledServices = $config->enabled_services ?? [];

        if (is_string($enabledServices)) {
            $decoded = json_decode($enabledServices, true);
            $enabledServices = is_array($decoded) ? $decoded : [];
        }

        $enabledServices = array_map(
            static fn ($service) => strtoupper(trim((string) $service)),
            $enabledServices
        );

        $rateDetails = $responseData['output']['rateReplyDetails'] ?? [];

        $allServices = FedexConfiguration::allServiceCodes();

        foreach ($rateDetails as $detail) {
            $serviceType = strtoupper(
                (string) ($detail['serviceType'] ?? '')
            );

            if ($serviceType === '') {
                continue;
            }

            /*
             * Only configured services are allowed.
             */
            if (
                !empty($enabledServices) &&
                !in_array($serviceType, $enabledServices, true)
            ) {
                continue;
            }

            $serviceName =
                $detail['serviceName']
                ?? ($allServices[$serviceType] ?? $serviceType);

            $ratedDetails =
                $detail['ratedShipmentDetails'] ?? [];

            if (empty($ratedDetails)) {
                continue;
            }

            /*
             * Prefer the requested rate type.
             */
            $configuredRateType = strtoupper(
                (string) ($config->rate_type ?? 'ACCOUNT')
            );

            $bestRate = null;

            // foreach ($ratedDetails as $rated) {
            //     $responseRateType = strtoupper(
            //         (string) ($rated['rateType'] ?? '')
            //     );

            //     if (
            //         $configuredRateType === 'ACCOUNT' &&
            //         in_array(
            //             $responseRateType,
            //             [
            //                 'ACCOUNT',
            //                 'PAYOR_ACCOUNT_SHIPMENT',
            //                 'PAYOR_ACCOUNT_PACKAGE',
            //             ],
            //             true
            //         )
            //     ) {
            //         $bestRate = $rated;
            //         break;
            //     }

            //     if (
            //         $configuredRateType === 'LIST' &&
            //         in_array(
            //             $responseRateType,
            //             [
            //                 'LIST',
            //                 'PAYOR_LIST_SHIPMENT',
            //                 'PAYOR_LIST_PACKAGE',
            //             ],
            //             true
            //         )
            //     ) {
            //         $bestRate = $rated;
            //         break;
            //     }
            // }

            foreach ($ratedDetails as $rated) {
                $responseRateType = strtoupper(
                    (string) ($rated['rateType'] ?? '')
                );

                $responseCurrency = strtoupper(
                    (string) (
                        $rated['currency']
                        ?? $rated['shipmentRateDetail']['currency']
                        ?? ''
                    )
                );

                // If a preferred currency was requested, select a rate
                // only when FedEx actually returned that currency.
                if (
                    $preferredCurrency &&
                    $responseCurrency === strtoupper($preferredCurrency)
                ) {
                    $bestRate = $rated;
                    break;
                }

                // Otherwise preserve the existing ACCOUNT/LIST selection.
                if (
                    !$preferredCurrency &&
                    $configuredRateType === 'ACCOUNT' &&
                    in_array(
                        $responseRateType,
                        ['ACCOUNT', 'PAYOR_ACCOUNT_SHIPMENT', 'PAYOR_ACCOUNT_PACKAGE'],
                        true
                    )
                ) {
                    $bestRate = $rated;
                    break;
                }

                if (
                    !$preferredCurrency &&
                    $configuredRateType === 'LIST' &&
                    in_array(
                        $responseRateType,
                        ['LIST', 'PAYOR_LIST_SHIPMENT', 'PAYOR_LIST_PACKAGE'],
                        true
                    )
                ) {
                    $bestRate = $rated;
                    break;
                }
            }

            /*
             * Fallback to the first returned rate if FedEx uses another
             * response rateType representation.
             */
            if (!$bestRate) {
                $bestRate = $ratedDetails[0] ?? null;
            }

            if (!$bestRate) {
                continue;
            }

            $totalCharge =
                $bestRate['totalNetCharge']
                ?? $bestRate['totalNetFedExCharge']
                ?? $bestRate['netCharge']
                ?? 0;

            $currency =
                $bestRate['currency']
                ?? $bestRate['shipmentRateDetail']['currency']
                ?? 'USD';

            /*
             * Collect surcharges.
             */
            $surcharges = [];

            foreach (
                $bestRate['shipmentRateDetail']['surCharges'] ?? []
                as $surcharge
            ) {
                $surcharges[] = [
                    'type' => $surcharge['type'] ?? '',
                    'description' => $surcharge['description'] ?? '',
                    'amount' => (float) (
                        $surcharge['amount'] ?? 0
                    ),
                ];
            }

            /*
             * Some FedEx responses use "surcharges" instead of
             * "surCharges".
             */
            if (empty($surcharges)) {
                foreach (
                    $bestRate['shipmentRateDetail']['surcharges'] ?? []
                    as $surcharge
                ) {
                    $surcharges[] = [
                        'type' => $surcharge['type'] ?? '',
                        'description' => $surcharge['description'] ?? '',
                        'amount' => (float) (
                            $surcharge['amount'] ?? 0
                        ),
                    ];
                }
            }

            /*
            * Transit / delivery information.
            *
            * FedEx may provide transitDays directly, but production
            * responses can also provide the committed delivery date
            * through commit.dateDetail.dayFormat and/or
            * operationalDetail.deliveryDate.
            */
            $transitDays = null;
            $deliveryDate = null;
            $deliveryDay = null;

            $commit = $detail['commit'] ?? [];
            $operationalDetail = $detail['operationalDetail'] ?? [];

            /*
            * Delivery date.
            *
            * Prefer operationalDetail.deliveryDate when available,
            * otherwise use commit.dateDetail.dayFormat.
            */
            $deliveryDate =
                $operationalDetail['deliveryDate']
                ?? $commit['dateDetail']['dayFormat']
                ?? null;

            /*
            * Delivery day.
            */
            $deliveryDay =
                $operationalDetail['deliveryDay']
                ?? $commit['dateDetail']['dayOfWeek']
                ?? null;

            /*
            * ---------------------------------------------------------
            * Transit days
            * ---------------------------------------------------------
            *
            * 1. First try FedEx's explicit transit information.
            * 2. If unavailable, calculate it from quoteDate -> deliveryDate.
            */

            /*
            * FedEx explicit transit time.
            *
            * Example:
            * "ONE_DAY"
            * "TWO_DAYS"
            * "THREE_DAYS"
            *
            * or:
            * "1 Business Day"
            */
            $transitValue =
                $commit['transitDays']['minimumTransitTime']
                ?? $commit['transitDays']['description']
                ?? $operationalDetail['transitTime']
                ?? null;

            if ($transitValue !== null) {
                $transitDays = $this->parseTransitDays($transitValue);
            }

            /*
            * Fallback:
            * Calculate transit days from FedEx quote date
            * to the committed delivery date.
            */
            if ($transitDays === null && $deliveryDate) {
                try {
                    $quoteDate = $responseData['quoteDate'] ?? null;

                    if ($quoteDate) {
                        $quote = \Carbon\Carbon::parse($quoteDate)->startOfDay();
                        $delivery = \Carbon\Carbon::parse($deliveryDate)->startOfDay();

                        $transitDays = $quote->diffInDays($delivery);
                    }
                } catch (\Throwable $e) {
                    Log::warning('Unable to calculate FedEx transit days', [
                        'service' => $serviceType,
                        'quote_date' => $responseData['quoteDate'] ?? null,
                        'delivery_date' => $deliveryDate,
                        'transit_value' => $transitValue,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            /*
            * Format delivery date for US format.
            *
            * Example:
            * 2026-09-16T23:59:00
            * becomes:
            * 09/16/2026
            */
            if ($deliveryDate) {
                try {
                    $deliveryDate = \Carbon\Carbon::parse($deliveryDate)->format('m/d/Y');
                } catch (\Throwable $e) {
                    Log::warning('Unable to format FedEx delivery date', [
                        'service' => $serviceType,
                        'delivery_date' => $deliveryDate,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $rates[] = [
                'service_type' => $serviceType,

                'service_name' => $serviceName,

                'display_name' =>
                    trim((string) $config->display_name) !== ''
                        ? $config->display_name
                            . ' ('
                            . $serviceName
                            . ')'
                        : $serviceName,

                'total_charge' => round(
                    (float) $totalCharge,
                    2
                ),

                'currency' => $currency,

                'transit_days' => $transitDays,

                'delivery_date' => $deliveryDate,

                'delivery_day' => $deliveryDay,

                'surcharges' => $surcharges,

                'rate_type' =>
                    $bestRate['rateType']
                    ?? $configuredRateType,
            ];
        }

        usort(
            $rates,
            static fn ($a, $b) =>
                ((float) ($a['total_charge'] ?? 0))
                <=>
                ((float) ($b['total_charge'] ?? 0))
        );

        return $rates;
    }

    /**
     * Parse transit days from FedEx response.
     */
    protected function parseTransitDays($value): ?int
    {
        if ($value === null) {
            return null;
        }

        /*
        * Numeric value.
        */
        if (is_numeric($value)) {
            return (int) $value;
        }

        $value = strtoupper(trim((string) $value));

        /*
        * FedEx enum values.
        */
        $map = [
            'ONE_DAY' => 1,
            'TWO_DAYS' => 2,
            'THREE_DAYS' => 3,
            'FOUR_DAYS' => 4,
            'FIVE_DAYS' => 5,
            'SIX_DAYS' => 6,
            'SEVEN_DAYS' => 7,
            'EIGHT_DAYS' => 8,
            'NINE_DAYS' => 9,
            'TEN_DAYS' => 10,
            'ELEVEN_DAYS' => 11,
            'TWELVE_DAYS' => 12,
            'THIRTEEN_DAYS' => 13,
            'FOURTEEN_DAYS' => 14,
        ];

        if (isset($map[$value])) {
            return $map[$value];
        }

        /*
        * Handle strings such as:
        *
        * "1 Business Day"
        * "2 Business Days"
        * "3 Business Days"
        */
        if (preg_match('/\b(\d+)\s*(?:BUSINESS\s+)?DAYS?\b/i', $value, $matches)) {
            return (int) $matches[1];
        }

        /*
        * Handle "ONE DAY", "TWO DAYS", etc.
        */
        $wordMap = [
            'ONE DAY' => 1,
            'TWO DAYS' => 2,
            'THREE DAYS' => 3,
            'FOUR DAYS' => 4,
            'FIVE DAYS' => 5,
            'SIX DAYS' => 6,
            'SEVEN DAYS' => 7,
            'EIGHT DAYS' => 8,
            'NINE DAYS' => 9,
            'TEN DAYS' => 10,
            'ELEVEN DAYS' => 11,
            'TWELVE DAYS' => 12,
            'THIRTEEN DAYS' => 13,
            'FOURTEEN DAYS' => 14,
        ];

        return $wordMap[$value] ?? null;
    }

    /**
     * Extract error message from FedEx response.
     */
    protected function extractErrorMessage(array $responseData): string
    {
        if (isset($responseData['errors']) && is_array($responseData['errors'])) {
            $messages = [];

            foreach ($responseData['errors'] as $error) {
                $code = $error['code'] ?? '';
                $message = $error['message'] ?? 'Unknown error';

                $messages[] = trim(
                    $code !== ''
                        ? $code . ': ' . $message
                        : $message
                );
            }

            if (!empty($messages)) {
                return implode(' | ', $messages);
            }
        }

        if (
            isset($responseData['output']['alerts']) &&
            is_array($responseData['output']['alerts'])
        ) {
            $messages = [];

            foreach ($responseData['output']['alerts'] as $alert) {
                if (!empty($alert['message'])) {
                    $messages[] = $alert['message'];
                }
            }

            if (!empty($messages)) {
                return implode(' | ', $messages);
            }
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
                    $errorMessage
                    ?? (
                        $success
                            ? null
                            : $this->extractErrorMessage(
                                $responseData ?? []
                            )
                    ),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to log FedEx quote', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Test connection to FedEx API.
     */
    public function testConnection(
        FedexConfiguration $config
    ): array {
        $token = $this->authService->getAccessToken($config);

        if ($token) {
            return [
                'success' => true,
                'message' => 'Successfully connected to FedEx API.',
            ];
        }

        return [
            'success' => false,
            'message' => 'Failed to connect. Please verify your credentials.',
        ];
    }

    /**
     * Convert a number into the string representation expected by
     * the successful FedEx request.
     *
     * 8      -> "8"
     * 8.5    -> "8.5"
     * 8.00   -> "8"
     */
    protected function formatFedexNumber($value): string
    {
        $number = (float) $value;

        if (floor($number) === $number) {
            return (string) (int) $number;
        }

        return rtrim(
            rtrim(
                number_format($number, 4, '.', ''),
                '0'
            ),
            '.'
        );
    }

    /**
     * Check whether a package dimension is positive.
     */
    protected function hasPositiveDimension($value): bool
    {
        return $value !== null
            && $value !== ''
            && is_numeric($value)
            && (float) $value > 0;
    }

    /**
     * Map legacy/old dropoff_type values to valid FedEx REST API
     * pickupType values.
     */
    protected function mapPickupType(?string $dropoffType): string
    {
        $dropoffType = strtoupper(
            trim((string) $dropoffType)
        );

        $validValues = [
            'USE_SCHEDULED_PICKUP',
            'CONTACT_FEDEX_TO_SCHEDULE',
            'DROPOFF_AT_FEDEX_LOCATION',
        ];

        if (in_array($dropoffType, $validValues, true)) {
            return $dropoffType;
        }

        /*
         * Legacy SOAP/API values.
         */
        $legacyMap = [
            'REGULAR_PICKUP'          => 'USE_SCHEDULED_PICKUP',
            'REQUEST_COURIER'         => 'CONTACT_FEDEX_TO_SCHEDULE',
            'DROP_BOX'                => 'DROPOFF_AT_FEDEX_LOCATION',
            'BUSINESS_SERVICE_CENTER' => 'DROPOFF_AT_FEDEX_LOCATION',
            'STATION'                 => 'DROPOFF_AT_FEDEX_LOCATION',
        ];

        if (isset($legacyMap[$dropoffType])) {
            return $legacyMap[$dropoffType];
        }

        return 'DROPOFF_AT_FEDEX_LOCATION';
    }
}