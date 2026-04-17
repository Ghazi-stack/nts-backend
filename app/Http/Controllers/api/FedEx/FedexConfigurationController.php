<?php

namespace App\Http\Controllers\api\FedEx;

use App\Http\Controllers\Controller;
use App\Models\ShippingMethod;
use App\Models\FedexConfiguration;
use App\Services\FedEx\FedExRateService;
use App\Services\FedEx\FedExAuthService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class FedexConfigurationController extends Controller
{
    protected FedExRateService $rateService;
    protected FedExAuthService $authService;

    public function __construct(FedExRateService $rateService, FedExAuthService $authService)
    {
        $this->rateService = $rateService;
        $this->authService = $authService;
    }

    /**
     * GET /api/admin/shipping/methods/{methodId}/fedex
     * Get FedEx configuration for a shipping method
     */
    public function show(int $methodId): JsonResponse
    {
        $method = ShippingMethod::findOrFail($methodId);
        $config = $method->fedexConfiguration;

        if (!$config) {
            return response()->json([
                'success' => true,
                'data'    => null,
                'message' => 'No FedEx configuration found. Please configure FedEx settings.',
            ]);
        }

        // Return config without sensitive fields (hidden in model)
        // But include the list of all available services for the checkboxes UI
        return response()->json([
            'success'           => true,
            'data'              => $config,
            'all_services'      => FedexConfiguration::allServiceCodes(),
            'enabled_services'  => $config->getEnabledServiceLabels(),
        ]);
    }

    /**
     * POST /api/admin/shipping/methods/{methodId}/fedex
     * Create or Update FedEx configuration
     *
     * ──── Tab 1: Settings ────
     * display_name, dropoff_type, packaging_type, packing_method,
     * rate_type, include_package_value, destination_type, enabled_services
     *
     * ──── Tab 2: Connection ────
     * client_id, client_secret, account_number, api_base_url,
     * ws_key, ws_password, meter_number
     *
     * ──── All fields can be sent together ────
     */
    public function storeOrUpdate(Request $request, int $methodId): JsonResponse
    {
        $method = ShippingMethod::findOrFail($methodId);

        $validated = $request->validate([
            // Connection
            'client_id'            => 'sometimes|required|string|max:255',
            'client_secret'        => 'sometimes|required|string|max:255',
            'account_number'       => 'sometimes|required|string|max:50',
            'api_base_url'         => 'sometimes|string|url|max:255',

            // Legacy WS credentials
            'ws_key'               => 'sometimes|nullable|string|max:255',
            'ws_password'          => 'sometimes|nullable|string|max:255',
            'meter_number'         => 'sometimes|nullable|string|max:50',

            // Settings
            'display_name'         => 'sometimes|string|max:255',
            'dropoff_type'         => 'sometimes|string|in:REGULAR_PICKUP,REQUEST_COURIER,DROP_BOX,BUSINESS_SERVICE_CENTER,STATION',
            'packaging_type'       => 'sometimes|string',
            'packing_method'       => 'sometimes|string|in:ALL_IN_ONE,INDIVIDUAL',
            'rate_type'            => 'sometimes|string|in:LIST,ACCOUNT,PREFERRED',
            'include_package_value' => 'sometimes|boolean',
            'destination_type'     => 'sometimes|string|in:COMMERCIAL,RESIDENTIAL',

            // Enabled services (checkboxes from BigCommerce UI)
            'enabled_services'     => 'sometimes|array',
            'enabled_services.*'   => 'string',
        ]);

        $config = FedexConfiguration::updateOrCreate(
            ['shipping_method_id' => $method->id],
            $validated
        );

        return response()->json([
            'success' => true,
            'message' => 'FedEx configuration saved successfully.',
            'data'    => $config->fresh(),
        ]);
    }

    /**
     * POST /api/admin/shipping/methods/{methodId}/fedex/test-connection
     * Test FedEx API connection (Connection tab — like BigCommerce "Connect" button)
     */
    public function testConnection(int $methodId): JsonResponse
    {
        $method = ShippingMethod::findOrFail($methodId);
        $config = $method->fedexConfiguration;

        if (!$config) {
            return response()->json([
                'success' => false,
                'message' => 'No FedEx configuration found. Please save credentials first.',
            ], 400);
        }

        // Clear any cached token to force fresh auth
        $this->authService->clearToken($config);

        $result = $this->rateService->testConnection($config);

        // Update is_connected status
        $config->update(['is_connected' => $result['success']]);

        return response()->json($result);
    }

    /**
     * POST /api/admin/shipping/methods/{methodId}/fedex/disconnect
     * Disconnect FedEx (like BigCommerce "Disconnect" button)
     */
    public function disconnect(int $methodId): JsonResponse
    {
        $method = ShippingMethod::findOrFail($methodId);
        $config = $method->fedexConfiguration;

        if ($config) {
            $this->authService->clearToken($config);
            $config->update(['is_connected' => false]);
        }

        return response()->json([
            'success' => true,
            'message' => 'FedEx disconnected successfully.',
        ]);
    }

    /**
     * POST /api/admin/shipping/methods/{methodId}/fedex/get-quote
     * Test quote from admin panel (Quote tab — like BigCommerce "Get quote" button)
     *
     * Request body:
     * {
     *   "destination_country": "US",
     *   "destination_state": "CA",
     *   "destination_zip": "90210",
     *   "package_weight": 5.00,
     *   "package_value": 100.00,
     *   "package_length": 12,
     *   "package_width": 8,
     *   "package_height": 6
     * }
     */
    public function getQuote(Request $request, int $methodId): JsonResponse
    {
        $method = ShippingMethod::findOrFail($methodId);
        $config = $method->fedexConfiguration;

        if (!$config || !$config->is_connected) {
            return response()->json([
                'success' => false,
                'message' => 'FedEx is not connected. Please connect first.',
            ], 400);
        }

        $validated = $request->validate([
            'destination_country' => 'required|string|max:5',
            'destination_state'   => 'sometimes|string|max:5',
            'destination_zip'     => 'sometimes|string|max:20',
            'package_weight'      => 'required|numeric|min:0.01',
            'package_value'       => 'sometimes|numeric|min:0',
            'package_length'      => 'sometimes|numeric|min:0',
            'package_width'       => 'sometimes|numeric|min:0',
            'package_height'      => 'sometimes|numeric|min:0',
        ]);

        $shipmentData = [
            'origin_postal_code'       => config('shipping.origin_postal_code', '33139'),
            'origin_country_code'      => config('shipping.origin_country_code', 'US'),
            'origin_state'             => config('shipping.origin_state', 'FL'),
            'origin_city'              => config('shipping.origin_city', 'Miami Beach'),
            'destination_postal_code'  => $validated['destination_zip'] ?? '',
            'destination_country_code' => $validated['destination_country'],
            'destination_state'        => $validated['destination_state'] ?? null,
            'total_weight'             => $validated['package_weight'],
            'weight_unit'              => 'LB',
            'package_length'           => $validated['package_length'] ?? null,
            'package_width'            => $validated['package_width'] ?? null,
            'package_height'           => $validated['package_height'] ?? null,
            'dimension_unit'           => 'IN',
            'package_value'            => $validated['package_value'] ?? null,
        ];

        $result = $this->rateService->getRates($config, $shipmentData);

        return response()->json($result);
    }

    /**
     * GET /api/admin/shipping/fedex/services
     * Get all available FedEx service codes (for the checkboxes UI)
     */
    public function availableServices(): JsonResponse
    {
        return response()->json([
            'success'  => true,
            'services' => FedexConfiguration::allServiceCodes(),
        ]);
    }

    /**
     * GET /api/admin/shipping/methods/{methodId}/fedex/quote-logs
     * View recent quote logs for debugging
     */
    public function quoteLogs(Request $request, int $methodId): JsonResponse
    {
        $method = ShippingMethod::findOrFail($methodId);
        $config = $method->fedexConfiguration;

        if (!$config) {
            return response()->json([
                'success' => true,
                'data'    => [],
            ]);
        }

        $logs = $config->quoteLogs()
            ->orderBy('created_at', 'desc')
            ->limit($request->input('limit', 20))
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $logs,
        ]);
    }
}
