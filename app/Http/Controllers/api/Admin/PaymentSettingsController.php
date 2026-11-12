<?php

namespace App\Http\Controllers\api\Admin;

use App\Http\Controllers\Controller;
use App\Services\PaymentSettingsService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;
use Throwable;

class PaymentSettingsController extends Controller
{
    protected PaymentSettingsService $paymentSettings;

    public function __construct(
        PaymentSettingsService $paymentSettings
    ) {
        $this->paymentSettings = $paymentSettings;
    }

    /**
     * Resolve authenticated store.
     */
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

    /**
     * Safe payment method response.
     * Credentials are never returned.
     */
    private function formatMethod($method): array
    {
        $configuration = $method->configuration ?? [];

        return [
            'id' => $method->id,
            'store_id' => $method->store_id,
            'method_code' => $method->method_code,
            'category' => $method->category,
            'display_name' => $method->display_name,
            'is_enabled' => (bool) $method->is_enabled,
            'is_configured' => (bool) $method->is_configured,
            'available_countries' => $method->available_countries,
            'configuration' => $configuration,

            // Never expose credentials.
            'has_credentials' => !empty($method->credentials),

            'created_at' => $method->created_at,
            'updated_at' => $method->updated_at,
        ];
    }

    /**
     * GET: List all payment methods.
     */
    public function index()
    {
        try {
            $storeId = $this->getStoreId();

            $methods = $this->paymentSettings
                ->getMethods($storeId);

            return response()->json([
                'status' => true,
                'message' => 'Payment methods retrieved successfully.',
                'data' => $methods->map(
                    fn ($method) => $this->formatMethod($method)
                ),
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
     * GET: Retrieve one payment method.
     */
    public function show(string $methodCode)
    {
        try {
            $storeId = $this->getStoreId();

            $method = $this->paymentSettings
                ->getMethod($storeId, $methodCode);

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
     * POST: Save or update payment method configuration.
     */
    public function save(Request $request, string $methodCode)
    {
        $storeId = $this->getStoreId();

        $method = $this->paymentSettings
            ->getMethod($storeId, $methodCode);

        if (!$method) {
            return response()->json([
                'status' => false,
                'message' => 'Payment method not found.',
            ], 404);
        }

        $validated = $request->validate([
            'display_name' => 'sometimes|required|string|max:150',

            'available_countries' => 'sometimes|nullable|array',

            'available_countries.*' => 'string|max:3',

            'configuration' => 'sometimes|nullable|array',

            'credentials' => 'sometimes|nullable|array',

            'credentials.*' => 'nullable|string|max:10000',
        ]);

        try {
            $saved = $this->paymentSettings->saveConfiguration(
                $storeId,
                $methodCode,
                $validated
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
     * PATCH: Enable payment method.
     */
    public function enable(string $methodCode)
    {
        try {
            $storeId = $this->getStoreId();

            $method = $this->paymentSettings
                ->enableMethod($storeId, $methodCode);

            return response()->json([
                'status' => true,
                'message' => 'Payment method enabled successfully.',
                'data' => $this->formatMethod($method),
            ]);

        } catch (\RuntimeException $e) {
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
     * PATCH: Disable payment method.
     */
    public function disable(string $methodCode)
    {
        try {
            $storeId = $this->getStoreId();

            $method = $this->paymentSettings
                ->disableMethod($storeId, $methodCode);

            return response()->json([
                'status' => true,
                'message' => 'Payment method disabled successfully.',
                'data' => $this->formatMethod($method),
            ]);

        } catch (\RuntimeException $e) {
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
}