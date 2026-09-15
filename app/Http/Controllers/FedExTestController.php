<?php

namespace App\Http\Controllers;

use App\Models\FedexConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class FedExTestController extends Controller
{
    public function rates(Request $request)
    {
        $testId = (string) Str::uuid();

        $validated = $request->validate([
            'client_id' => 'sometimes|nullable|string',
            'client_secret' => 'sometimes|nullable|string',

            'service_type' => 'required|string',
            'weight' => 'required|numeric|min:0.01',
            'weight_unit' => 'sometimes|string|in:LB,KG',

            'origin.postal_code' => 'required|string',
            'origin.country_code' => 'required|string|size:2',
            'origin.state' => 'sometimes|nullable|string',
            'origin.city' => 'sometimes|nullable|string',

            'destination.postal_code' => 'required|string',
            'destination.country_code' => 'required|string|size:2',
            'destination.state' => 'sometimes|nullable|string',
            'destination.city' => 'sometimes|nullable|string',
            'destination.residential' => 'sometimes|boolean',

            'package_length' => 'sometimes|nullable|numeric|min:0',
            'package_width' => 'sometimes|nullable|numeric|min:0',
            'package_height' => 'sometimes|nullable|numeric|min:0',
        ]);

        $config = FedexConfiguration::where('id', 2)
            ->first();

        if (!$config) {
            Log::error('FedEx test configuration not found', [
                'test_id' => $testId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'FedEx configuration not found.',
                'test_id' => $testId,
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Credentials
        |--------------------------------------------------------------------------
        |
        | If Postman sends client_id/client_secret, use those.
        | Otherwise use the decrypted values from FedexConfiguration.
        |
        */

        $clientId = $validated['client_id'] ?? $config->client_id;
        $clientSecret = $validated['client_secret'] ?? $config->client_secret;

        $usingPostmanCredentials =
            !empty($validated['client_id']) ||
            !empty($validated['client_secret']);

        $baseUrl = rtrim($config->api_base_url, '/');

        Log::info('FedEx credential isolation test starting', [
            'test_id' => $testId,
            'config_id' => $config->id,
            'api_base_url' => $baseUrl,
            'account_number' => $this->mask($config->account_number),
            'using_postman_credentials' => $usingPostmanCredentials,
            'client_id_present' => !empty($clientId),
            'client_secret_present' => !empty($clientSecret),
            'client_secret_length' => $clientSecret ? strlen($clientSecret) : 0,
        ]);

        /*
        |--------------------------------------------------------------------------
        | 1. OAuth
        |--------------------------------------------------------------------------
        */

        $oauthUrl = $baseUrl . '/oauth/token';

        Log::info('FedEx OAuth request starting', [
            'test_id' => $testId,
            'url' => $oauthUrl,
            'client_id' => $this->mask($clientId, 6),
            'client_secret_length' => $clientSecret ? strlen($clientSecret) : 0,
        ]);

        $oauthStarted = microtime(true);

        try {
            /*
             * FedEx OAuth expects form-urlencoded data.
             *
             * IMPORTANT:
             * We intentionally send client_id/client_secret in the
             * form body instead of HTTP Basic Auth for this isolation test.
             */

            $oauthResponse = Http::asForm()
                ->acceptJson()
                ->timeout(30)
                ->connectTimeout(15)
                ->post($oauthUrl, [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ]);

            $oauthDuration = round(microtime(true) - $oauthStarted, 3);

            $oauthRaw = $oauthResponse->body();
            $oauthJson = $oauthResponse->json();

            Log::info('FedEx OAuth response received', [
                'test_id' => $testId,
                'status' => $oauthResponse->status(),
                'successful' => $oauthResponse->successful(),
                'duration' => $oauthDuration,
                'content_type' => $oauthResponse->header('Content-Type'),
                'response' => $oauthJson,
                'raw_body' => $oauthJson === null ? $oauthRaw : null,
            ]);

            if (!$oauthResponse->successful()) {
                Log::error('FedEx OAuth failed', [
                    'test_id' => $testId,
                    'status' => $oauthResponse->status(),
                    'response' => $oauthJson,
                    'raw_body' => $oauthJson === null ? $oauthRaw : null,
                ]);

                return response()->json([
                    'success' => false,
                    'stage' => 'oauth',
                    'message' => 'FedEx OAuth authentication failed.',
                    'test_id' => $testId,
                    'fedex_status' => $oauthResponse->status(),
                    'fedex_response' => $oauthJson ?: $oauthRaw,
                ], 400);
            }

            $accessToken = $oauthJson['access_token'] ?? null;

            Log::info('FedEx OAuth token diagnostics', [
            'token_exists' => !empty($accessToken),
            'token_length' => $accessToken ? strlen($accessToken) : 0,
            'token_prefix' => $accessToken ? substr($accessToken, 0, 20) : null,
            'token_suffix' => $accessToken ? substr($accessToken, -20) : null,
            'token_type' => $oauthResponse->json('token_type'),
            'scope' => $oauthResponse->json('scope'),
        ]);

            if (!$accessToken) {
                Log::error('FedEx OAuth succeeded but access token missing', [
                    'test_id' => $testId,
                    'response' => $oauthJson,
                ]);

                return response()->json([
                    'success' => false,
                    'stage' => 'oauth',
                    'message' => 'FedEx OAuth succeeded but no access token was returned.',
                    'test_id' => $testId,
                ], 502);
            }

            Log::info('FedEx OAuth succeeded', [
                'test_id' => $testId,
                'expires_in' => $oauthJson['expires_in'] ?? null,
                'token_type' => $oauthJson['token_type'] ?? null,
                'access_token_length' => strlen($accessToken),
            ]);
        } catch (Throwable $e) {
            Log::error('FedEx OAuth exception', [
                'test_id' => $testId,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'stage' => 'oauth',
                'message' => 'FedEx OAuth request failed.',
                'test_id' => $testId,
                'error' => $e->getMessage(),
            ], 502);
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Build Rate Request
        |--------------------------------------------------------------------------
        */

        $weightUnit = $validated['weight_unit'] ?? 'LB';

        $shipperAddress = [
            'postalCode' => $validated['origin']['postal_code'],
            'countryCode' => strtoupper($validated['origin']['country_code']),
        ];

        if (!empty($validated['origin']['state'])) {
            $shipperAddress['stateOrProvinceCode'] =
                $validated['origin']['state'];
        }

        if (!empty($validated['origin']['city'])) {
            $shipperAddress['city'] =
                $validated['origin']['city'];
        }

        $recipientAddress = [
            'postalCode' => $validated['destination']['postal_code'],
            'countryCode' => strtoupper($validated['destination']['country_code']),
            'residential' => (bool) ($validated['destination']['residential'] ?? false),
        ];

        if (!empty($validated['destination']['state'])) {
            $recipientAddress['stateOrProvinceCode'] =
                $validated['destination']['state'];
        }

        if (!empty($validated['destination']['city'])) {
            $recipientAddress['city'] =
                $validated['destination']['city'];
        }

        $package = [
            'weight' => [
                'units' => $weightUnit,
                'value' => (string) $validated['weight'],
            ],
        ];

        /*
         * Only include dimensions when all three dimensions exist.
         */
        if (
            isset($validated['package_length']) &&
            isset($validated['package_width']) &&
            isset($validated['package_height']) &&
            $validated['package_length'] !== null &&
            $validated['package_width'] !== null &&
            $validated['package_height'] !== null
        ) {
            $package['dimensions'] = [
                'length' => (string) $validated['package_length'],
                'width' => (string) $validated['package_width'],
                'height' => (string) $validated['package_height'],
                'units' => 'IN',
            ];
        }

        $payload = [
            'accountNumber' => [
                'value' => $config->account_number,
            ],

            'requestedShipment' => [
                'shipper' => [
                    'address' => [
                        'postalCode' => '91723',
                        'countryCode' => 'US',
                    ],
                ],

                'recipient' => [
                    'address' => [
                        'postalCode' => '90001',
                        'countryCode' => 'US',
                        'residential' => 'true',
                    ],
                ],

                'serviceType' => 'GROUND_HOME_DELIVERY',

                'pickupType' => 'DROPOFF_AT_FEDEX_LOCATION',

                'rateRequestType' => [
                    'LIST',
                ],

                'requestedPackageLineItems' => [
                    [
                        'weight' => [
                            'units' => 'LB',
                            'value' => '8',
                        ],
                    ],
                ],
            ],
        ];

        Log::info('FedEx rate request payload prepared', [
            'test_id' => $testId,
            'payload' => $this->maskPayload($payload),
        ]);

        /*
        |--------------------------------------------------------------------------
        | 3. Rate Request
        |--------------------------------------------------------------------------
        */

        $rateUrl = $baseUrl . '/rate/v1/rates/quotes';

        Log::info('FedEx rate request starting', [
            'test_id' => $testId,
            'url' => $rateUrl,
            'service_type' => $validated['service_type'],
            'weight' => $validated['weight'],
            'weight_unit' => $weightUnit,
        ]);

        $rateStarted = microtime(true);

        try {
            $rateResponse = Http::withToken($accessToken)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-locale' => 'en_US',
                    'x-customer-transaction-id' => 'test_' . $testId,
                ])
                ->timeout(60)
                ->connectTimeout(15)
                ->post($rateUrl, $payload);

            $rateDuration = round(microtime(true) - $rateStarted, 3);

            $rateRaw = $rateResponse->body();
            $rateJson = $rateResponse->json();

            Log::info('FedEx rate response received', [
                'test_id' => $testId,
                'status' => $rateResponse->status(),
                'successful' => $rateResponse->successful(),
                'duration' => $rateDuration,
                'content_type' => $rateResponse->header('Content-Type'),
                'response' => $rateJson,
                'raw_body' => $rateJson === null ? $rateRaw : null,
            ]);

            return response()->json([
                'success' => $rateResponse->successful(),
                'test_id' => $testId,
                'oauth' => [
                    'success' => true,
                    'expires_in' => $oauthJson['expires_in'] ?? null,
                    'token_type' => $oauthJson['token_type'] ?? null,
                ],
                'rate_request' => [
                    'url' => $rateUrl,
                    'payload' => $this->maskPayload($payload),
                ],
                'fedex_status' => $rateResponse->status(),
                'rates_response' => $rateJson ?: $rateRaw,
            ], $rateResponse->successful() ? 200 : 502);

        } catch (Throwable $e) {
            Log::error('FedEx rate request exception', [
                'test_id' => $testId,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'stage' => 'rate',
                'message' => 'FedEx rate request failed.',
                'test_id' => $testId,
                'error' => $e->getMessage(),
            ], 502);
        }
    }

    /**
     * Convert the application's pickup/dropoff value into
     * a FedEx Rate API pickupType.
     */
    protected function mapPickupType(?string $value): string
    {
        return match (strtoupper(trim((string) $value))) {
            'USE_SCHEDULED_PICKUP' =>
                'USE_SCHEDULED_PICKUP',

            'CONTACT_FEDEX_TO_SCHEDULE' =>
                'CONTACT_FEDEX_TO_SCHEDULE',

            'DROPOFF_AT_FEDEX_LOCATION' =>
                'DROPOFF_AT_FEDEX_LOCATION',

            /*
             * Your current DB value is DROP_BOX.
             * For this test, map it to the valid FedEx dropoff value.
             */
            'DROP_BOX' =>
                'DROPOFF_AT_FEDEX_LOCATION',

            default =>
                'DROPOFF_AT_FEDEX_LOCATION',
        };
    }

    /**
     * Mask sensitive values before logging/returning payloads.
     */
    protected function maskPayload(array $payload): array
    {
        $copy = $payload;

        if (isset($copy['accountNumber']['value'])) {
            $copy['accountNumber']['value'] =
                $this->mask($copy['accountNumber']['value']);
        }

        return $copy;
    }

    protected function mask(?string $value, int $visible = 4): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $length = strlen($value);

        if ($length <= $visible) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - $visible)
            . substr($value, -$visible);
    }
}