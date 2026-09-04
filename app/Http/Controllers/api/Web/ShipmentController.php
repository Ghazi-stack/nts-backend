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
use App\Models\Logo;
use App\Http\Resources\ShipmentResource;
use Illuminate\Support\Facades\Mail;
use App\Mail\ShipmentTrackingMail;
use App\Models\OrderTimeline;
use App\Models\Store;

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


    public function getShipmentByOrderId(Request $request)
    {
        $storeId = current_store_id();

        $request->validate([
            'order_id' => 'required|exists:orders,id',
        ]);

        $shipment = Shipment::with([
            'order.items.product.seo'
        ])
            ->where('store_id', $storeId)
            ->where('order_id', $request->order_id)
            ->first();

        if (!$shipment) {
            return response()->json([
                'status' => false,
                'message' => 'Shipment not found against this order id.',
            ], 404);
        }

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
            'shippingMethod' => 'nullable|string|max:255',
            'trackingCarrier' => 'nullable|string|max:255',
            'shippedBy' => 'nullable|string|max:255',
            'packingSlipNotes' => 'nullable|string|max:255',
            'dateShipped' => 'nullable|date',
            'trackingId' => 'nullable|string|max:255',
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
            'shipping_method'      => $validated['shippingMethod'] ?? null,
            'tracking_carrie'      => $validated['trackingCarrier'] ?? null,
            'shipped_by'      => $validated['shippedBy'] ?? null,
            'packing_slip_note'      => $validated['packingSlipNotes'] ?? null,
            'tracking_number'      => $validated['trackingId'] ?? null,
        ]);

        $order->tracking_number = $validated['trackingId'] ?? null;
        $order->save();

        // ===================== TIMELINE: SHIPMENT CREATED =====================
        OrderTimeline::addEvent($order->id, $storeId, 'shipment_created', 'Shipment created', [
            'tracking_number' => $shipment->tracking_number,
            'shipping_method' => $shipment->shipping_method,
            'shipped_by' => $shipment->shipped_by,
            'packing_slip_note' => $shipment->packing_slip_note,
            'shipped_to'      => $shipment->shipped_to,
            'date_shipped'    => $shipment->date_shipped,
        ], 'shipping');


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
            'shippedTo'         => 'nullable|string|max:255',
            'dateShipped'       => 'nullable|date',
            'shippingMethod'    => 'nullable|string|max:255',
            'trackingCarrier'   => 'nullable|string|max:255',
            'shippedBy'         => 'nullable|string|max:255',
            'packingSlipNotes'  => 'nullable|string|max:1000',
            'trackingId'        => 'required|string|max:255',
        ]);

        $shipment->update([
            'shipped_to'           => $validated['shippedTo'] ?? $shipment->shipped_to,
            'date_shipped'         => $validated['dateShipped'] ?? $shipment->date_shipped,
            'shipping_method'      => $validated['shippingMethod'] ?? $shipment->shipping_method,
            'tracking_carrier'     => $validated['trackingCarrier'] ?? $shipment->tracking_carrier,  // typo fixed
            'shipped_by'           => $validated['shippedBy'] ?? $shipment->shipped_by,
            'packing_slip_notes'   => $validated['packingSlipNotes'] ?? $shipment->packing_slip_notes, // consistent name
            'tracking_number'      => $validated['trackingId'],
        ]);

        // ===================== TIMELINE =====================
        OrderTimeline::addEvent($shipment->order_id, $storeId, 'tracking_updated', 'Tracking information updated', [
            'tracking_number'    => $shipment->tracking_number,
            'tracking_carrier'   => $shipment->tracking_carrier,
            'shipped_by'         => $shipment->shipped_by,
            'packing_slip_notes' => $shipment->packing_slip_notes,
            'shipping_method'    => $shipment->shipping_method,
            'shipped_to'         => $shipment->shipped_to,
        ], 'shipping');

        // ===================== EMAIL =====================
        try {
            $order = Order::with('customer')
                ->where('store_id', $storeId)
                ->find($shipment->order_id);

            if ($order && $order->customer && !empty($order->customer->email)) {
                Mail::to($order->customer->email)
                    ->send(new ShipmentTrackingMail($order, $shipment));
            }
        } catch (\Exception $e) {
            \Log::error('Shipment Tracking Email Failed', [
                'shipment_id' => $shipment->id,
                'order_id'    => $shipment->order_id,
                'error'       => $e->getMessage(),
            ]);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Shipment updated.',
            'data'    => new ShipmentResource($shipment)
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

        $shipment = Shipment::with(['order.items.product.identifiers', 'order.billingInformation'])
            ->where('store_id', $storeId)
            ->where('order_id', $id)
            ->latest()
            ->first();

        if (!$shipment) {
            return response()->json([
                'status'  => false,
                'message' => 'No shipment found for this order. Please create a shipment first.',
            ], 404);
        }

        $store = Store::find($storeId);

        // Dynamic logo from Logo table
        $logo = Logo::where('store_id', $storeId)->first();
        $logoBase64 = null;

        if ($logo && $logo->logo_url) {
            try {
                $logoContent = file_get_contents($logo->logo_url);

                if ($logoContent) {
                    $extension = pathinfo(parse_url($logo->logo_url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'png';

                    $mimeTypes = [
                        'png'  => 'image/png',
                        'jpg'  => 'image/jpeg',
                        'jpeg' => 'image/jpeg',
                        'webp' => 'image/webp',
                        'svg'  => 'image/svg+xml',
                    ];

                    $mime = $mimeTypes[strtolower($extension)] ?? 'image/png';

                    $logoBase64 = 'data:' . $mime . ';base64,' . base64_encode($logoContent);
                }
            } catch (\Exception $e) {
                \Log::warning('Packing Slip logo fetch failed: ' . $e->getMessage());
            }
        }

        // Generate PDF
        $pdf = Pdf::loadView('pdf.packing-slip', [
            'shipment'   => $shipment,
            'order'      => $shipment->order,
            'logoBase64' => $logoBase64,
            'store'      => $store,
        ]);

        return $pdf->download('packing-slip-' . $shipment->id . '.pdf');
    }

    /**
     * Generate tracking number.
     */
    // private function generateTrackingNumber()
    // {
    //     return 'TRK-' . strtoupper(Str::random(10));
    // }

    public function advancedSearch(Request $request)
    {
        $storeId = current_store_id();

        $keyword = $request->input('keyword', $request->input('searchKeywords'));

        // UI default "0" ko empty treat karo
        if ($keyword === 0 || $keyword === '0') {
            $keyword = null;
        }

        $query = Shipment::with(['order.items.product'])
            ->where('store_id', $storeId)
            ->where(function ($mainQuery) use ($request, $keyword) {
                $filtersApplied = false;

                // Shipper name, Shipment ID, Tracking number
                if ($keyword !== null && $keyword !== '') {
                    $filtersApplied = true;

                    $mainQuery->where(function ($q) use ($keyword) {
                        if (is_numeric($keyword)) {
                            $q->where('id', $keyword)
                                ->orWhere('tracking_number', $keyword)
                                ->orWhere('order_id', $keyword);
                        } else {
                            $q->where('shipped_by', 'like', "%{$keyword}%")
                                ->orWhere('shipped_to', 'like', "%{$keyword}%")
                                ->orWhere('tracking_number', 'like', "%{$keyword}%")
                                ->orWhere('tracking_carrie', 'like', "%{$keyword}%")
                                ->orWhere('shipping_method', 'like', "%{$keyword}%");
                        }
                    });
                }

                // Shipment ID Range
                if ($request->filled('shipmentIdFrom') && $request->shipmentIdFrom != 0) {
                    $filtersApplied = true;
                    $mainQuery->where('id', '>=', $request->shipmentIdFrom);
                }

                if ($request->filled('shipmentIdTo') && $request->shipmentIdTo != 0) {
                    $filtersApplied = true;
                    $mainQuery->where('id', '<=', $request->shipmentIdTo);
                }

                // Order ID Range
                if ($request->filled('orderIdFrom') && $request->orderIdFrom != 0) {
                    $filtersApplied = true;
                    $mainQuery->where('order_id', '>=', $request->orderIdFrom);
                }

                if ($request->filled('orderIdTo') && $request->orderIdTo != 0) {
                    $filtersApplied = true;
                    $mainQuery->where('order_id', '<=', $request->orderIdTo);
                }

                // Shipping Date
                if ($request->filled('shippingDate')) {
                    $filtersApplied = true;
                    $mainQuery->whereDate('date_shipped', $request->shippingDate);
                }

                // Order Date
                if ($request->filled('orderDate')) {
                    $filtersApplied = true;
                    $mainQuery->whereHas('order', function ($q) use ($request) {
                        $q->whereDate('created_at', $request->orderDate);
                    });
                }

                if (!$filtersApplied) {
                    $mainQuery->whereRaw('0 = 1');
                }
            });

        $sortField = $request->get('sortField', $request->get('sortBy', 'id'));
        $sortDirection = $request->get('sortDirection', $request->get('sortOrder', 'asc'));
        $query->orderBy($sortField, $sortDirection);

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
