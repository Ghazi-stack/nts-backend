<?php

namespace App\Http\Controllers\api\Admin;

use App\Http\Controllers\Controller;
use App\Services\PaymentSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PaymentSettingsController extends Controller
{
    protected PaymentSettingsService $paymentSettings;

    public function __construct(PaymentSettingsService $paymentSettings)
    {
        $this->paymentSettings = $paymentSettings;
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

    private function formatMethod($method): array
    {
        return [
            'id' => $method->id,
            'storeId' => $method->store_id,
            'methodCode' => $method->method_code,
            'category' => $method->category,
            'displayName' => $method->display_name,
            'isEnabled' => (bool) $method->is_enabled,
            'isConfigured' => (bool) $method->is_configured,
            'availableCountries' => $method->available_countries,
            'availableCurrencies' => $method->available_currencies,
            'configuration' => $this->keysToCamelCase(
                $method->configuration ?? []
            ),
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
            $currency = $request->query('currency');

            $currency = $currency !== null
                ? strtoupper(trim($currency))
                : null;

            $groups = $this->paymentSettings->getGroupedMethods(
                $this->getStoreId(),
                $currency
            );

            $data = [];

            foreach ($groups as $category => $methods) {
                $data[$category] = $methods
                    ->map(fn ($method) => $this->formatMethod($method))
                    ->values();
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
    public function show(string $methodCode)
    {
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
                'data' => $this->formatMethod($method),
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
            $saved = $this->paymentSettings->saveConfiguration(
                $storeId,
                $methodCode,
                $validator->validated()
            );

            return response()->json([
                'status' => true,
                'message' => 'Payment configuration saved successfully.',
                'data' => $this->formatMethod($saved),
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
    public function enable(string $methodCode)
    {
        try {
            $method = $this->paymentSettings->enableMethod(
                $this->getStoreId(),
                $methodCode
            );

            return response()->json([
                'status' => true,
                'message' => 'Payment method enabled successfully.',
                'data' => $this->formatMethod($method),
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
    public function disable(string $methodCode)
    {
        try {
            $method = $this->paymentSettings->disableMethod(
                $this->getStoreId(),
                $methodCode
            );

            return response()->json([
                'status' => true,
                'message' => 'Payment method disabled successfully.',
                'data' => $this->formatMethod($method),
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