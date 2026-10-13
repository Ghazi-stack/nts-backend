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
                    ->orWhere('order_number', 'like', "%{$keyword}%");

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

    public function showSearchCustomersDetails($id)
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status'  => false,
                'message' => 'Store not found or unauthorized.',
            ], 403);
        }

        $customer = Customer::query()
            ->where('store_id', $storeId)
            ->find($id);

        if (!$customer) {
            return response()->json([
                'status'  => false,
                'message' => 'Customer not found.',
            ], 404);
        }

        $orders = $customer->orders()
            ->with([
                'items:id,order_id,product_id,quantity,price',
                'items.product:id,name,sku,price',
            ])
            ->where('store_id', $storeId)
            ->where('is_draft', 0)
            ->orderByDesc('id')
            ->get();

        $ordersCount = $orders->count();
        $totalSpent  = round((float) $orders->sum('total_amount'), 2);
        $lastOrder   = $orders->sortByDesc('created_at')->first();

        $itemPrices = $orders->flatMap(fn($order) => $order->items->pluck('price'))->filter(fn($price) => $price !== null);
        $minPrice   = $itemPrices->min();
        $maxPrice   = $itemPrices->max();

        $discountedOrders = $orders->filter(function ($order) {
            return (float) $order->discountAmount > 0
                || (float) $order->manual_discount > 0
                || !empty($order->coupon_code);
        })->count();

        $allItems = $orders->flatMap->items;
        $itemsCount = $allItems->count();
        $discountedItems = $allItems->filter(function ($item) {
            $productPrice = (float) optional($item->product)->price;
            return $productPrice > 0 && (float) $item->price < $productPrice;
        })->count();

        return response()->json([
            'status'  => true,
            'message' => 'Customer details retrieved successfully',
            'data'    => [
                'customer' => [
                    'id'    => $customer->id,
                    'name'  => trim($customer->name ?: ($customer->first_name . ' ' . $customer->last_name)),
                    'email' => $customer->email,
                    'phone' => $customer->phone,
                ],
                'stats' => [
                    'totalSpent' => $totalSpent,
                    'orders'     => $ordersCount,
                    'lastPurchase' => $lastOrder ? [
                        'date'    => optional($lastOrder->created_at)->toDateString(),
                        'daysAgo' => Carbon::parse($lastOrder->created_at)->diffInDays(now()),
                    ] : null,
                    'aov' => $ordersCount ? round($totalSpent / $ordersCount, 2) : 0,
                    'itemPriceRange' => [
                        'min' => $minPrice !== null ? round((float) $minPrice, 2) : 0,
                        'max' => $maxPrice !== null ? round((float) $maxPrice, 2) : 0,
                    ],
                ],
                'discounts' => [
                    'orders' => [
                        'percent'  => $ordersCount ? round(($discountedOrders / $ordersCount) * 100, 2) : 0,
                        'count'    => $discountedOrders,
                        'total'    => $ordersCount,
                    ],
                    'products' => [
                        'percent' => $itemsCount ? round(($discountedItems / $itemsCount) * 100, 2) : 0,
                        'count'   => $discountedItems,
                        'total'   => $itemsCount,
                    ],
                ],
                'orders' => $orders->map(function ($order) {
                    $subtotal = round((float) $order->items->sum(fn($item) => $item->price * $item->quantity), 2);
                    $discount = round((float) $order->discountAmount + (float) $order->manual_discount, 2);

                    return [
                        'id'          => $order->id,
                        'orderNumber' => $order->order_number ?: (string) $order->id,
                        'ordered'     => optional($order->created_at)->toDateString(),
                        'channel'     => $order->channel ?: 'Direct',
                        'totalAmount' => round((float) $order->total_amount, 2),
                        'subtotal'    => $subtotal,
                        'discounts'   => $discount,
                        'shipping'    => round((float) $order->shipping_cost, 2),
                    ];
                })->values(),
                'products' => $orders->flatMap(function ($order) {
                    return $order->items->map(function ($item) use ($order) {
                        $productPrice = (float) optional($item->product)->price;
                        $soldPrice    = (float) $item->price;
                        $percent      = $productPrice > 0 && $soldPrice < $productPrice
                            ? round((($productPrice - $soldPrice) / $productPrice) * 100, 2)
                            : 0;

                        return [
                            'orderId'     => $order->id,
                            'orderNumber' => $order->order_number ?: (string) $order->id,
                            'ordered'     => optional($order->created_at)->toDateString(),
                            'channel'     => $order->channel ?: 'Direct',
                            'productId'   => $item->product_id,
                            'product'     => $item->product->name ?? null,
                            'sku'         => $item->product->sku ?? null,
                            'qty'         => (int) $item->quantity,
                            'revenue'     => round($soldPrice * (int) $item->quantity, 2),
                            'discounted'  => $percent,
                        ];
                    });
                })->values(),
            ],
        ]);
    }

    public function analyticsCustomers(Request $request)
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status'  => false,
                'message' => 'Store not found or unauthorized.',
            ], 403);
        }

        $preset  = $request->query('preset', 'today');
        $compare = $request->query('compare', 'previous_period');

        if ($request->filled('dateFrom') && $request->filled('dateTo')) {
            $from = Carbon::parse($request->query('dateFrom'))->startOfDay();
            $to   = Carbon::parse($request->query('dateTo'))->endOfDay();
        } else {
            [$from, $to] = $this->presetRange($preset);
        }

        if ($request->filled('compareFrom') && $request->filled('compareTo')) {
            $compareFrom = Carbon::parse($request->query('compareFrom'))->startOfDay();
            $compareTo   = Carbon::parse($request->query('compareTo'))->endOfDay();
        } elseif ($compare === 'previous_year') {
            $compareFrom = $from->copy()->subYear();
            $compareTo   = $to->copy()->subYear();
        } else {
            $days = $from->diffInDays($to) + 1;
            $compareFrom = $from->copy()->subDays($days);
            $compareTo   = $from->copy()->subDay()->endOfDay();
        }

        $toggle = collect(explode(',', (string) $request->query('columns', 'email,name,type')))
            ->map(fn($col) => trim($col))
            ->filter(fn($col) => in_array($col, ['email', 'name', 'type'], true))
            ->values()
            ->all();

        $columns = array_merge($toggle, [
            'cohort',
            'firstChannel',
            'lastOrderId',
            'lastOrderDate',
            'lastOrderAmount',
            'totalSpent',
        ]);

        $current  = $this->rows($storeId, $from, $to);
        $previous = $this->rows($storeId, $compareFrom, $compareTo);

        $rows = $current->map(function ($row) use ($columns) {
            $out = ['id' => $row['id']];
            foreach ($columns as $col) {
                $out[$col] = $row[$col] ?? null;
            }
            return $out;
        })->values();

        if ($request->query('export') === 'csv') {
            return $this->csv($rows, array_merge(['id'], $columns));
        }

        return response()->json([
            'status'  => true,
            'message' => 'Customer analytics retrieved successfully',
            'data'    => [
                'range' => [
                    'from' => $from->toDateString(),
                    'to'   => $to->toDateString(),
                ],
                'compare' => [
                    'from' => $compareFrom->toDateString(),
                    'to'   => $compareTo->toDateString(),
                ],
                'columns' => $columns,
                'summary' => $this->summary($current, $previous),
                'customers' => $rows,
            ],
        ]);
    }

    private function presetRange(string $preset): array
    {
        $now = now();

        return match ($preset) {
            'yesterday'       => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            'last_week'       => [$now->copy()->subWeek()->startOfWeek(), $now->copy()->subWeek()->endOfWeek()],
            'last_month'      => [$now->copy()->subMonth()->startOfMonth(), $now->copy()->subMonth()->endOfMonth()],
            'week_to_date'    => [$now->copy()->startOfWeek(), $now->copy()->endOfDay()],
            'month_to_date'   => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
            'quarter_to_date' => [$now->copy()->startOfQuarter(), $now->copy()->endOfDay()],
            'year_to_date'    => [$now->copy()->startOfYear(), $now->copy()->endOfDay()],
            default           => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };
    }

    private function rows(int $storeId, Carbon $from, Carbon $to)
    {
        $customers = Customer::query()
            ->with(['orders' => function ($q) use ($storeId) {
                $q->where('store_id', $storeId)
                    ->where('is_draft', 0)
                    ->orderBy('created_at');
            }])
            ->where('store_id', $storeId)
            ->whereHas('orders', function ($q) use ($storeId, $from, $to) {
                $q->where('store_id', $storeId)
                    ->where('is_draft', 0)
                    ->whereBetween('created_at', [$from, $to]);
            })
            ->get();

        return $customers->map(function ($customer) use ($from, $to) {
            $orders  = $customer->orders;
            $inRange = $orders->filter(fn($order) => $order->created_at >= $from && $order->created_at <= $to)->values();
            $before  = $orders->filter(fn($order) => $order->created_at < $from)->count();
            $first   = $orders->sortBy('created_at')->first();
            $last    = $inRange->sortByDesc('created_at')->first();

            return [
                'id'              => $customer->id,
                'email'           => $customer->email,
                'name'            => trim($customer->name ?: ($customer->first_name . ' ' . $customer->last_name)),
                'type'            => $before > 0 ? 'returning' : 'new',
                'cohort'          => $from->toDateString() . ' to ' . $to->toDateString(),
                'firstChannel'    => $first->channel ?: 'Direct',
                'lastOrderId'     => $last->id ?? null,
                'lastOrderNumber' => $last ? ($last->order_number ?: (string) $last->id) : null,
                'lastOrderDate'   => optional($last?->created_at)->toDateString(),
                'lastOrderAmount' => $last ? round((float) $last->total_amount, 2) : 0,
                'totalSpent'      => round((float) $inRange->sum('total_amount'), 2),
            ];
        })->values();
    }

    private function summary($current, $previous): array
    {
        $customers = $current->count();
        $new = $current->where('type', 'new')->count();
        $returning = $customers - $new;

        $prevCustomers = $previous->count();
        $prevNew = $previous->where('type', 'new')->count();
        $prevReturning = $prevCustomers - $prevNew;

        $newRate = $customers ? round(($new / $customers) * 100, 2) : 0;
        $returningRate = $customers ? round(($returning / $customers) * 100, 2) : 0;
        $prevNewRate = $prevCustomers ? round(($prevNew / $prevCustomers) * 100, 2) : 0;
        $prevReturningRate = $prevCustomers ? round(($prevReturning / $prevCustomers) * 100, 2) : 0;

        return [
            'customers' => [
                'value'  => $customers,
                'change' => $this->change($customers, $prevCustomers),
                'from'   => $prevCustomers,
            ],
            'newCustomers' => [
                'value'  => $new,
                'change' => $this->change($new, $prevNew),
                'from'   => $prevNew,
            ],
            'newCustomersRate' => [
                'value'  => $newRate,
                'change' => round($newRate - $prevNewRate, 2),
                'from'   => $prevNewRate,
            ],
            'returningCustomersRate' => [
                'value'  => $returningRate,
                'change' => round($returningRate - $prevReturningRate, 2),
                'from'   => $prevReturningRate,
            ],
        ];
    }

    private function change($current, $previous): float
    {
        if (!$previous) {
            return $current ? 100 : 0;
        }
        return round((($current - $previous) / $previous) * 100, 2);
    }

    private function csv($rows, array $headers)
    {
        $lines = [implode(',', $headers)];

        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(function ($key) use ($row) {
                return '"' . str_replace('"', '""', (string) ($row[$key] ?? '')) . '"';
            }, $headers));
        }

        return response(implode("\n", $lines), 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="customers.csv"',
        ]);
    }

    public function orderIndex(Request $request)
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status'  => false,
                'message' => 'Store not found or unauthorized.',
            ], 403);
        }

        [$from, $to, $compareFrom, $compareTo] = $this->orderRange($request);

        $allowed = [
            'orderId',
            'date',
            'source',
            'subtotal',
            'lineItemDiscounts',
            'orderDiscounts',
            'shipping',
            'tax',
            'total',
        ];

        $columns = collect(explode(',', (string) $request->query('columns', implode(',', $allowed))))
            ->map(fn($col) => trim($col))
            ->filter(fn($col) => in_array($col, $allowed, true))
            ->values()
            ->all();

        if (!$columns) {
            $columns = $allowed;
        }

        $current  = $this->orderRows($storeId, $from, $to);
        $previous = $this->orderRows($storeId, $compareFrom, $compareTo);

        $rows = $current->map(function ($row) use ($columns) {
            $out = ['id' => $row['id']];
            foreach ($columns as $col) {
                $out[$col] = $row[$col] ?? null;
            }
            return $out;
        })->values();

        if ($request->query('export') === 'csv') {
            return $this->orderCsv($rows, array_merge(['id'], $columns));
        }

        return response()->json([
            'status'  => true,
            'message' => 'Order analytics retrieved successfully',
            'data'    => [
                'range'   => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'compare' => ['from' => $compareFrom->toDateString(), 'to' => $compareTo->toDateString()],
                'columns' => $columns,
                'summary' => $this->orderSummary($current, $previous),
                'chart'   => $this->orderChart($current, $previous, $from, $to, $compareFrom),
                'orders'  => $rows,
            ],
        ]);
    }

    private function orderRange(Request $request): array
    {
        $preset  = $request->query('preset', 'today');
        $compare = $request->query('compare', 'previous_period');

        if ($request->filled('dateFrom') && $request->filled('dateTo')) {
            $from = Carbon::parse($request->query('dateFrom'))->startOfDay();
            $to   = Carbon::parse($request->query('dateTo'))->endOfDay();
        } else {
            [$from, $to] = $this->orderPresetRange($preset);
        }

        if ($request->filled('compareFrom') && $request->filled('compareTo')) {
            $compareFrom = Carbon::parse($request->query('compareFrom'))->startOfDay();
            $compareTo   = Carbon::parse($request->query('compareTo'))->endOfDay();
        } elseif ($compare === 'previous_year') {
            $compareFrom = $from->copy()->subYear();
            $compareTo   = $to->copy()->subYear();
        } else {
            $days = $from->diffInDays($to) + 1;
            $compareFrom = $from->copy()->subDays($days);
            $compareTo   = $from->copy()->subDay()->endOfDay();
        }

        return [$from, $to, $compareFrom, $compareTo];
    }

    private function orderPresetRange(string $preset): array
    {
        $now = now();

        return match ($preset) {
            'yesterday'       => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            'last_week'       => [$now->copy()->subWeek()->startOfWeek(), $now->copy()->subWeek()->endOfWeek()],
            'last_month'      => [$now->copy()->subMonth()->startOfMonth(), $now->copy()->subMonth()->endOfMonth()],
            'week_to_date'    => [$now->copy()->startOfWeek(), $now->copy()->endOfDay()],
            'month_to_date'   => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
            'quarter_to_date' => [$now->copy()->startOfQuarter(), $now->copy()->endOfDay()],
            'year_to_date'    => [$now->copy()->startOfYear(), $now->copy()->endOfDay()],
            default           => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };
    }

    private function orderRows(int $storeId, Carbon $from, Carbon $to)
    {
        return Order::query()
            ->with([
                'items:id,order_id,product_id,quantity,price',
                'items.product:id,price',
            ])
            ->where('store_id', $storeId)
            ->where('is_draft', 0)
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('id')
            ->get()
            ->map(function ($order) {
                $subtotal = round((float) $order->items->sum(fn($item) => $item->price * $item->quantity), 2);
                $lineDiscount = round((float) $order->items->sum(function ($item) {
                    $productPrice = (float) optional($item->product)->price;
                    $sold = (float) $item->price;
                    return $productPrice > $sold ? ($productPrice - $sold) * (int) $item->quantity : 0;
                }), 2);

                return [
                    'id'                => $order->id,
                    'orderId'           => $order->order_number ?: (string) $order->id,
                    'date'              => optional($order->created_at)->toDateString(),
                    'source'            => $order->channel ?: 'Direct',
                    'subtotal'          => $subtotal,
                    'lineItemDiscounts' => $lineDiscount,
                    'orderDiscounts'    => round((float) $order->discountAmount + (float) $order->manual_discount, 2),
                    'shipping'          => round((float) $order->shipping_cost, 2),
                    'tax'               => round((float) ($order->tax ?? 0), 2),
                    'total'             => round((float) $order->total_amount, 2),
                    'units'             => (int) $order->items->sum('quantity'),
                    'discounted'        => (float) $order->discountAmount > 0
                        || (float) $order->manual_discount > 0
                        || !empty($order->coupon_code)
                        || $lineDiscount > 0,
                ];
            })
            ->values();
    }

    private function orderSummary($current, $previous): array
    {
        $count = $current->count();
        $units = (int) $current->sum('units');
        $revenue = round((float) $current->sum('total'), 2);
        $discounted = $current->where('discounted', true)->count();
        $discountRate = $count ? round(($discounted / $count) * 100, 2) : 0;
        $aov = $count ? round($revenue / $count, 2) : 0;

        $prevCount = $previous->count();
        $prevUnits = (int) $previous->sum('units');
        $prevRevenue = round((float) $previous->sum('total'), 2);
        $prevDiscounted = $previous->where('discounted', true)->count();
        $prevDiscountRate = $prevCount ? round(($prevDiscounted / $prevCount) * 100, 2) : 0;
        $prevAov = $prevCount ? round($prevRevenue / $prevCount, 2) : 0;

        return [
            'orderCount' => ['value' => $count, 'change' => $this->orderChange($count, $prevCount), 'from' => $prevCount],
            'unitsSold'  => ['value' => $units, 'change' => $this->orderChange($units, $prevUnits), 'from' => $prevUnits],
            'revenue'    => ['value' => $revenue, 'change' => $this->orderChange($revenue, $prevRevenue), 'from' => $prevRevenue],
            'percentDiscounted' => ['value' => $discountRate, 'change' => round($discountRate - $prevDiscountRate, 2), 'from' => $prevDiscountRate],
            'aov'        => ['value' => $aov, 'change' => $this->orderChange($aov, $prevAov), 'from' => $prevAov],
        ];
    }

    private function orderChart($current, $previous, Carbon $from, Carbon $to, Carbon $compareFrom): array
    {
        $currentByDate  = $current->groupBy('date');
        $previousByDate = $previous->groupBy('date');
        $points = [];
        $day = 0;
        $cursor = $from->copy()->startOfDay();

        while ($cursor <= $to) {
            $currentKey = $cursor->toDateString();
            $compareKey = $compareFrom->copy()->addDays($day)->toDateString();

            $points[] = [
                'date'    => $currentKey,
                'label'   => $cursor->format('n/j'),
                'current' => isset($currentByDate[$currentKey]) ? $currentByDate[$currentKey]->count() : 0,
                'compare' => isset($previousByDate[$compareKey]) ? $previousByDate[$compareKey]->count() : 0,
            ];

            $cursor->addDay();
            $day++;
        }

        return $points;
    }

    private function orderChange($current, $previous): float
    {
        if (!$previous) {
            return $current ? 100 : 0;
        }
        return round((($current - $previous) / $previous) * 100, 2);
    }

    private function orderCsv($rows, array $headers)
    {
        $lines = [implode(',', $headers)];

        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(function ($key) use ($row) {
                return '"' . str_replace('"', '""', (string) ($row[$key] ?? '')) . '"';
            }, $headers));
        }

        return response(implode("\n", $lines), 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="orders.csv"',
        ]);
    }
}
