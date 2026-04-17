<?php

namespace App\Http\Controllers\api\FedEx;

use App\Http\Controllers\Controller;
use App\Models\ShippingZone;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ShippingZoneController extends Controller
{
    /**
     * GET /api/admin/shipping/zones
     * List all shipping zones with their methods
     */
    public function index(): JsonResponse
    {
        $zones = ShippingZone::with(['shippingMethods.fedexConfiguration'])
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $zones,
        ]);
    }

    /**
     * POST /api/admin/shipping/zones
     * Create a new shipping zone
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'       => 'required|string|max:255',
            'zone_type'  => 'sometimes|string|in:country,state,zip_range',
            'countries'  => 'required|array',
            'countries.*' => 'string|max:10',
            'states'     => 'sometimes|array',
            'states.*'   => 'string|max:10',
            'zip_ranges' => 'sometimes|array',
            'is_active'  => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer',
        ]);

        $zone = ShippingZone::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Shipping zone created successfully.',
            'data'    => $zone->load('shippingMethods'),
        ], 201);
    }

    /**
     * GET /api/admin/shipping/zones/{id}
     * Get single zone with all methods
     */
    public function show(int $id): JsonResponse
    {
        $zone = ShippingZone::with(['shippingMethods.fedexConfiguration'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $zone,
        ]);
    }

    /**
     * PUT /api/admin/shipping/zones/{id}
     * Update a shipping zone
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $zone = ShippingZone::findOrFail($id);

        $validated = $request->validate([
            'name'       => 'sometimes|string|max:255',
            'zone_type'  => 'sometimes|string|in:country,state,zip_range',
            'countries'  => 'sometimes|array',
            'countries.*' => 'string|max:10',
            'states'     => 'sometimes|array',
            'states.*'   => 'string|max:10',
            'zip_ranges' => 'sometimes|array',
            'is_active'  => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer',
        ]);

        $zone->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Shipping zone updated successfully.',
            'data'    => $zone->load('shippingMethods'),
        ]);
    }

    /**
     * DELETE /api/admin/shipping/zones/{id}
     * Delete a shipping zone (cascades to methods and fedex config)
     */
    public function destroy(int $id): JsonResponse
    {
        $zone = ShippingZone::findOrFail($id);
        $zone->delete();

        return response()->json([
            'success' => true,
            'message' => 'Shipping zone deleted successfully.',
        ]);
    }
}
