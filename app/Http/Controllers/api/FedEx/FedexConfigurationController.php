<?php

namespace App\Http\Controllers\API\FedEx;

use App\Http\Controllers\Controller;
use App\Models\ShippingMethod;
use App\Models\FedexConfiguration;
use App\Models\StoreShippingAddress;
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

    public function show(int $methodId): JsonResponse
    {
        $method = ShippingMethod::findOrFail($methodId);
        $config = $method->fedexConfiguration;
        if (!$config) return response()->json(['success' => true, 'data' => null, 'message' => 'No FedEx configuration found.']);

        return response()->json([
            'success' => true,
            'data' => $config,
            'all_services' => FedexConfiguration::allServiceCodes(),
            'pickup_types' => FedexConfiguration::validPickupTypes(),
            'enabled_services' => $config->getEnabledServiceLabels(),
        ]);
    }

    public function storeOrUpdate(Request $request, int $methodId): JsonResponse
    {
        $method = ShippingMethod::findOrFail($methodId);
        $validated = $request->validate([
            'client_id' => 'sometimes|required|string|max:255',
            'client_secret' => 'sometimes|required|string|max:255',
            'account_number' => 'sometimes|required|string|max:50',
            'api_base_url' => 'sometimes|string|url|max:255',
            'ws_key' => 'sometimes|nullable|string|max:255',
            'ws_password' => 'sometimes|nullable|string|max:255',
            'meter_number' => 'sometimes|nullable|string|max:50',
            'display_name' => 'sometimes|string|max:255',
            'dropoff_type' => 'sometimes|string|max:255',
            'packaging_type' => 'sometimes|string',
            'packing_method' => 'sometimes|string|in:ALL_IN_ONE,INDIVIDUAL',
            'rate_type' => 'sometimes|string|in:LIST,ACCOUNT,PREFERRED',
            'include_package_value' => 'sometimes|boolean',
            'destination_type' => 'sometimes|string|in:COMMERCIAL,RESIDENTIAL',
            'enabled_services' => 'sometimes|array',
            'enabled_services.*' => 'string',
        ]);
        $config = FedexConfiguration::updateOrCreate(['shipping_method_id' => $method->id], $validated);
        return response()->json(['success' => true, 'message' => 'FedEx configuration saved successfully.', 'data' => $config->fresh()]);
    }

    public function testConnection(int $methodId): JsonResponse
    {
        $method = ShippingMethod::findOrFail($methodId);
        $config = $method->fedexConfiguration;
        if (!$config) return response()->json(['success' => false, 'message' => 'No FedEx configuration found.'], 400);

        $this->authService->clearToken($config);
        $result = $this->rateService->testConnection($config);
        $config->update(['is_connected' => $result['success']]);
        return response()->json($result);
    }

    public function disconnect(int $methodId): JsonResponse
    {
        $method = ShippingMethod::findOrFail($methodId);
        $config = $method->fedexConfiguration;
        if ($config) {
            $this->authService->clearToken($config);
            $config->update(['is_connected' => false]);
        }
        return response()->json(['success' => true, 'message' => 'FedEx disconnected successfully.']);
    }

    /**
     * GET + POST dono support
     * GET  /fedex-config/get-quote/3?destination_country=US&destination_state=CA&destination_zip=90210&package_weight=1
     * POST /fedex-config/get-quote/3  {body}
     *
     * Origin address database (store_shipping_address) se aata hai
     */
    public function getQuote(Request $request, int $methodId): JsonResponse
    {
        $method = ShippingMethod::findOrFail($methodId);
        $config = $method->fedexConfiguration;
        if (!$config || !$config->is_connected) return response()->json(['success' => false, 'message' => 'FedEx is not connected.'], 400);

        $validated = $request->validate([
            'destination_country' => 'required|string|max:5',
            'destination_state' => 'sometimes|string|max:5',
            'destination_zip' => 'sometimes|string|max:20',
            'package_weight' => 'required|numeric|min:0.01',
            'package_value' => 'sometimes|numeric|min:0',
            'package_length' => 'sometimes|numeric|min:0',
            'package_width' => 'sometimes|numeric|min:0',
            'package_height' => 'sometimes|numeric|min:0',
        ]);

        // Origin from database pickup address
        $storeAddress = StoreShippingAddress::getActive();
        $originPostal = $storeAddress ? $storeAddress->postal_code : config('shipping.origin_postal_code', '91723');
        $originCountry = $storeAddress ? $storeAddress->country_code : config('shipping.origin_country_code', 'US');
        $originState = $storeAddress ? $storeAddress->state_code : config('shipping.origin_state', 'CA');
        $originCity = $storeAddress ? $storeAddress->city : config('shipping.origin_city', 'Covina');

        $shipmentData = [
            'origin_postal_code'       => $originPostal,
            'origin_country_code'      => $originCountry,
            'origin_state'             => $originState,
            'origin_city'              => $originCity,
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

        return response()->json($this->rateService->getRates($config, $shipmentData));
    }

    public function availableServices(): JsonResponse
    {
        return response()->json(['success' => true, 'services' => FedexConfiguration::allServiceCodes(), 'pickup_types' => FedexConfiguration::validPickupTypes()]);
    }

    public function quoteLogs(Request $request, int $methodId): JsonResponse
    {
        $method = ShippingMethod::findOrFail($methodId);
        $config = $method->fedexConfiguration;
        if (!$config) return response()->json(['success' => true, 'data' => []]);
        $logs = $config->quoteLogs()->orderBy('created_at', 'desc')->limit($request->input('limit', 20))->get();
        return response()->json(['success' => true, 'data' => $logs]);
    }
}
