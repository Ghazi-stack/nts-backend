<?php

namespace App\Http\Controllers\api\Admin;

use App\Http\Controllers\Controller;

use App\Models\Order;
use App\Models\VisitorSession;
use Carbon\Carbon;
use Illuminate\Http\Request;

class RealtimeController extends Controller
{
    public function index()
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status'  => false,
                'message' => 'Store not found or unauthorized.',
            ], 403);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Realtime stats retrieved successfully',
            'data'    => $this->build((int) $storeId),
        ]);
    }

    public function build(int $storeId): array
    {
        $from = now()->subHours(24);

        $orders = Order::query()
            ->with([
                'items:id,order_id,product_id,quantity,price,created_at',
                'items.product:id,name,sku',
                'items.product.images',
                'payment:id,order_id,amount,discountAmount,payment_status,updated_at',
            ])
            ->where('store_id', $storeId)
            ->where('is_draft', 0)
            ->whereHas('payment', function ($q) use ($from) {
                $q->where('payment_status', 'completed')
                    ->where('updated_at', '>=', $from);
            })
            ->orderByDesc('id')
            ->get();

        $visits = VisitorSession::query()
            ->where('store_id', $storeId)
            ->where('created_at', '>=', $from)
            ->orderByDesc('id')
            ->get(['id', 'source', 'label', 'host', 'created_at']);

        $latestProducts = $orders
            ->flatMap(function ($order) {
                $capturedAt = optional(optional($order->payment)->updated_at)->toDateTimeString();

                return $order->items->map(function ($item) use ($order, $capturedAt) {
                    $image = $item->product?->images?->first();

                    return [
                        'orderId'   => $order->id,
                        'productId' => $item->product_id,
                        'name'      => $item->product->name ?? null,
                        'image'     => $image->url ?? $image->image ?? $image->path ?? null,
                        'soldAt'    => $capturedAt,
                    ];
                });
            })
            ->sortByDesc('soldAt')
            ->unique('productId')
            ->take(5)
            ->values();

        return [
            'statistics' => [
                'revenue'   => round((float) $orders->sum('total_amount'), 2),
                'itemsSold' => (int) $orders->sum(fn($o) => $o->items->sum('quantity')),
                'orders'    => $orders->count(),
                'visits'    => $visits->count(),
            ],
            'latestProducts' => $latestProducts,
            'latestActivity' => $visits->take(20)->map(fn($row) => [
                'id'        => $row->id,
                'label'     => $row->label ?: 'Visit from direct',
                'createdAt' => optional($row->created_at)->toDateTimeString(),
            ])->values(),
        ];
    }
}
