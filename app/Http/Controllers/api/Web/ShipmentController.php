<?php

namespace App\Http\Controllers\Api\Web;

use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\ShipmentResource;
use Illuminate\Support\Str;
use Barryvdh\DomPDF\Facade\Pdf;

class ShipmentController extends Controller
{
    /**
     * List all shipments with pagination.
     */
    public function index(Request $request)
    {
        $storeId = current_store_id();

        $shipments = Shipment::with(['order.items.product'])
            ->where('store_id', $storeId)
            ->orderByDesc('id')
            ->paginate($request->get('pageSize', 20));

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
        $order = Order::where('store_id', $storeId)->findOrFail($validated['orderId']);

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
            'order_id' => $order->id,
            'store_id' => $storeId,
            'shipped_to' => $validated['shippedTo'] ?? $order->name,
            'date_shipped' => $validated['dateShipped'],
            'tracking_number' => $validated['trackingNumber'] ?? $this->generateTrackingNumber(),
        ]);

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
    public function destroy($id)
    {
        $storeId = current_store_id();

        $shipment = Shipment::where('store_id', $storeId)->findOrFail($id);
        $shipment->delete();

        return response()->json([
            'status' => true,
            'message' => 'Shipment deleted.'
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
}
