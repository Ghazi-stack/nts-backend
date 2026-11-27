<?php

namespace App\Http\Controllers\api\Admin;

use App\Http\Controllers\Controller;
use App\Services\PaymentSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Services\StripeAccountDisconnector;

class PaymentSettingsController extends Controller
{
    protected PaymentSettingsService $paymentSettings;

    protected StripeAccountDisconnector $stripeDisconnector;

    public function __construct(PaymentSettingsService $paymentSettings, StripeAccountDisconnector $stripeDisconnector)
    {
        $this->paymentSettings = $paymentSettings;
        $this->stripeDisconnector = $stripeDisconnector;
    }

    private function keysToSnakeCase(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $newKey = is_string($key) ? Str::snake($key) : $key;

            $result[$newKey] = is_array($value)
                ? $this->keysToSnakeCase($value)
                : $value;
        }

        return $result;
    }

    private function keysToCamelCase(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $newKey = is_string($key) ? Str::camel($key) : $key;

            $result[$newKey] = is_array($value)
                ? $this->keysToCamelCase($value)
                : $value;
        }

        return $result;
    }

    private function getStoreId(): int
    {
        $storeId = current_store_id();

        if (!$storeId) {
            abort(response()->json([
                'status' => false,
                'message' => 'Store not selected or token invalid.',
            ], 400));
        }

        return (int) $storeId;
    }

    private function generateStripeAuthorizationUrls(int $storeId): array
    {
        $store = DB::connection('central')
            ->table('stores')
            ->where('legacy_store_id', $storeId)
            ->where('provisioning_status', 'active')
            ->first();

        if (!$store) {
            return [];
        }

        $connectUrls = [];

        foreach (['sandbox', 'live'] as $environment) {
            $environmentConfig = config(
                "services.stripe.connect.{$environment}"
            );

            $clientId = $environmentConfig['client_id'] ?? null;
            $redirectUri = $environmentConfig['redirect_uri'] ?? null;

            // Skip environments that are not configured.
            if (empty($clientId) || empty($redirectUri)) {
                continue;
            }

            $state = Str::random(64);

            Cache::put(
                'stripe_connect_state:' . hash('sha256', $state),
                [
                    'store_hash' => $store->store_hash,
                    'environment' => $environment,
                    'created_at' => now()->timestamp,
                ],
                now()->addMinutes(10)
            );

            $params = [
                'response_type' => 'code',
                'client_id' => $clientId,
                'scope' => 'read_write',
                'redirect_uri' => $redirectUri,
                'state' => $state,
            ];

            if (!empty($store->name)) {
                $params['stripe_user[business_name]'] = $store->name;
            }

            if (!empty($store->base_url)) {
                $params['stripe_user[url]'] = $store->base_url;
            }

            $connectUrls[$environment] =
                'https://connect.stripe.com/oauth/authorize?' .
                http_build_query($params);
        }

        return $connectUrls;
    }


    // private function formatMethod($method): array
    // {
    //     return [
    //         'id' => $method->id,
    //         'storeId' => $method->store_id,
    //         'methodCode' => $method->method_code,
    //         'category' => $method->category,
    //         'displayName' => $method->display_name,
    //         'isEnabled' => (bool) $method->is_enabled,
    //         'isConfigured' => (bool) $method->is_configured,
    //         'availableCountries' => $method->available_countries,
    //         'availableCurrencies' => $method->available_currencies,
    //         'configuration' => $this->keysToCamelCase(
    //             $method->configuration ?? []
    //         ),
    //         'hasCredentials' => !empty($method->credentials),
    //         'createdAt' => $method->created_at,
    //         'updatedAt' => $method->updated_at,
    //     ];
    // }

    
    // private function formatMethod($method): array
    // {
    //     return [
    //         'id' => $method->id,
    //         'storeId' => $method->store_id,
    //         'methodCode' => $method->method_code,
    //         'category' => $method->category,
    //         'displayName' => $method->display_name,
    //         'isEnabled' => (bool) $method->is_enabled,
    //         'isConfigured' => (bool) $method->is_configured,
    //         'availableCountries' => $method->available_countries,
    //         'availableCurrencies' => $method->available_currencies,
    //         'configuration' => $this->keysToCamelCase(
    //             $method->configuration ?? []
    //         ),
    //         'hasCredentials' => !empty($method->credentials),
    //         'createdAt' => $method->created_at,
    //         'updatedAt' => $method->updated_at,
    //     ];
    // }


    private function formatMethod($method, ?string $currency = null): array
    {
        $effective = $this->paymentSettings->getEffectiveSettings(
            $method,
            $currency
        );
        $configuration = $effective['configuration'];

        // Include all existing Stripe defaults and saved settings
        // in the same configuration object.
        if ($method->method_code === 'stripe') {
            $configuration = array_replace_recursive(
                config('stripe_payment.defaults', []),
                $configuration
            );
        }

        return [
            'id' => $method->id,
            'storeId' => $method->store_id,
            'methodCode' => $method->method_code,
            'category' => $method->category,
            'displayName' => $method->display_name,
            'isEnabled' => $effective['isEnabled'],
            'isConfigured' => $effective['isConfigured'],
            'availableCountries' => $method->available_countries,
            'availableCurrencies' => $method->available_currencies,
            'configuration' => $this->keysToCamelCase($configuration),
            'hasCredentials' => !empty($method->credentials),
            'createdAt' => $method->created_at,
            'updatedAt' => $method->updated_at,
        ];
    }

    
    private function validationErrorResponse($validator)
    {
        $errors = [];

        foreach ($validator->errors()->messages() as $field => $messages) {
            $camelField = implode('.', array_map(
                fn ($part) => ctype_digit((string) $part)
                    ? $part
                    : Str::camel($part),
                explode('.', $field)
            ));

            $errors[$camelField] = $messages;
        }

        return response()->json([
            'status' => false,
            'message' => 'Validation failed.',
            'errors' => $errors,
        ], 422);
    }

    /**
     * GET /payment-settings
     * All methods, grouped by category.
     */
    // public function index()
    // {
    //     try {
    //         $groups = $this->paymentSettings->getGroupedMethods(
    //             $this->getStoreId()
    //         );

    //         $data = [];

    //         foreach ($groups as $category => $methods) {
    //             $data[$category] = $methods
    //                 ->map(fn ($method) => $this->formatMethod($method))
    //                 ->values();
    //         }

    //         return response()->json([
    //             'status' => true,
    //             'message' => 'Payment methods retrieved successfully.',
    //             'data' => $data,
    //         ]);
    //     } catch (Throwable $e) {
    //         report($e);

    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Unable to retrieve payment methods.',
    //         ], 500);
    //     }
    // }

    
    
