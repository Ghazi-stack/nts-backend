<?php

namespace App\Http\Controllers\API\FedEx;

use App\Http\Controllers\Controller;
use App\Services\FedEx\ShippingRateService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CheckoutShippingController extends Controller
{
    protected ShippingRateService $shippingRateService;

    public function __construct(ShippingRateService $shippingRateService)
    {
        $this->shippingRateService = $shippingRateService;
    }

    /**
     * POST + GET /checkout/get-shipping-rates
     *
     * Customer checkout pe address enter kare → ye call hogi
     * Response mein saare shipping options aayenge with prices
     */
    public function getRates(Request $request): JsonResponse
    {
        $storeId = current_store_id();
        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store not selected or token invalid.'
            ], 400);
        }

        $validated = $request->validate([
            'destination' => 'required|array',
            'destination.country_code' => 'required|string|max:5',
            'destination.state' => 'sometimes|string|max:5',
            'destination.city' => 'nullable|string|max:255',
            'destination.postal_code' => 'required|string|max:20',
            'package' => 'required|array',
            'package.total_weight' => 'required|numeric|min:0.01',
            'package.weight_unit' => 'sometimes|string|in:LB,KG',
            // 'package.package_length'=>'sometimes|numeric|min:0',
            // 'package.package_width'=>'sometimes|numeric|min:0',
            // 'package.package_height'=>'sometimes|numeric|min:0',
            // 'package.dimension_unit'=>'sometimes|string|in:IN,CM',
            'package.order_total' => 'sometimes|numeric|min:0',
            'package.item_count' => 'sometimes|integer|min:1',
            'package.package_value' => 'sometimes|numeric|min:0',
        ]);

        return response()->json($this->shippingRateService->getCheckoutShippingRates($validated['destination'], $validated['package'], $storeId));
    }
}
