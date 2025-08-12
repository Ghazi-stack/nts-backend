<?php

namespace App\Http\Controllers\api\Admin;

use App\Http\Controllers\Controller;
use App\Imports\OrderTrackingImport;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Maatwebsite\Excel\Facades\Excel;

class OrderTrackingImportController extends Controller
{
    public const VALID_STATUSES = [
        'Pending',
        'Awaiting Payment',
        'Awaiting Fulfillment',
        'Awaiting Shipment',
        'Awaiting Pickup',
        'Partially Shipped',
        'Completed',
        'Shipped',
        'Cancelled',
        'Declined',
        'Refunded',
        'Disputed',
        'Manual Verification Required',
        'Partially Refunded',
    ];

    // POST /api/admin/orders/tracking/import
    public function import(Request $request)
    {
        $validated = $request->validate([
            'file'              => 'required|file|mimes:csv,txt,xlsx|max:512000',
            'statusToSet'       => 'nullable|string|in:' . implode(',', self::VALID_STATUSES),
            'overrideExisting'  => 'nullable|boolean',
            'hasHeaders'        => 'nullable|boolean',
            'separator'         => 'nullable|string',
            'enclosure'         => 'nullable|string',
        ]);

        $import = new OrderTrackingImport(
            storeId: current_store_id(),
            statusToSet: $validated['statusToSet'] ?? null, // null = Do not update
            overrideExisting: (bool)($validated['overrideExisting'] ?? false),
            hasHeaders: (bool)($validated['hasHeaders'] ?? true),
            separator: $validated['separator'] ?? ',',
            enclosure: $validated['enclosure'] ?? '"',
        );

        Excel::import($import, $request->file('file'));

        return response()->json([
            'status'  => true,
            'message' => 'Tracking import finished.',
            'data'    => $import->summary,
        ]);
    }

    // POST /api/admin/orders/{order}/tracking  (single order update)
    public function setSingle(Request $request, Order $order)
    {
        abort_unless($order->store_id === current_store_id(), 403, 'Order not in current store.');

        $data = $request->validate([
            'trackingNumber'   => 'required|string',
            'carrierCode'      => 'nullable|string|max:50',
            'carrierName'      => 'nullable|string|max:100',
            'statusToSet'      => 'nullable|string|in:' . implode(',', self::VALID_STATUSES),
            'overrideExisting' => 'nullable|boolean',
            'shippedAt'        => 'nullable|date',
        ]);

        $override = (bool)($data['overrideExisting'] ?? true);
        if (!$override && $order->tracking_number) {
            return response()->json([
                'status'  => false,
                'message' => 'Tracking already exists; set overrideExisting=true to replace.',
            ], 409);
        }

        $order->tracking_number       = $data['trackingNumber'];
        $order->tracking_carrier_code = $data['carrierCode'] ?? $order->tracking_carrier_code;
        $order->tracking_carrier_name = $data['carrierName'] ?? $order->tracking_carrier_name;
        $order->shipped_at            = $data['shippedAt'] ?? now();

        if (!empty($data['statusToSet'])) {
            $order->status = $data['statusToSet'];
        }

        $order->save();

        return response()->json([
            'status'  => true,
            'message' => 'Tracking set.',
            'data'    => new OrderResource($order->load('items')),
        ]);
    }

    // GET /api/admin/orders/tracking/template
    public function template()
    {
        // CamelCase headers to match the importer
        $csv = implode("\n", [
            'orderId,orderNumber,trackingNumber,carrierCode,carrierName,shippedAt',
            '109,,1Z999AA10123456784,ups,UPS,2025-08-01 14:45:00',
            ',57548,9400111899223857481234,usps,USPS,2025-08-02 10:30:00',
        ]);

        return Response::make($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="tracking_template.csv"',
        ]);
    }
}
