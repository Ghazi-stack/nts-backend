<?php

namespace App\Http\Controllers\api\FedEx;

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
     * POST /api/checkout/shipping-rates
     *
     * Called from checkout page when customer enters their shipping address
     * Returns all available shipping options with prices — exactly like
     * the BigCommerce checkout screenshot showing:
     *   - Ship on my own account    $0.00
     *   - Flat Rate for under 10 LBS*  $10.00
     *   - FedEx (FedEx Ground)       $35.84
     *   - FedEx (FedEx Express Saver) $65.90
     *   - FedEx (FedEx 2 Day)        $84.27
     *   - FedEx (Standard Overnight) $169.83
     *   - FedEx (Priority Overnight) $179.93
     *   - FedEx (First Overnight)    $220.39
     *
     * Request body:
     * {
     *   "destination": {
     *     "country_code": "US",
     *     "state": "FL",
     *     "city": "Miami Beach",
     *     "postal_code": "33139"
     *   },
     *   "package": {
     *     "total_weight": 5.0,
     *     "weight_unit": "LB",
     *     "package_length": 12,
     *     "package_width": 8,
     *     "package_height": 6,
     *     "dimension_unit": "IN",
     *     "order_total": 150.00,
     *     "item_count": 3,
     *     "package_value": 150.00
     *   }
     * }
     */
    public function getRates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'destination'               => 'required|array',
            'destination.country_code'  => 'required|string|max:5',
            'destination.state'         => 'sometimes|string|max:5',
            'destination.city'          => 'sometimes|string|max:255',
            'destination.postal_code'   => 'required|string|max:20',

            'package'                   => 'required|array',
            'package.total_weight'      => 'required|numeric|min:0.01',
            'package.weight_unit'       => 'sometimes|string|in:LB,KG',
            'package.package_length'    => 'sometimes|numeric|min:0',
            'package.package_width'     => 'sometimes|numeric|min:0',
            'package.package_height'    => 'sometimes|numeric|min:0',
            'package.dimension_unit'    => 'sometimes|string|in:IN,CM',
            'package.order_total'       => 'sometimes|numeric|min:0',
            'package.item_count'        => 'sometimes|integer|min:1',
            'package.package_value'     => 'sometimes|numeric|min:0',
        ]);

        $result = $this->shippingRateService->getCheckoutShippingRates(
            $validated['destination'],
            $validated['package']
        );

        return response()->json($result);
    }
}
