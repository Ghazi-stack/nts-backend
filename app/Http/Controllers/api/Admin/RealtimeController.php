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
                'payment:id,order_id,amount,discountAmount,payment_status',
            ])
            ->where('store_id', $storeId)
            ->where('is_draft', 0)
            ->where('created_at', '>=', $from)
            ->whereHas('payment', function ($q) {
                $q->where('payment_status', 'completed');
            })
            ->orderByDesc('id')
            ->get();

        $visits = VisitorSession::query()
            ->where('store_id', $storeId)
            ->where('created_at', '>=', $from)
            ->orderByDesc('id')
            ->get(['id', 'source', 'label', 'host', 'created_at']);

        $buckets = [];
        for ($i = 23; $i >= 0; $i--) {
            $start = now()->subHours($i)->startOfHour();
            $buckets[$start->format('Y-m-d H:00')] = [
                'hour'      => $start->format('Y-m-d H:00'),
                'label'     => $start->format('g A'),
                'revenue'   => 0,
                'itemsSold' => 0,
                'orders'    => 0,
                'visits'    => 0,
            ];
        }

        foreach ($orders as $order) {
            $key = Carbon::parse($order->created_at)->startOfHour()->format('Y-m-d H:00');
            if (!isset($buckets[$key])) {
                continue;
            }
            $buckets[$key]['revenue']   += (float) optional($order->payment)->amount;
            $buckets[$key]['orders']    += 1;
            $buckets[$key]['itemsSold'] += (int) $order->items->sum('quantity');
        }

        foreach ($visits as $visit) {
            $key = Carbon::parse($visit->created_at)->startOfHour()->format('Y-m-d H:00');
            if (!isset($buckets[$key])) {
                continue;
            }
            $buckets[$key]['visits'] += 1;
        }

        $hourly = array_values(array_map(function ($row) {
            $row['revenue'] = round($row['revenue'], 2);
            return $row;
        }, $buckets));

        $latestProducts = $orders
            ->flatMap(function ($order) {
                return $order->items->map(function ($item) use ($order) {
                    $image = $item->product?->images?->first();

                    return [
                        'orderId'   => $order->id,
                        'productId' => $item->product_id,
                        'name'      => $item->product->name ?? null,
                        'sku'       => $item->product->sku ?? null,
                        'quantity'  => (int) $item->quantity,
                        'price'     => (float) $item->price,
                        'image'     => $image->url ?? $image->image ?? $image->path ?? null,
                        'soldAt'    => optional($item->created_at)->toDateTimeString()
                            ?? optional($order->created_at)->toDateTimeString(),
                    ];
                });
            })
            ->sortByDesc('soldAt')
            ->unique('productId')
            ->take(5)
            ->values();

        return [
            'window' => [
                'from' => $from->toDateTimeString(),
                'to'   => now()->toDateTimeString(),
            ],
            'statistics' => [
                'revenue'   => round((float) $orders->sum(fn($o) => (float) optional($o->payment)->amount), 2),
                'itemsSold' => (int) $orders->sum(fn($o) => $o->items->sum('quantity')),
                'orders'    => $orders->count(),
                'visits'    => $visits->count(),
            ],
            'hourly' => $hourly,
            'latestProducts' => $latestProducts,
            'latestActivity' => $visits->take(20)->map(fn($row) => [
                'id'        => $row->id,
                'type'      => 'visit',
                'source'    => $row->source ?: 'direct',
                'label'     => $row->label ?: 'Visit from direct',
                'host'      => $row->host,
                'createdAt' => optional($row->created_at)->toDateTimeString(),
            ])->values(),
        ];
    }
}
