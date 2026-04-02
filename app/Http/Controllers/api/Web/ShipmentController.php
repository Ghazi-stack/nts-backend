<?php

namespace App\Http\Controllers\api\Web;

use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Events\ShipmentCreated;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use App\Http\Resources\ShipmentResource;

class ShipmentController extends Controller
{
    public function index(Request $request)
    {
        $storeId = current_store_id();
        $keyword = $request->get('keyword');

        // Eager-load related order, items, and products
        $query = Shipment::with(['order.items.product'])
            ->where('store_id', $storeId);

        // Apply keyword search (if provided)
        if ($keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->where('shipped_to', 'like', '%' . $keyword . '%')
                    ->orWhere('tracking_number', 'like', '%' . $keyword . '%');
            });
        }

        // Paginate results
        $shipments = $query->orderByDesc('id')->paginate($request->get('pageSize', 20));

        // Return response
        return response()->json([
            'status' => true,
            'message' => 'Shipments fetched successfully.',
            'data' => ShipmentResource::collection($shipments),
            'pagination' => [
                'total' => $shipments->total(),
                'page' => $shipments->currentPage(),
                'pageSize' => $shipments->perPage(),
                'lastPage' => $shipments->lastPage()
            ]
        ]);
    }

    /**
     * Show a single shipment with order and product details.
     */
    public function show($id)
    {
        $storeId = current_store_id();

        $shipment = Shipment::with(['order.items.product'])
            ->where('store_id', $storeId)
            ->findOrFail($id);

        return response()->json([
            'status' => true,
            'message' => 'Shipment details fetched.',
            'data' => new ShipmentResource($shipment)
        ]);
    }

    /**
     * Create a new shipment for an order (if not already shipped).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'orderId' => 'required|exists:orders,id',
            'shippedTo' => 'nullable|string|max:255',
            'dateShipped' => 'required|date',
            'trackingNumber' => 'nullable|string|max:255',
        ]);

        $storeId = current_store_id();
        $order = Order::with('customer') // ⬅️ make sure customer is eager-loaded
            ->where('store_id', $storeId)
            ->findOrFail($validated['orderId']);

        $existing = Shipment::where('store_id', $storeId)
            ->where('order_id', $order->id)
            ->first();

        if ($existing) {
            return response()->json([
                'status' => false,
                'message' => 'Shipment already exists for this order.',
                'data' => new ShipmentResource($existing)
            ], 409); // Conflict
        }

        $shipment = Shipment::create([
            'order_id'        => $order->id,
            'store_id'        => $storeId,
            'shipped_to'      => $validated['shippedTo']
                ?? trim(optional($order->customer)->first_name . ' ' . optional($order->customer)->last_name)
                ?? 'Unknown',
            'date_shipped'    => $validated['dateShipped'],
            'tracking_number' => $validated['trackingNumber'],
        ]);

        $order->tracking_number;
        $order->save();

        event(new ShipmentCreated($shipment));
        sleep(1);

        $emailStatus = Cache::pull('shipment_email_status_' . $shipment->id);

        return response()->json([
            'status' => true,
            'message' => 'Shipment created successfully.',
            'data' => new ShipmentResource($shipment)
        ], 201);
    }


    /**
     * Update shipment details.
     */
    public function update(Request $request, $id)
    {
        $storeId = current_store_id();

        $shipment = Shipment::where('store_id', $storeId)->findOrFail($id);

        $validated = $request->validate([
            'shippedTo' => 'nullable|string|max:255',
            'dateShipped' => 'nullable|date',
            'trackingNumber' => 'nullable|string|max:255',
        ]);

        $shipment->update([
            'shipped_to' => $validated['shippedTo'] ?? $shipment->shipped_to,
            'date_shipped' => $validated['dateShipped'] ?? $shipment->date_shipped,
            'tracking_number' => $validated['trackingNumber'] ?? $shipment->tracking_number,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Shipment updated.',
            'data' => new ShipmentResource($shipment)
        ]);
    }

    /**
     * Delete a shipment.
     */
    public function destroy(Request $request)
    {
        $storeId = current_store_id();

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:shipments,id'
        ]);

        $deleted = Shipment::where('store_id', $storeId)
            ->whereIn('id', $validated['ids'])
            ->delete();

        return response()->json([
            'status' => true,
            'message' => "$deleted shipment(s) deleted successfully."
        ]);
    }

    /**
     * Download PDF packing slip.
     */
    public function printPackingSlip($id)
    {
        $storeId = current_store_id();

        $shipment = Shipment::with(['order.items.product'])
            ->where('store_id', $storeId)
            ->findOrFail($id);

        // Convert logo to base64
        $logoPath = public_path('logo/nts-logo.png');
        $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));

        // Load PDF with view and pass base64 logo
        $pdf = Pdf::loadView('pdf.packing-slip', [
            'shipment' => $shipment,
            'order' => $shipment->order,
            'logoBase64' => $logoBase64
        ]);

        return $pdf->download('packing-slip-' . $shipment->id . '.pdf');
    }

    /**
     * Generate tracking number.
     */
    private function generateTrackingNumber()
    {
        return 'TRK-' . strtoupper(Str::random(10));
    }

    public function advancedSearch(Request $request)
    {
        $storeId = current_store_id();

        $query = Shipment::with(['order.items.product'])
            ->where('store_id', $storeId)
            ->where(function ($mainQuery) use ($request) {
                $filtersApplied = false;

                // ✅ Keyword search (in shipped_to, tracking_number, or ID)
                if ($request->filled('keyword')) {
                    $filtersApplied = true;
                    $keyword = $request->keyword;

                    $mainQuery->where(function ($q) use ($keyword) {
                        $q->where('shipped_to', 'like', "%$keyword%")
                            ->orWhere('tracking_number', 'like', "%$keyword%")
                            ->orWhere('id', $keyword); // ID search
                    });
                }

                // ✅ Shipment ID Range
                if ($request->filled('shipmentIdFrom')) {
                    $filtersApplied = true;
                    $mainQuery->where('id', '>=', $request->shipmentIdFrom);
                }

                if ($request->filled('shipmentIdTo')) {
                    $filtersApplied = true;
                    $mainQuery->where('id', '<=', $request->shipmentIdTo);
                }

                // ✅ Order ID Range
                if ($request->filled('orderIdFrom')) {
                    $filtersApplied = true;
                    $mainQuery->where('order_id', '>=', $request->orderIdFrom);
                }

                if ($request->filled('orderIdTo')) {
                    $filtersApplied = true;
                    $mainQuery->where('order_id', '<=', $request->orderIdTo);
                }

                // ✅ Shipping Date (date_shipped column)
                if ($request->filled('shippingDate')) {
                    $filtersApplied = true;
                    $mainQuery->whereDate('date_shipped', $request->shippingDate);
                }

                // ✅ Order Date (from orders table)
                if ($request->filled('orderDate')) {
                    $filtersApplied = true;
                    $mainQuery->whereHas('order', function ($q) use ($request) {
                        $q->whereDate('created_at', $request->orderDate);
                    });
                }

                // ❌ No filters at all? Return no results
                if (!$filtersApplied) {
                    $mainQuery->whereRaw('0 = 1');
                }
            });

        // ✅ Sorting
        $sortField = $request->get('sortField', 'id');
        $sortDirection = $request->get('sortDirection', 'asc');
        $query->orderBy($sortField, $sortDirection);

        // ✅ Pagination
        $shipments = $query->paginate($request->get('pageSize', 20));

        return response()->json([
            'status' => true,
            'message' => 'Advanced search results.',
            'data' => ShipmentResource::collection($shipments),
            'pagination' => [
                'total' => $shipments->total(),
                'page' => $shipments->currentPage(),
                'pageSize' => $shipments->perPage(),
                'lastPage' => $shipments->lastPage()
            ]
        ]);
    }
}
