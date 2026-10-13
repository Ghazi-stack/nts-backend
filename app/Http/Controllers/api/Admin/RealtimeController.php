<?php

namespace App\Http\Controllers\api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
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

        $captured = Order::query()
            ->with([
                'items:id,order_id,product_id,quantity,price',
                'items.product:id,name,sku',
                'items.product.images',
                'payment:id,order_id,payment_status,updated_at',
            ])
            ->where('store_id', $storeId)
            ->where('is_draft', 0)
            ->whereHas('payment', function ($q) {
                $q->where('payment_status', 'completed');
            })
            ->get();

        $orders = $captured->filter(function ($order) use ($from) {
            return optional($order->payment)->updated_at >= $from;
        })->values();

        $visits = VisitorSession::query()
            ->where('store_id', $storeId)
            ->where('created_at', '>=', $from)
            ->orderByDesc('id')
            ->get(['id', 'label', 'created_at']);

        $latestProducts = $captured
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

    public function searchIndex(Request $request)
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status'  => false,
                'message' => 'Store not found or unauthorized.',
            ], 403);
        }

        $type    = strtolower((string) $request->query('type', 'customer'));
        $keyword = trim((string) $request->query('keyword', ''));

        if ($keyword === '' || !in_array($type, ['customer', 'product', 'order'], true)) {
            return response()->json([
                'status'  => true,
                'message' => 'Search results',
                'data'    => [],
            ]);
        }

        $data = match ($type) {
            'customer' => $this->customers($storeId, $keyword),
            'product'  => $this->products($storeId, $keyword),
            'order'    => $this->orders($storeId, $keyword),
        };

        return response()->json([
            'status'  => true,
            'message' => 'Search results',
            'data'    => $data,
        ]);
    }

    private function customers(int $storeId, string $keyword)
    {
        return Customer::query()
            ->where('store_id', $storeId)
            ->where(function ($q) use ($keyword) {
                $q->where('email', 'like', "%{$keyword}%")
                    ->orWhere('first_name', 'like', "%{$keyword}%")
                    ->orWhere('last_name', 'like', "%{$keyword}%")
                    ->orWhere('name', 'like', "%{$keyword}%")
                    ->orWhere('phone', 'like', "%{$keyword}%")
                    ->orWhere('company_name', 'like', "%{$keyword}%")
                    ->orWhereRaw("CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')) LIKE ?", ["%{$keyword}%"]);
            })
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'first_name', 'last_name', 'name', 'email', 'phone'])
            ->map(function ($row) {
                $name = trim($row->name ?: ($row->first_name . ' ' . $row->last_name));

                return [
                    'id'    => $row->id,
                    'email' => $row->email,
                    'name'  => $name,
                    'phone' => $row->phone,
                    '_key'  => strtolower(trim((string) $row->email)) . '|' . strtolower($name),
                ];
            })
            ->unique('_key')
            ->take(15)
            ->map(fn($row) => [
                'id'    => $row['id'],
                'email' => $row['email'],
                'name'  => $row['name'],
                'phone' => $row['phone'],
            ])
            ->values();
    }

    private function products(int $storeId, string $keyword)
    {
        return Product::query()
            ->with('brand:id,name')
            ->where('store_id', $storeId)
            ->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                    ->orWhere('sku', 'like', "%{$keyword}%")
                    ->orWhere('search_keywords', 'like', "%{$keyword}%")
                    ->orWhereHas('brand', function ($b) use ($keyword) {
                        $b->where('name', 'like', "%{$keyword}%");
                    });
            })
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'name', 'sku', 'brand_id'])
            ->unique(fn($row) => strtolower(trim((string) $row->sku)))
            ->take(15)
            ->map(fn($row) => [
                'id'    => $row->id,
                'name'  => $row->name,
                'sku'   => $row->sku,
                'brand' => $row->brand->name ?? null,
            ])
            ->values();
    }

    private function orders(int $storeId, string $keyword)
    {
        return Order::query()
            ->where('store_id', $storeId)
            ->where('is_draft', 0)
            ->where(function ($q) use ($keyword) {
                $q->where('id', 'like', "%{$keyword}%")
                    ->orWhere('order_number', 'like', "%{$keyword}%")
                    ->orWhere('email', 'like', "%{$keyword}%")
                    ->orWhere('name', 'like', "%{$keyword}%")
                    ->orWhere('phone', 'like', "%{$keyword}%")
                    ->orWhere('total_amount', 'like', "%{$keyword}%");

                if (is_numeric($keyword)) {
                    $q->orWhere('id', (int) $keyword);
                }

                $q->orWhereHas('customer', function ($c) use ($keyword) {
                    $c->where('email', 'like', "%{$keyword}%")
                        ->orWhere('first_name', 'like', "%{$keyword}%")
                        ->orWhere('last_name', 'like', "%{$keyword}%")
                        ->orWhere('name', 'like', "%{$keyword}%")
                        ->orWhere('phone', 'like', "%{$keyword}%")
                        ->orWhereRaw("CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')) LIKE ?", ["%{$keyword}%"]);
                });

                $q->orWhereHas('billingInformation', function ($b) use ($keyword) {
                    $b->where('email', 'like', "%{$keyword}%")
                        ->orWhere('first_name', 'like', "%{$keyword}%")
                        ->orWhere('last_name', 'like', "%{$keyword}%")
                        ->orWhere('phone', 'like', "%{$keyword}%");
                });

                $q->orWhereHas('items.product', function ($p) use ($keyword) {
                    $p->where('name', 'like', "%{$keyword}%")
                        ->orWhere('sku', 'like', "%{$keyword}%");
                });
            })
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'order_number'])
            ->unique('id')
            ->take(15)
            ->map(fn($row) => [
                'id'          => $row->id,
                'orderNumber' => $row->order_number ?: (string) $row->id,
            ])
            ->values();
    }
}
