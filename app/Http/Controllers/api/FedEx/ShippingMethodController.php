<?php

namespace App\Http\Controllers\API\FedEx;

use App\Http\Controllers\Controller;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ShippingMethodController extends Controller
{
    public function index(int $zoneId): JsonResponse
    {
        $zone = ShippingZone::findOrFail($zoneId);
        $methods = $zone->shippingMethods()->with('fedexConfiguration')->orderBy('sort_order')->get();
        return response()->json(['success' => true, 'data' => $methods]);
    }

    public function show(int $id): JsonResponse
    {
        $method = ShippingMethod::with('fedexConfiguration')->findOrFail($id);
        return response()->json(['success' => true, 'data' => $method]);
    }

    public function store(Request $request, int $zoneId): JsonResponse
    {
        $zone = ShippingZone::findOrFail($zoneId);
        $validated = $request->validate([
            'method_type' => 'required|string|in:free_shipping,flat_rate,flat_rate_weight,fedex_realtime,custom_label',
            'display_name' => 'required|string|max:255',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer',
            'flat_rate_amount' => 'sometimes|nullable|numeric|min:0',
            'flat_rate_type' => 'sometimes|nullable|string|in:per_order,per_item',
            'weight_threshold' => 'sometimes|nullable|numeric|min:0',
            'weight_rate_amount' => 'sometimes|nullable|numeric|min:0',
            'free_shipping_min_order' => 'sometimes|nullable|numeric|min:0',
            'custom_description' => 'sometimes|nullable|string|max:1000',
            // Ship by weight / order total config
            'charge_shipping_by' => 'sometimes|nullable|string|in:by_weight,by_order_total',
            'default_shipping_cost' => 'sometimes|nullable|numeric|min:0',
            'rate_display_type' => 'sometimes|nullable|string|in:fixed,percentage',
            // Ranges array
            'ranges' => 'sometimes|nullable|array',
            'ranges.*.from' => 'required_with:ranges|numeric|min:0',
            'ranges.*.up_to' => 'required_with:ranges|numeric|min:0',
            'ranges.*.cost' => 'required_with:ranges|numeric|min:0',
        ]);
        $validated['shipping_zone_id'] = $zone->id;
        $method = ShippingMethod::create($validated);
        return response()->json(['success' => true, 'message' => 'Shipping method added successfully.', 'data' => $method], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $method = ShippingMethod::findOrFail($id);
        $validated = $request->validate([
            'display_name' => 'sometimes|string|max:255',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer',
            'flat_rate_amount' => 'sometimes|nullable|numeric|min:0',
            'flat_rate_type' => 'sometimes|nullable|string|in:per_order,per_item',
            'weight_threshold' => 'sometimes|nullable|numeric|min:0',
            'weight_rate_amount' => 'sometimes|nullable|numeric|min:0',
            'free_shipping_min_order' => 'sometimes|nullable|numeric|min:0',
            'custom_description' => 'sometimes|nullable|string|max:1000',
            // Ship by weight / order total config
            'charge_shipping_by' => 'sometimes|nullable|string|in:by_weight,by_order_total',
            'default_shipping_cost' => 'sometimes|nullable|numeric|min:0',
            'rate_display_type' => 'sometimes|nullable|string|in:fixed,percentage',
            // Ranges array
            'ranges' => 'sometimes|nullable|array',
            'ranges.*.from' => 'required_with:ranges|numeric|min:0',
            'ranges.*.up_to' => 'required_with:ranges|numeric|min:0',
            'ranges.*.cost' => 'required_with:ranges|numeric|min:0',
        ]);
        $method->update($validated);
        return response()->json(['success' => true, 'message' => 'Shipping method updated successfully.', 'data' => $method->load('fedexConfiguration')]);
    }

    public function destroy(int $id): JsonResponse
    {
        ShippingMethod::findOrFail($id)->delete();
        return response()->json(['success' => true, 'message' => 'Shipping method deleted successfully.']);
    }

    public function toggle(int $id): JsonResponse
    {
        $method = ShippingMethod::findOrFail($id);
        $method->update(['is_active' => !$method->is_active]);
        return response()->json(['success' => true, 'message' => $method->is_active ? 'Shipping method enabled.' : 'Shipping method disabled.', 'data' => ['id' => $method->id, 'is_active' => $method->is_active]]);
    }
}