/**
 * GET /payment-settings
 * Payment methods grouped by category and filtered by currency.
 */
    public function index(Request $request)
    {
        $validator = Validator::make($request->query(), [
            'currency' => [
                'nullable',
                'string',
                'size:3',
                'alpha',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $storeId = $this->getStoreId();

            $currency = $request->query('currency');

            $currency = $currency !== null
                ? strtoupper(trim($currency))
                : null;

            $groups = $this->paymentSettings->getGroupedMethods(
                $storeId,
                $currency
            );

            $data = [];

            foreach ($groups as $category => $methods) {
                $data[$category] = $methods
                    ->map(fn ($method) => $this->formatMethod($method, $currency))
                    ->values()
                    ->all();
            }

            // Add actual Stripe OAuth URLs when Stripe is not configured.
            if (isset($data['online'])) {
                foreach ($data['online'] as $index => $method) {
                    if (
                        $method['methodCode'] === 'stripe' &&
                        !$method['isConfigured'] &&
                        (!$currency || !$method['hasCredentials'])
                    ) {
                        $data['online'][$index]['connectUrls'] =
                            $this->generateStripeAuthorizationUrls($storeId);

                        break;
                    }
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Payment methods retrieved successfully.',
                'data' => $data,
            ]);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Unable to retrieve payment methods.',
            ], 500);
        }
    }

    /**
     * GET /payment-settings/catalog
     * Static setup definitions; not a statement of live availability.
     */
    public function catalog()
    {
        try {
            return response()->json([
                'status' => true,
                'message' => 'Payment catalog retrieved successfully.',
                'data' => $this->paymentSettings->getCatalog(),
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Unable to retrieve payment catalog.',
            ], 500);
        }
    }

    /**
     * GET /payment-settings/{methodCode}/setup
     */
    public function setup(string $methodCode)
    {
        $definition = $this->paymentSettings->getCatalogMethod($methodCode);

        if (!$definition) {
            return response()->json([
                'status' => false,
                'message' => 'Payment method setup not found.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Payment method setup retrieved successfully.',
            'data' => $definition,
        ]);
    }

    /**
     * GET /payment-settings/{methodCode}/providers
     */
    public function providers(string $methodCode)
    {
        try {
            $providers = $this->paymentSettings->getWalletProviders(
                $this->getStoreId(),
                $methodCode
            );

            return response()->json([
                'status' => true,
                'message' => 'Wallet providers retrieved successfully.',
                'data' => $providers,
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 404);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Unable to retrieve wallet providers.',
            ], 500);
        }
    }

    /**
     * GET /payment-settings/{methodCode}
     */
    public function show(Request $request, string $methodCode)
    {
        $validated = Validator::make($request->query(), [
            'currency' => ['nullable', 'string', 'size:3', 'alpha'],
        ]);

        if ($validated->fails()) {
            return $this->validationErrorResponse($validated);
        }

        $currency = $request->query('currency');
        $currency = $currency !== null ? strtoupper(trim($currency)) : null;

        try {
            $method = $this->paymentSettings->getMethod(
                $this->getStoreId(),
                $methodCode
            );

            if (!$method) {
                return response()->json([
                    'status' => false,
                    'message' => 'Payment method not found.',
                ], 404);
            }

            return response()->json([
                'status' => true,
                'message' => 'Payment method retrieved successfully.',
                'data' => $this->formatMethod($method, $currency),
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Unable to retrieve payment method.',
            ], 500);
        }
    }

    /**
     * POST /payment-settings/{methodCode}
     */
    // public function save(Request $request, string $methodCode)
    // {
    //     $storeId = $this->getStoreId();

    //     $method = $this->paymentSettings->getMethod(
    //         $storeId,
    //         $methodCode
    //     );

    //     if (!$method) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Payment method not found.',
    //         ], 404);
    //     }

    //     $normalizedData = $this->keysToSnakeCase($request->all());

    //     $validator = Validator::make($normalizedData, [
    //         'display_name' => 'sometimes|required|string|max:150',

    //         // Supports:
    //         // { "allCountries": true, "countries": [] }
    //         'available_countries' => 'sometimes|nullable|array',
    //         'available_countries.all_countries' => 'sometimes|boolean',
    //         'available_countries.countries' => 'sometimes|array',
    //         'available_countries.countries.*' => [
    //             'string',
    //             'size:2',
    //             'alpha',
    //         ],

    //         'configuration' => 'sometimes|nullable|array',

    //         'credentials' => 'sometimes|nullable|array',
    //         'credentials.*' => 'nullable|string|max:10000',
    //     ]);

    //     if ($validator->fails()) {
    //         return $this->validationErrorResponse($validator);
    //     }

    //     try {
    //         $saved = $this->paymentSettings->saveConfiguration(
    //             $storeId,
    //             $methodCode,
    //             $validator->validated()
    //         );

    //         return response()->json([
    //             'status' => true,
    //             'message' => 'Payment configuration saved successfully.',
    //             'data' => $this->formatMethod($saved),
    //         ]);
    //     } catch (Throwable $e) {
    //         report($e);

    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Unable to save payment configuration.',
    //         ], 500);
    //     }
    // }

    
    /**
 * POST /payment-settings/{methodCode}
 */
    public function save(Request $request, string $methodCode)
    {
        $storeId = $this->getStoreId();

        $method = $this->paymentSettings->getMethod(
            $storeId,
            $methodCode
        );

        if (!$method) {
            return response()->json([
                'status' => false,
                'message' => 'Payment method not found.',
            ], 404);
        }

        $normalizedData = $this->keysToSnakeCase($request->all());

        $validator = Validator::make($normalizedData, [
            'currency' => 'sometimes|nullable|string|size:3|alpha',
            'display_name' => 'sometimes|required|string|max:150',

            // Existing country settings
            'available_countries' => 'sometimes|nullable|array',
            'available_countries.all_countries' => 'sometimes|boolean',
            'available_countries.countries' => 'sometimes|array',
            'available_countries.countries.*' => [
                'string',
                'size:2',
                'alpha',
            ],

            // Supported currencies: USD, PKR, EUR, GBP, etc.
            'available_currencies' => 'sometimes|nullable|array',
            'available_currencies.*' => [
                'string',
                'size:3',
                'alpha',
            ],

            'configuration' => 'sometimes|nullable|array',

            'credentials' => 'sometimes|nullable|array',
            'credentials.*' => 'nullable|string|max:10000',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            $validated = $validator->validated();
            $currency = $validated['currency'] ?? null;
            unset($validated['currency']);

            if ($currency !== null) {
                if (array_key_exists('credentials', $validated)) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Provider credentials are store-level and cannot be changed in a currency-specific update.',
                        'errors' => [
                            'credentials' => ['Use the existing store-level credential configuration operation.'],
                        ],
                    ], 422);
                }

                if (!array_key_exists('configuration', $validated)
                    || !is_array($validated['configuration'])) {
                    return response()->json([
                        'status' => false,
                        'message' => 'A configuration object is required for a currency-specific update.',
                        'errors' => [
                            'configuration' => ['The configuration field is required when currency is provided.'],
                        ],
                    ], 422);
                }

                $currency = strtoupper(trim($currency));
                $saved = $this->paymentSettings->saveCurrencyConfiguration(
                    $storeId,
                    $methodCode,
                    $currency,
                    $validated['configuration']
                );
            } else {
                $saved = $this->paymentSettings->saveConfiguration(
                    $storeId,
                    $methodCode,
                    $validated
                );
            }

            return response()->json([
                'status' => true,
                'message' => 'Payment configuration saved successfully.',
                'data' => $this->formatMethod($saved, $currency),
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Unable to save payment configuration.',
            ], 500);
        }
    }

    /**
     * PATCH /payment-settings/{methodCode}/enable
     */
    public function enable(Request $request, string $methodCode)
    {
        $validated = Validator::make($request->all(), [
            'currency' => ['nullable', 'string', 'size:3', 'alpha'],
        ]);

        if ($validated->fails()) {
            return $this->validationErrorResponse($validated);
        }

        $currency = $request->input('currency');
        $currency = $currency !== null ? strtoupper(trim($currency)) : null;

        try {
            $method = $currency !== null
                ? $this->paymentSettings->enableCurrency(
                    $this->getStoreId(),
                    $methodCode,
                    $currency
                )
                : $this->paymentSettings->enableMethod(
                    $this->getStoreId(),
                    $methodCode
                );

            return response()->json([
                'status' => true,
                'message' => 'Payment method enabled successfully.',
                'data' => $this->formatMethod($method, $currency),
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Unable to enable payment method.',
            ], 500);
        }
    }

    /**
     * PATCH /payment-settings/{methodCode}/disable
     */
    // public function disable(string $methodCode)
    // {
    //     try {
    //         $method = $this->paymentSettings->disableMethod(
    //             $this->getStoreId(),
    //             $methodCode
    //         );

    //         return response()->json([
    //             'status' => true,
    //             'message' => 'Payment method disabled successfully.',
    //             'data' => $this->formatMethod($method),
    //         ]);
    //     } catch (RuntimeException $e) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => $e->getMessage(),
    //         ], 404);
    //     } catch (Throwable $e) {
    //         report($e);

    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Unable to disable payment method.',
    //         ], 500);
    //     }
    // }

    
    public function disable(Request $request, string $methodCode)
    {
        $validated = Validator::make($request->all(), [
            'currency' => ['nullable', 'string', 'size:3', 'alpha'],
        ]);

        if ($validated->fails()) {
            return $this->validationErrorResponse($validated);
        }

        $currency = $request->input('currency');
        $currency = $currency !== null ? strtoupper(trim($currency)) : null;

        try {
            $storeId = $this->getStoreId();

            $method = $this->paymentSettings->getMethod(
                $storeId,
                $methodCode
            );

            if (!$method) {
                return response()->json([
                    'status' => false,
                    'message' => 'Payment method not found.',
                ], 404);
            }

            if ($currency !== null) {
                // Currency-specific Stripe disablement must retain the shared
                // OAuth connection and only clear this currency's settings.
                $method = $this->paymentSettings->disableCurrency(
                    $storeId,
                    $methodCode,
                    $currency
                );
            // Stripe requires OAuth revocation for a global disable.
            } elseif ($methodCode === 'stripe') {
                if ($method->category !== 'online') {
                    throw new RuntimeException(
                        'Stripe must be configured as an online payment method.'
                    );
                }

                $this->stripeDisconnector->disconnectForStoreId($storeId);

                // The disconnector clears the Stripe record and disconnects
                // the tenant database connection. Update the loaded model
                // for the response without querying the database again.
                $method->configuration = null;
                $method->credentials = null;
                $method->is_enabled = false;
                $method->is_configured = false;
            } else {
                $method = $this->paymentSettings->disableMethod(
                    $storeId,
                    $methodCode
                );
            }

            return response()->json([
                'status' => true,
                'message' => 'Payment method disabled successfully.',
                'data' => $this->formatMethod($method, $currency),
            ]);

        } catch (RuntimeException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 422);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Unable to disable payment method.',
            ], 500);
        }
    }

    /**
     * PATCH /payment-settings/{methodCode}/providers/{providerCode}/enable
     */
    public function enableProvider(
        string $methodCode,
        string $providerCode
    ) {
        try {
            $provider = $this->paymentSettings->enableWalletProvider(
                $this->getStoreId(),
                $methodCode,
                $providerCode
            );

            return response()->json([
                'status' => true,
                'message' => 'Wallet provider enabled successfully.',
                'data' => [
                    'methodCode' => $provider->method_code,
                    'providerCode' => $provider->provider_code,
                    'isEnabled' => (bool) $provider->is_enabled,
                    'isAvailable' => (bool) $provider->is_available,
                ],
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Unable to enable wallet provider.',
            ], 500);
        }
    }

    /**
     * PATCH /payment-settings/{methodCode}/providers/{providerCode}/disable
     */
    public function disableProvider(
        string $methodCode,
        string $providerCode
    ) {
        try {
            $provider = $this->paymentSettings->disableWalletProvider(
                $this->getStoreId(),
                $methodCode,
                $providerCode
            );

            return response()->json([
                'status' => true,
                'message' => 'Wallet provider disabled successfully.',
                'data' => [
                    'methodCode' => $provider->method_code,
                    'providerCode' => $provider->provider_code,
                    'isEnabled' => (bool) $provider->is_enabled,
                    'isAvailable' => (bool) $provider->is_available,
                ],
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 404);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Unable to disable wallet provider.',
            ], 500);
        }
    }
}
