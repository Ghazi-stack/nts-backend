<?php

namespace App\Http\Controllers\api\Web;

use App\Mail\WelcomeLoginMail;
use Exception;
use App\Models\Cart;
use App\Models\User;
use App\Models\Order;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\OrderItem;
use App\Models\OrderNote;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Exports\OrdersExport;
use App\Models\ReturnRequest;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Mail\PaymentInvoiceMail;
use App\Models\BillingInformation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Maatwebsite\Excel\Facades\Excel;
use App\Http\Resources\OrderResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ProductListResource;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\ShipmentResource;
use Illuminate\Support\Facades\Storage;
use App\Http\Resources\OrderNoteResource;
use App\Mail\OrderStatusUpdatedMail;
use App\Models\SavedAddress;
use App\Http\Resources\ReturnRequestResource;
use App\Mail\OrderPlacedMail;
use App\Models\CartShippingRate;
use App\Models\CheckoutFormData;
use App\Models\CouponCode;
use App\Models\CouponUsage;
use App\Models\Logo;
use App\Models\ManualDiscount;
use App\Models\OrderCartItemProduct;
use App\Models\OrderTimeline;
use App\Models\Store;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use App\Services\DraftQuoteService;

class OrderController extends Controller
{

    public function __construct(protected DraftQuoteService $draftQuote) {}
    public function index(Request $request)
    {
        try {
            $perPage   = min((int) $request->query('perPage', 20), 50);
            $keyword   = $request->query('keyword');
            $status    = $request->query('status');
            $productId = $request->query('productId');
            $storeId   = current_store_id();

            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not found or unauthorized.',
                ], 403);
            }

            $query = Order::query()
                ->with([
                    'items' => function ($q) {
                        $q->select('id', 'order_id', 'product_id', 'quantity', 'price')
                            ->with([
                                'product' => function ($p) {
                                    $p->with([
                                        'images',
                                        'brand',
                                        'categories:id',
                                    ]);
                                }
                            ]);
                    },
                    'customer',
                    'billingInformation',
                    'notes:id,order_id,note',
                    'payment',
                    'shipment:id,order_id,tracking_number,tracking_carrie,date_shipped,shipping_method,shipped_by,packing_slip_note',
                    'manualDiscounts:id,order_id,discount_amount',
                ])
                ->withCount('messages')
                ->where('store_id', $storeId)
                ->where('is_draft', 0)
                ->when($status, function ($q) use ($status) {
                    if ($status === 'High Risk') {
                        $q->whereHas('payment', function ($p) {
                            $p->where(function ($r) {
                                $r->whereIn('risk_level', ['highest', 'elevated'])
                                    ->orWhere('risk_score', '>=', 70);
                            });
                        });
                    } elseif ($status === 'Incomplete') {
                        $q->where('status', 'Pending');
                    } else {
                        $q->where('status', $status);
                    }
                })
                ->when($productId, function ($q) use ($productId) {
                    $q->whereHas('items', function ($item) use ($productId) {
                        $item->where('product_id', (int) $productId);
                    });
                })
                ->when($keyword, function ($q) use ($keyword) {
                    $keyword = trim($keyword);
                    $q->where(function ($query) use ($keyword) {
                        $query->where('id', 'like', "%{$keyword}%")
                            ->orWhere('order_number', 'like', "%{$keyword}%")
                            ->orWhere('status', 'like', "%{$keyword}%")
                            ->orWhere('total_amount', 'like', "%{$keyword}%")
                            ->orWhereExists(function ($sub) use ($keyword) {
                                $sub->select(\DB::raw(1))
                                    ->from('customers')
                                    ->whereColumn('customers.id', 'orders.customer_id')
                                    ->where(function ($c) use ($keyword) {
                                        $c->where('first_name', 'like', "%{$keyword}%")
                                            ->orWhere('last_name', 'like', "%{$keyword}%")
                                            ->orWhere('email', 'like', "%{$keyword}%")
                                            ->orWhere('phone', 'like', "%{$keyword}%");
                                    });
                            })
                            ->orWhereExists(function ($sub) use ($keyword) {
                                $sub->select(\DB::raw(1))
                                    ->from('billing_information')
                                    ->whereColumn('billing_information.order_id', 'orders.id')
                                    ->where(function ($b) use ($keyword) {
                                        $b->where('first_name', 'like', "%{$keyword}%")
                                            ->orWhere('last_name', 'like', "%{$keyword}%")
                                            ->orWhere('phone', 'like', "%{$keyword}%")
                                            ->orWhere('payment_method', 'like', "%{$keyword}%")
                                            ->orWhere('shipping_method', 'like', "%{$keyword}%");
                                    });
                            });
                    });
                });

            if ($request->filled('keywords')) {
                $keywords = trim($request->input('keywords'));

                $customerIds = \App\Models\Customer::where('store_id', $storeId)
                    ->where(function ($c) use ($keywords) {
                        $c->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$keywords}%"])
                            ->orWhere('first_name', 'like', "%{$keywords}%")
                            ->orWhere('last_name', 'like', "%{$keywords}%");
                    })->pluck('id');

                $billingIds = \App\Models\BillingInformation::where('store_id', $storeId)
                    ->where(function ($b) use ($keywords) {
                        $b->where('first_name', 'like', "%{$keywords}%")
                            ->orWhere('last_name', 'like', "%{$keywords}%")
                            ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$keywords}%"])
                            ->orWhere('payment_method', 'like', "%{$keywords}%")
                            ->orWhere('shipping_method', 'like', "%{$keywords}%");
                    })->pluck('id');

                $query->where(function ($q) use ($keywords, $customerIds, $billingIds) {
                    $q->where('order_number', 'like', "%{$keywords}%")
                        ->orWhere('status', 'like', "%{$keywords}%");

                    if (is_numeric($keywords)) {
                        $q->orWhere('id', $keywords)
                            ->orWhere('total_amount', $keywords);
                    }

                    if ($customerIds->isNotEmpty()) {
                        $q->orWhereIn('customer_id', $customerIds);
                    }

                    if ($billingIds->isNotEmpty()) {
                        $q->orWhereIn('billing_information_id', $billingIds);
                    }

                    $q->orWhereHas('items.product', function ($p) use ($keywords) {
                        $p->where('name', 'like', "%{$keywords}%")
                            ->orWhere('sku', 'like', "%{$keywords}%");
                    });
                });
            }

            if ($request->filled('paymentMethod')) {
                $query->whereHas('billingInformation', function ($b) use ($request) {
                    $b->where('payment_method', $request->input('paymentMethod'));
                });
            }

            if ($request->filled('shippingProvider') && $request->input('shippingProvider') !== 'any') {
                $query->where('tracking_carrier_name', $request->input('shippingProvider'));
            }

            if ($request->filled('shippingMethod') && $request->input('shippingMethod') !== 'any') {
                $query->whereHas('billingInformation', function ($b) use ($request) {
                    $b->where('shipping_method', $request->input('shippingMethod'));
                });
            }

            if ($request->filled('fulfillmentSource') && $request->input('fulfillmentSource') !== 'any') {
                $query->where('fulfillment_source', $request->input('fulfillmentSource'));
            }

            if ($request->filled('coupon') && $request->input('coupon') !== 'any') {
                $query->where('coupon_code', $request->input('coupon'));
            }

            if ($request->filled('guest') && $request->input('guest') === 'true') {
                $query->whereNull('customer_id');
            }

            if (
                $request->filled('preorderYes') && $request->input('preorderYes') === 'true'
                && (!$request->filled('preorderNo') || $request->input('preorderNo') === 'false')
            ) {
                $query->where('channel', 'preorder');
            } elseif (
                $request->filled('preorderNo') && $request->input('preorderNo') === 'true'
                && (!$request->filled('preorderYes') || $request->input('preorderYes') === 'false')
            ) {
                $query->where(function ($q) {
                    $q->where('channel', '!=', 'preorder')
                        ->orWhereNull('channel');
                });
            }

            if ($request->filled('orderIdFrom')) {
                $query->where('id', '>=', (int) $request->input('orderIdFrom'));
            }

            if ($request->filled('orderIdTo')) {
                $query->where('id', '<=', (int) $request->input('orderIdTo'));
            }

            if ($request->filled('orderTotalFrom')) {
                $query->where('total_amount', '>=', (float) $request->input('orderTotalFrom'));
            }

            if ($request->filled('orderTotalTo')) {
                $query->where('total_amount', '<=', (float) $request->input('orderTotalTo'));
            }

            if ($request->filled('dateRange')) {
                $dateType = $request->input('dateType', 'order-date');

                if ($dateType === 'shipped-date') {
                    $columns = ['shipped_at'];
                } elseif ($dateType === 'both') {
                    $columns = ['created_at', 'shipped_at'];
                } else {
                    $columns = ['created_at'];
                }

                $dateRange = $request->input('dateRange');

                if ($dateRange === 'custom' && $request->filled('dateFrom') && $request->filled('dateTo')) {
                    $query->where(function ($q) use ($columns, $request) {
                        foreach ($columns as $i => $col) {
                            $method = $i === 0 ? 'where' : 'orWhere';
                            $q->$method(function ($sub) use ($col, $request) {
                                $sub->whereDate($col, '>=', $request->input('dateFrom'))
                                    ->whereDate($col, '<=', $request->input('dateTo'));
                            });
                        }
                    });
                } else {
                    $dateMap = [
                        'Yesterday'   => today()->subDay(),
                        'Today'       => today(),
                        'Last7Days'   => now()->subDays(7),
                        'Last30Days'  => now()->subDays(30),
                        'Last90Days'  => now()->subDays(90),
                        'Last365Days' => now()->subDays(365),
                    ];

                    if (isset($dateMap[$dateRange])) {
                        $query->where(function ($q) use ($columns, $dateRange, $dateMap) {
                            foreach ($columns as $i => $col) {
                                $method = $i === 0 ? 'where' : 'orWhere';
                                if ($dateRange === 'Yesterday' || $dateRange === 'Today') {
                                    $q->$method(function ($sub) use ($col, $dateMap, $dateRange) {
                                        $sub->whereDate($col, $dateMap[$dateRange]);
                                    });
                                } else {
                                    $q->$method(function ($sub) use ($col, $dateMap, $dateRange) {
                                        $sub->whereDate($col, '>=', $dateMap[$dateRange]);
                                    });
                                }
                            }
                        });
                    }
                }
            }

            if ($request->filled('deletedOrder') && $request->input('deletedOrder') !== 'ignore') {
                $deleted = $request->input('deletedOrder');
                if ($deleted === 'only') {
                    $query->onlyTrashed();
                } elseif ($deleted === 'with') {
                    $query->withTrashed();
                }
            }

            if ($request->filled('sortBy')) {
                $sortBy = $request->input('sortBy', 'id');
                $sortDirection = $request->input('sortDirection', 'DESC');
                $allowedSorts = ['id', 'order_number', 'total_amount', 'created_at', 'updated_at', 'status', 'shipped_at'];
                if (!in_array($sortBy, $allowedSorts)) {
                    $sortBy = 'id';
                }
                $query->orderBy($sortBy, $sortDirection);
            } else {
                $query->latest('id');
            }

            $orders = $query->paginate($perPage);

            $orderIds = $orders->getCollection()->pluck('id');

            $overrides = \App\Models\OrderCartItemProduct::query()
                ->whereIn('order_id', $orderIds)
                ->get()
                ->groupBy(fn($row) => $row->order_id . '-' . $row->product_id);

            $orders->getCollection()->transform(function ($order) use ($overrides) {
                foreach ($order->items as $item) {
                    $key = $order->id . '-' . $item->product_id;
                    $row = $overrides->get($key)?->last();

                    if (!$row) {
                        continue;
                    }

                    $item->quantity = $row->qty;
                    $item->price    = $row->price;

                    if ($item->relationLoaded('product') && $item->product) {
                        $cloned = clone $item->product;
                        $cloned->setAttribute('price', $row->price);
                        $item->setRelation('product', $cloned);
                    }
                }

                return $order;
            });

            return response()->json([
                'status'     => true,
                'message'    => 'Orders retrieved successfully',
                'data'       => OrderResource::collection($orders),
                'pagination' => [
                    'total'       => $orders->total(),
                    'count'       => $orders->count(),
                    'perPage'     => $orders->perPage(),
                    'currentPage' => $orders->currentPage(),
                    'totalPages'  => $orders->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve orders',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function overview(Request $request)
    {
        try {
            $perPage = min((int) $request->query('perPage', 20), 50);
            $status  = strtolower(trim((string) $request->query('status', 'recent')));

            $storeId = current_store_id();

            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not found or unauthorized.',
                ], 403);
            }

            $statusMap = [
                'pending'   => ['Awaiting Payment', 'Pending', 'Awaiting Pickup', 'Awaiting Fulfillment', 'Partially Shipped', 'Awaiting Shipment'],
                'completed' => ['Shipped', 'Completed'],
                'refunded'  => ['Refunded', 'Cancelled', 'Declined', 'Disputed', 'Partially Refunded'],
            ];

            if (!in_array($status, ['recent', 'pending', 'completed', 'refunded'], true)) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Invalid status. Use recent, pending, completed or refunded.',
                ], 422);
            }

            $orders = Order::query()
                ->with([
                    'items' => function ($q) {
                        $q->select('id', 'order_id', 'product_id', 'quantity', 'price')
                            ->with([
                                'product' => function ($p) {
                                    $p->with([
                                        'images',
                                        'brand',
                                        'categories:id',
                                    ]);
                                }
                            ]);
                    },
                    'customer',
                    'billingInformation',
                    'notes:id,order_id,note',
                    'payment',
                    'shipment:id,order_id,tracking_number,tracking_carrie,date_shipped,shipping_method,shipped_by,packing_slip_note',
                ])
                ->withCount('messages')
                ->where('store_id', $storeId)
                ->where('is_draft', 0)
                ->when($status !== 'recent', function ($q) use ($status, $statusMap) {
                    $q->whereIn('status', $statusMap[$status]);
                })
                ->latest('id')
                ->paginate($perPage);

            return response()->json([
                'status'  => true,
                'message' => 'Orders retrieved successfully',
                'data'    => OrderResource::collection($orders),
                'pagination' => [
                    'total'       => $orders->total(),
                    'count'       => $orders->count(),
                    'perPage'     => $orders->perPage(),
                    'currentPage' => $orders->currentPage(),
                    'totalPages'  => $orders->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve orders',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function detail(Request $request)
    {
        try {

            $request->validate([
                'orderId' => 'required|string',
            ]);

            $storeId = current_store_id();

            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not found or unauthorized.',
                ], 403);
            }

            $order = Order::with([
                'items.product.images',
                'items.product.brand',
                'items.product.categories.parent',
                'items.product.content',
                'items.product.seo',
                'items.product.identifiers',
                'items.product.reviews.user',
                'customer',
                'billingInformation',
                'payment',
            ])
                ->withCount('messages')
                ->where('store_id', $storeId)
                ->where('is_draft', 0)
                ->where('order_number', $request->orderId)
                ->first();

            if (!$order) {
                return response()->json([
                    'status' => false,
                    'message' => 'Order not found.',
                ], 404);
            }

            return response()->json([
                'status' => true,
                'message' => 'Order retrieved successfully.',
                'data' => [

                    'id' => $order->id,
                    'orderNumber' => $order->order_number,
                    'status' => $order->status,
                    'shippingCost' => $order->shipping_cost,
                    'staffNotes' => $order->staff_notes,
                    'trackingNumber' => $order->tracking_number,
                    'shippedAt' => $order->shipped_at,
                    'discountAmount' => $order->discountAmount,
                    'manualDiscount' => $order->manual_discount,
                    'couponCode'     => $order->coupon_code,
                    'totalAmount' => $order->total_amount,
                    'createdAt' => $order->created_at,
                    'updatedAt' => $order->updated_at,

                    // order table ka JSON column — poora array/object yahan aayega
                    'billingAddress' => $order->billing_address,

                    'customer' => $order->customer ? [
                        'id' => $order->customer->id,
                        'storeId' => $order->customer->store_id,
                        'firstName' => $order->customer->first_name,
                        'lastName' => $order->customer->last_name,
                        'companyName' => $order->customer->company_name,
                        'email' => $order->customer->email,
                        'customerGroup' => $order->customer->customer_group,
                        'phone' => $order->customer->phone,
                        'storeCredit' => $order->customer->store_credit,
                        'receiveReviewEmails' => (bool) $order->customer->receive_review_emails,
                        'forcePasswordReset' => (bool) $order->customer->force_password_reset,
                        'taxExemptCode' => $order->customer->tax_exempt_code,
                        'country' => $order->customer->country,
                        'addressLine1' => $order->customer->address_line_1,
                        'addressLine2' => $order->customer->address_line_2,
                        'state' => $order->customer->state,
                        'createdAt' => $order->customer->created_at,
                        'updatedAt' => $order->customer->updated_at,
                    ] : null,

                    'products' => $order->items
                        ->filter(fn($item) => $item->product)
                        ->map(function ($item) use ($order) {

                            $override = \App\Models\OrderCartItemProduct::query()
                                ->where('order_id', $order->id)
                                ->where('product_id', $item->product_id)
                                ->latest('id')
                                ->first();

                            $qty   = $override ? $override->qty : $item->quantity;
                            $price = $override ? $override->price : $item->price;

                            $product = (new ProductResource($item->product))->toArray(request());

                            $product['quantity'] = $qty;
                            $product['price']    = $price;

                            return $product;
                        })->values(),

                ],
            ]);
        } catch (Exception $e) {

            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve order.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(Request $request, $id)
    {
        try {
            $user = auth('sanctum')->user();
            $sessionId = $request->header('X-Session-ID') ?? session()->getId();
            $storeId = current_store_id();

            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not found or unauthorized.',
                ], 403);
            }

            $orderQuery = Order::query()
                ->with([
                    'items' => function ($q) {
                        $q->select('id', 'order_id', 'product_id', 'quantity', 'price')
                            ->with([
                                'product' => function ($p) {
                                    $p->with([
                                        'images',
                                        'brand',
                                        'categories:id',
                                    ]);
                                }
                            ]);
                    },
                    'customer',
                    'billingInformation',
                    'notes:id,order_id,note',
                    'payment',
                    'shipment:id,order_id,tracking_number,tracking_carrie,date_shipped,shipping_method,shipped_by,packing_slip_note',
                    'manualDiscounts:id,order_id,discount_amount',
                ])
                ->withCount('messages')
                ->where('id', $id)
                ->where('store_id', $storeId);

            $isAdmin = $user && (int) ($user->userRole ?? 0) === 1;

            if (!$isAdmin) {
                $orderQuery->when($user, fn($q) => $q->where('user_id', $user->id))
                    ->when(!$user, fn($q) => $q->where('session_id', $sessionId));
            }

            $order = $orderQuery->firstOrFail();

            return response()->json([
                'status'  => true,
                'message' => 'Order detail retrieved successfully',
                'data'    => new OrderResource($order),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Order not found or access denied',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve order detail',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function showByOrderId(Request $request)
    {
        try {
            $user = auth('sanctum')->user();
            $sessionId = $request->header('X-Session-ID') ?? session()->getId();
            $orderId = $request->input('orderId');

            $orderQuery = Order::with(['items.product.images', 'customer', 'billingInformation'])
                ->where('order_number', $orderId)
                ->where('store_id', current_store_id());

            $orderQuery->when($user, fn($q) => $q->where('user_id', $user->id))
                ->when(!$user, fn($q) => $q->where('session_id', $sessionId));

            $order = $orderQuery->firstOrFail();
            $customer = $order->customer;

            // Group order items by destination (if you have multiple destinations, else single)
            $shippingDestinations = $order->items
                ->groupBy(fn($item) => $item->destination_id ?? 0) // fallback to 0 if no destination
                ->map(function ($items, $key) use ($order, $customer) {
                    $billing = $order->billingInformation; // adjust if you store per-destination billing
                    return [
                        "address" => [
                            "firstName" => $billing->first_name ?? null,
                            "lastName" => $billing->last_name ?? null,
                            "phone" => $billing->phone ?? null,
                            "companyName" => $billing->company_name ?? null,
                            "addressLine1" => $billing->address_line_1 ?? null,
                            "addressLine2" => $billing->address_line_2 ?? null,
                            "city" => $billing->city ?? null,
                            "state" => $billing->state ?? null,
                            "zip" => $billing->zip ?? null,
                            "country" => $billing->country ?? null,
                            "email" => $billing->email ?? $customer->email ?? null
                        ],
                        "products" => $items->map(fn($item) => [
                            'productId' => $item->product_id,
                            'quantity' => $item->quantity,
                            'price' => $item->price
                        ])->values()
                    ];
                })->values();

            // Build order response
            $orderResponse = [
                "id" => $order->id,
                "orderNumber" => $order->order_number,
                "status" => ucfirst($order->status),
                "shippingCost" => $order->shipping_cost,
                "comments" => $order->comments,
                "staffNotes" => $order->staff_notes,
                "isDraft" => $order->is_draft,
                "totalAmount" => $order->total_amount,
                "trackingNumber" => $order->tracking_number,
                "carrierCode" => $order->shipping_provider,
                "carrierName" => $order->shipping_provider,
                "shippedAt" => $order->shipped_at,
                "customerId" => $customer->id ?? null,
                "customerGroup" => $customer->customer_group ?? null,
                "receiveOffers" => $customer->receive_review_emails ?? null,
                "customer" => $customer ? new CustomerResource($customer) : null,
                "billingInformation" => [
                    "firstName" => $order->billingInformation->first_name ?? null,
                    "lastName" => $order->billingInformation->last_name ?? null,
                    "phone" => $order->billingInformation->phone ?? null,
                    "companyName" => $order->billingInformation->company_name ?? null,
                    "addressLine1" => $order->billingInformation->address_line_1 ?? null,
                    "addressLine2" => $order->billingInformation->address_line_2 ?? null,
                    "city" => $order->billingInformation->city ?? null,
                    "state" => $order->billingInformation->state ?? null,
                    "zip" => $order->billingInformation->zip ?? null,
                    "country" => $order->billingInformation->country ?? null,
                    "email" => $order->billingInformation->email ?? $customer->email ?? null,
                    "paymentMethod" => $order->billingInformation->payment_method ?? null,
                    "shippingMethod" => $order->billingInformation->shipping_method ?? null,
                    "shippingData"   => $order->billingInformation->shipping_data['display_name'] ?? null,
                ],
                "paymentMethod" => [
                    "method" => $order->billingInformation->payment_method ?? null,
                    "cardType" => $order->billingInformation->card_type ?? null,
                    "cardholderName" => $order->billingInformation->cardholder_name ?? null,
                    "creditCardNo" => $order->billingInformation->credit_card_no ?? null,
                    "ccv2Value" => $order->billingInformation->ccv2_value ?? null,
                    "expirationMonth" => $order->billingInformation->expiration_month ?? null,
                    "expirationYear" => $order->billingInformation->expiration_year ?? null,
                    "emailInvoice" => $order->billingInformation->email_invoice ?? null
                ],
                "products" => $order->items->map(function ($item) {
                    return (new ProductListResource($item->product))->additional([
                        'quantity' => $item->quantity,
                        'price' => $item->price,
                    ]);
                }),
                "shippingDestinations" => $shippingDestinations,
                "shippingMethod" => [
                    "provider" => $order->shipping_provider,
                    "cost" => $order->shipping_cost,
                    "method" => $order->shipping_method
                ]
            ];

            return response()->json([
                'status' => true,
                'message' => 'Order detail retrieved successfully',
                'order' => [$orderResponse]
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Order not found or access denied',
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve order detail',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    public function myOrdersList(Request $request)
    {
        try {
            $user = auth('sanctum')->user();
            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not authenticated.'
                ], 401);
            }

            $storeId = current_store_id();

            $orders = Order::where('user_id', $user->id)
                ->where('store_id', $storeId)
                ->where('is_draft', 0)
                ->select('id', 'order_number', 'created_at', 'total_amount')
                ->latest()
                ->get()
                ->map(function ($order) {
                    return [
                        'id'           => $order->id,
                        'orderNumber'  => $order->order_number,
                        'createdAt'    => $order->created_at?->format('M d, Y'),
                        'totalAmount'  => $order->total_amount,
                        'label'        => 'Order #' . $order->order_number . ' - Placed on ' . $order->created_at?->format('M d, Y') . ' for $' . number_format($order->total_amount, 2),
                    ];
                });

            return response()->json([
                'status' => true,
                'message' => 'Orders retrieved successfully.',
                'data' => $orders,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // public function show(Request $request, $id)
    // {
    //     try {
    //         $user = auth('sanctum')->user();
    //         $sessionId = $request->header('X-Session-ID') ?? session()->getId();

    //         $order = Order::with(['items.product.images', 'customer', 'billingInformation'])
    //             ->where('id', $id)
    //             ->where('store_id', current_store_id());

    //         $isAdmin = $user && (int)($user->userRole ?? 0) === 1;

    //         if (!$isAdmin) {
    //             $order->when($user, fn ($q) => $q->where('user_id', $user->id))
    //                   ->when(!$user, fn ($q) => $q->where('session_id', $sessionId));
    //         }

    //         $order = $order->firstOrFail();

    //         return response()->json([
    //             'status' => true,
    //             'message' => 'Order detail retrieved successfully',
    //             'data' => new OrderResource($order),
    //         ]);
    //     } catch (ModelNotFoundException $e) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Order not found or access denied',
    //         ], 200);
    //     } catch (Exception $e) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Failed to retrieve order detail',
    //             'error' => $e->getMessage(),
    //         ], 500);
    //     }
    // }

    // public function store(Request $request)
    // {
    //     try {
    //         $validated = $request->validate([
    //             'firstName'      => 'required|string|max:255',
    //             'lastName'       => 'required|string|max:255',
    //             'companyName'    => 'nullable|string|max:255',
    //             'email'          => 'required|email',
    //             'phone'          => 'nullable|string|max:20',
    //             'addressLine1'   => 'required|string|max:255',
    //             'addressLine2'   => 'nullable|string|max:255',
    //             'city'           => 'nullable|string|max:100',
    //             'state'          => 'nullable|string|max:100',
    //             'zip'            => 'nullable|string|max:20',
    //             'country'        => 'nullable|string|max:100',
    //             'paymentMethod'  => 'required|string|max:100',
    //             'shippingMethod' => 'required|string|max:100',
    //             'shippingCost'   => 'required|numeric',
    //             'comments'       => 'nullable|string|max:1000',
    //         ]);

    //         $user = auth('sanctum')->user();
    //         $sessionId = $request->header('X-Session-ID') ?? session()->getId();

    //         $cartItems = Cart::with('product')
    //             ->when($user, fn ($q) => $q->where('user_id', $user->id))
    //             ->when(!$user, fn ($q) => $q->where('session_id', $sessionId))
    //             ->get();

    //         if ($cartItems->isEmpty()) {
    //             return response()->json([
    //                 'status' => false,
    //                 'message' => 'Cart is empty.',
    //             ], 400);
    //         }

    //         $cartTotal = $cartItems->sum(fn ($item) => optional($item->product)->price * $item->quantity);
    //         $shippingCost = $validated['shippingCost'];
    //         $totalAmount  = $cartTotal + $shippingCost;

    //         // Save billing info
    //         $billing = $this->saveBillingInformation([
    //             'first_name'      => $validated['firstName'],
    //             'last_name'       => $validated['lastName'],
    //             'company_name'    => $validated['companyName'] ?? null,
    //             'email'           => $validated['email'],
    //             'phone'           => $validated['phone'] ?? null,
    //             'address_line_1'  => $validated['addressLine1'],
    //             'address_line_2'  => $validated['addressLine2'] ?? null,
    //             'city'            => $validated['city'] ?? null,
    //             'state'           => $validated['state'] ?? null,
    //             'zip'             => $validated['zip'] ?? null,
    //             'country'         => $validated['country'] ?? null,
    //             'payment_method'  => $validated['paymentMethod'],
    //             'shipping_method' => $validated['shippingMethod'],
    //         ], $user);

    //         // Create order
    //         $order = Order::create([
    //             'store_id'               => current_store_id(),
    //             'user_id'                => $user?->id,
    //             'session_id'             => $user ? null : $sessionId,
    //             'order_number'           => 'ORD-' . strtoupper(Str::random(8)),
    //             'billing_information_id' => $billing->id,
    //             'status'                 => 'Pending',
    //             'shipping_cost'          => $shippingCost,
    //             'comments'               => $validated['comments'] ?? null,
    //             'total_amount'           => $totalAmount,
    //         ]);

    //         // Items
    //         foreach ($cartItems as $item) {
    //             if (!$item->product) continue;

    //             $order->items()->create([
    //                 'store_id'   => current_store_id(),
    //                 'product_id' => $item->product_id,
    //                 'quantity'   => $item->quantity,
    //                 'price'      => $item->product->price,
    //             ]);
    //             $product = $item->product;
    //             $product->increment('sold_count', $item->quantity);
    //         }

    //         // Clear cart
    //         Cart::when($user, fn ($q) => $q->where('user_id', $user->id))
    //             ->when(!$user, fn ($q) => $q->where('session_id', $sessionId))
    //             ->delete();

    //         return response()->json([
    //             'status'  => true,
    //             'message' => 'Order placed successfully.',
    //             'data'    => new OrderResource($order->load('items.product.images', 'billingInformation','customer')),
    //         ]);
    //     } catch (ValidationException $e) {
    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Validation failed.',
    //             'errors'  => $e->errors(),
    //         ], 422);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Order creation failed.',
    //             'error'   => $e->getMessage(),
    //         ], 500);
    //     }
    // }

    // public function update(Request $request, $id)
    // {
    //     try {
    //         $order = Order::with('billingInformation')
    //             ->where('id', $id)
    //             ->where('store_id', current_store_id())
    //             ->firstOrFail();

    //         // Updatable fields on orders table only
    //         $orderFields = [
    //             'shipping_cost',
    //             'total_amount',
    //             'coupon_code',
    //             'comments',
    //             'staff_notes',
    //             'status',
    //             'is_draft',
    //             'channel',
    //             'fulfillment_source',
    //             'tracking_number',
    //             'tracking_carrier_code',
    //             'tracking_carrier_name',
    //             'shipped_at',
    //         ];

    //         foreach ($orderFields as $field) {
    //             if ($request->has($field)) {
    //                 $order->{$field} = $request->input($field);
    //             }
    //         }

    //         // Updatable fields that are now in billing_information
    //         $billing = $order->billingInformation;
    //         if ($billing) {
    //             $billingMap = [
    //                 'first_name'      => ['first_name', 'firstName'],
    //                 'last_name'       => ['last_name', 'lastName'],
    //                 'company_name'    => ['company_name', 'companyName'],
    //                 'email'           => ['email'],
    //                 'phone'           => ['phone'],
    //                 'address_line_1'  => ['address_line_1', 'addressLine1'],
    //                 'address_line_2'  => ['address_line_2', 'addressLine2'],
    //                 'city'            => ['city'],
    //                 'state'           => ['state'],
    //                 'zip'             => ['zip'],
    //                 'country'         => ['country'],
    //                 'payment_method'  => ['payment_method', 'paymentMethod'],
    //                 'shipping_method' => ['shipping_method', 'shippingMethod'],
    //             ];

    //             foreach ($billingMap as $column => $aliases) {
    //                 foreach ($aliases as $key) {
    //                     if ($request->has($key)) {
    //                         $billing->{$column} = $request->input($key);
    //                         break;
    //                     }
    //                 }
    //             }

    //             $billing->save();
    //         }

    //         $order->save();

    //         return response()->json([
    //             'status'  => true,
    //             'message' => 'Order updated successfully.',
    //             'data'    => new OrderResource($order->fresh()->load('items.product.images', 'billingInformation','customer')),
    //         ]);
    //     } catch (ModelNotFoundException $e) {
    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Order not found or unauthorized.',
    //         ], 404);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Order update failed.',
    //             'error'   => $e->getMessage(),
    //         ], 500);
    //     }
    // }


    public function store(Request $request)
    {
        try {

            $validated = $request->validate([
                'orderId'        => 'nullable|integer|exists:orders,id',
                'userType'       => 'nullable|string|max:100',
                'deviceType'     => 'nullable|string|max:1000',
                'firstName'      => 'required_if:isMultiAddress,false|string|max:255',
                'lastName'       => 'required_if:isMultiAddress,false|string|max:255',
                'companyName'    => 'nullable|string|max:255',
                'email'          => 'required|email',
                'phone'          => 'nullable|string|max:20',
                'addressLine1'   => 'required_if:isMultiAddress,false|string|max:255',
                'addressLine2'   => 'nullable|string|max:255',
                'city'           => 'nullable|string|max:100',
                'state'          => 'nullable|string|max:100',
                'zip'            => 'nullable|string|max:20',
                'country'        => 'nullable|string|max:100',
                'newsletter'     => 'nullable|boolean',

                'paymentMethod'  => 'required|string|max:100',

                'shippingMethod' => 'nullable|string|max:100',
                'shippingCost'   => 'nullable|numeric',
                'shippingData'   => 'nullable',

                'comments'       => 'nullable|string|max:1000',
                'discountAmount' => 'nullable|numeric',
                'manualDiscount' => 'nullable|numeric',
                'paymentIntentId' => 'required|string',
                'couponCode'     => 'nullable|string|max:255',
                'isMultiAddress' => 'required|boolean',

                'ipAddress'                  => 'nullable|string|max:45',
                'isSaveAddressForShipping'   => 'nullable|boolean',
                'isSaveAddressForBilling'    => 'nullable|boolean',
                'billingSame'                => 'nullable|boolean',

                'destinations'   => 'required_if:isMultiAddress,true|array|min:1',
                'destinations.*.firstName'      => 'required|string|max:255',
                'destinations.*.lastName'       => 'required|string|max:255',
                'destinations.*.companyName'    => 'nullable|string|max:255',
                'destinations.*.phone'          => 'nullable|string|max:20',
                'destinations.*.addressLine1'   => 'required|string|max:255',
                'destinations.*.addressLine2'   => 'nullable|string|max:255',
                'destinations.*.city'           => 'required|string|max:100',
                'destinations.*.state'          => 'required|string|max:100',
                'destinations.*.zip'            => 'required|string|max:20',
                'destinations.*.country'        => 'required|string|max:100',

                'destinations.*.shippingMethod' => 'required',
                'destinations.*.shippingCost'   => 'required|numeric',
                'destinations.*.shippingData'   => 'nullable',

                'destinations.*.products'       => 'required|array|min:1',
                'destinations.*.products.*.product_id' => 'required|integer|exists:products,id',
                'destinations.*.products.*.quantity'   => 'required|integer|min:1',

                'products'       => 'required_if:isMultiAddress,false|array|min:1',
                'products.*.product_id' => 'required|integer|exists:products,id',
                'products.*.quantity'   => 'required|integer|min:1',
            ]);

            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json(['status' => false, 'message' => 'Store not selected'], 400);
            }

            $orderEmail = strtolower(trim($validated['email']));

            $existingDraft = null;
            if (!empty($validated['orderId'])) {
                $existingDraft = Order::where('id', $validated['orderId'])
                    ->where('store_id', $storeId)
                    ->first();

                if (!$existingDraft) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Order not found.',
                    ], 404);
                }
            }

            $resolvePrice = function (int $productId, $fallbackPrice) use ($existingDraft) {
                if ($existingDraft) {
                    $row = \App\Models\OrderCartItemProduct::query()
                        ->where('order_id', $existingDraft->id)
                        ->where('product_id', $productId)
                        ->latest('id')
                        ->first();

                    if ($row) {
                        return (float) $row->price;
                    }
                }

                return (float) $fallbackPrice;
            };

            $existingPayment = Payment::where('stripe_session_id', $validated['paymentIntentId'])->first();

            if ($existingPayment) {
                if ($existingPayment->payment_status === 'completed') {
                    $order = Order::with('items.product.images', 'billingInformation', 'customer')
                        ->find($existingPayment->order_id);

                    return response()->json([
                        'status'  => true,
                        'message' => 'Order already placed successfully.',
                        'data'    => new OrderResource($order),
                    ]);
                } else {
                    return response()->json([
                        'status'  => false,
                        'message' => 'This payment is already being processed.'
                    ], 409);
                }
            }

            Stripe::setApiKey(config('services.stripe.secret'));

            try {
                $stripeIntent = PaymentIntent::retrieve(
                    $validated['paymentIntentId']
                );
            } catch (\Exception $stripeException) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Unable to verify payment with Stripe.',
                ], 422);
            }

            if ($stripeIntent->status !== 'requires_capture') {
                return response()->json([
                    'status'  => false,
                    'message' => 'Payment has not been authorized.',
                    'payment_status' => $stripeIntent->status,
                ], 422);
            }

            $user = auth('sanctum')->user();
            $customer = null;
            $isGuest = false;

            if ($user) {
                $customer = $user->customer;
            } else {
                $user = User::where('email', $validated['email'])->where('userRole', 2)->where('store_id', $storeId)->first();
                if (!$user) {
                    $isGuest = true;
                }
            }

            if ($user && !$customer) {
                $customer = $user->customer;
            }

            if (!$customer) {
                $primary = $validated['isMultiAddress'] ? $validated['destinations'][0] : $validated;

                $customer = Customer::create([
                    'store_id'       => $storeId,
                    'first_name'     => $primary['firstName'] ?? '',
                    'last_name'      => $primary['lastName'] ?? '',
                    'email'          => $validated['email'],
                    'phone'          => $primary['phone'] ?? null,
                    'company_name'   => $primary['companyName'] ?? null,
                    'address'        => $primary['addressLine1'] ?? '',
                    'address_line_2' => $primary['addressLine2'] ?? null,
                    'city'           => $primary['city'] ?? null,
                    'state'          => $primary['state'] ?? null,
                    'zip'            => $primary['zip'] ?? null,
                    'country'        => $primary['country'] ?? null,
                    'user_type'      => $isGuest ? 'guest' : null,
                ]);

                if ($user) {
                    $user->customer_id = $customer->id;
                    $user->save();
                }
            }

            $user = $request->user('sanctum') ?? auth('sanctum')->user();

            if (!$user && $request->bearerToken()) {
                $token = \Laravel\Sanctum\PersonalAccessToken::findToken($request->bearerToken());
                $user  = $token?->tokenable;
            }

            $orderEmail = strtolower(trim($validated['email'] ?? ''));
            $sessionId  = $request->header('X-Session-ID');
            $userId     = $user?->id;

            if ($orderEmail) {
                CouponUsage::where('store_id', $storeId)
                    ->whereRaw('LOWER(email) = ?', [$orderEmail])
                    ->where(function ($q) use ($userId, $sessionId) {
                        if ($userId) {
                            $q->orWhere('user_id', $userId);
                        }
                        if ($sessionId) {
                            $q->orWhere('session_id', $sessionId);
                        }
                    })
                    ->update([
                        'user_used' => 1,
                    ]);
            }
            // $coupon = null;

            // if (!empty($validated['couponCode'])) {

            //     $coupon = CouponCode::where('store_id', $storeId)
            //         ->where('couponCode', $validated['couponCode'])
            //         ->first();

            //     if (!$coupon) {
            //         return response()->json([
            //             'status' => false,
            //             'message' => 'Invalid coupon code.'
            //         ], 400);
            //     }

            //     $couponUsageQuery = CouponUsage::where('store_id', $storeId)
            //         ->where('coupon_id', $coupon->id);

            //     if ($user) {
            //         $couponUsageQuery->where('user_id', $user->id);
            //     } else {
            //         $sessionId = $request->header('X-Session-ID');

            //         if (!$sessionId) {
            //             return response()->json([
            //                 'status' => false,
            //                 'message' => 'Session ID is required for guest coupon usage.'
            //             ], 400);
            //         }

            //         $couponUsageQuery->where('session_id', $sessionId);
            //     }

            //     $alreadyUsedByUserOrSession = $couponUsageQuery->exists();

            //     $alreadyUsedByEmail = CouponUsage::where('store_id', $storeId)
            //         ->where('coupon_id', $coupon->id)
            //         ->whereRaw('LOWER(email) = ?', [$orderEmail])
            //         ->exists();

            //     if ($alreadyUsedByUserOrSession || $alreadyUsedByEmail) {
            //         return response()->json([
            //             'status' => false,
            //             'message' => 'You have already used this coupon.'
            //         ], 400);
            //     }
            // }

            if ($isGuest) {
                try {
                    $store = Store::find($storeId);
                    $frontendUrl = rtrim($store?->base_url ?? '#', '/');
                    $registerLink = $frontendUrl . '/auth/signup';

                    Mail::to($validated['email'])->send(
                        new \App\Mail\GuestAccountMail(
                            $customer->first_name,
                            $validated['email'],
                            $registerLink,
                            $storeId
                        )
                    );
                } catch (\Exception $mailEx) {
                    Log::error('GUEST ACCOUNT EMAIL FAILED', [
                        'error' => $mailEx->getMessage(),
                        'email' => $validated['email'],
                    ]);
                }
            }

            if (!$user && !$isGuest) {
                $user = User::where('email', $validated['email'])->first();
            }

            $orders = [];
            $totalOrderAmount = 0;
            $discount = $validated['discountAmount'] ?? 0;
            $manualDiscount = $validated['manualDiscount'] ?? 0;
            $isMulti = $validated['isMultiAddress'];

            $commonOrderFields = [
                'ip_address'                    => $validated['ipAddress'] ?? $request->ip(),
                'is_save_address_for_shipping'  => $validated['isSaveAddressForShipping'] ?? false,
                'is_save_address_for_billing'   => $validated['isSaveAddressForBilling'] ?? false,
                'billing_same'                  => $validated['billingSame'] ?? true,
            ];

            $draftConvertFields = [
                'is_draft'   => 0,
                'isdrafturl' => null,
            ];

            if (!$isMulti) {
                $cartTotal = 0;
                foreach ($validated['products'] as $item) {
                    $product = Product::find($item['product_id']);
                    if ($product) {
                        $cartTotal += $resolvePrice($product->id, $product->price) * $item['quantity'];
                    }
                }

                $totalAmount = $cartTotal + $validated['shippingCost'] - $discount - $manualDiscount;

                $billing = BillingInformation::create([
                    'store_id'        => $storeId,
                    'first_name'      => $validated['firstName'],
                    'last_name'       => $validated['lastName'],
                    'customer_id'     => $customer->id,
                    'company_name'    => $validated['companyName'] ?? null,
                    'email'           => $validated['email'],
                    'phone'           => $validated['phone'] ?? null,
                    'address_line_1'  => $validated['addressLine1'],
                    'address_line_2'  => $validated['addressLine2'] ?? null,
                    'city'            => $validated['city'] ?? null,
                    'state'           => $validated['state'] ?? null,
                    'zip'             => $validated['zip'] ?? null,
                    'country'         => $validated['country'] ?? null,
                    'payment_method'  => $validated['paymentMethod'],
                    'shipping_method' => $validated['shippingMethod'],
                    'shipping_data'   => $validated['shippingData'] ?? null,
                ]);

                $orderPayload = array_merge([
                    'store_id'               => $storeId,
                    'user_id'                => $user?->id ?? $existingDraft?->user_id,
                    'customer_id'            => $customer->id,
                    'billing_information_id' => $billing->id,
                    'status'                 => 'Awaiting Payment',
                    'shipping_cost'          => $validated['shippingCost'],
                    'comments'               => $validated['comments'] ?? null,
                    'device_type'            => $validated['deviceType'] ?? null,
                    'user_type'              => $isGuest ? 'guest' : ($validated['userType'] ?? null),
                    'total_amount'           => $totalAmount,
                    'discountAmount'         => $discount,
                    'manualDiscount'         => $manualDiscount,
                    'coupon_code'            => $validated['couponCode'] ?? null,
                    'is_multi_address'       => false,
                    'newsletter'             => $validated['newsletter'] ?? false,
                ], $commonOrderFields);

                if ($existingDraft) {
                    $existingDraft->items()->delete();
                    $existingDraft->update(array_merge($orderPayload, $draftConvertFields));
                    $order = $existingDraft->fresh();
                } else {
                    $order = Order::create(array_merge($orderPayload, [
                        'order_number' => '',
                    ]));
                    $order->update(['order_number' => $order->id]);
                }

                foreach ($validated['products'] as $item) {
                    $product = Product::find($item['product_id']);
                    if ($product) {
                        $order->items()->create([
                            'store_id'   => $storeId,
                            'product_id' => $product->id,
                            'quantity'   => $item['quantity'],
                            'price'      => $resolvePrice($product->id, $product->price),
                        ]);

                        $product->decrement('current_stock', $item['quantity']);
                    }
                }

                $orders[] = $order;
                $totalOrderAmount = $totalAmount;
            } else {
                $destIndex = 0;

                foreach ($validated['destinations'] as $dest) {
                    $cartTotal = 0;
                    foreach ($dest['products'] as $item) {
                        $product = Product::find($item['product_id']);
                        if ($product) {
                            $cartTotal += $resolvePrice($product->id, $product->price) * $item['quantity'];
                        }
                    }

                    $totalAmount = $cartTotal + $dest['shippingCost'] - $discount - $manualDiscount;

                    $billing = BillingInformation::create([
                        'store_id'        => $storeId,
                        'first_name'      => $dest['firstName'],
                        'last_name'       => $dest['lastName'],
                        'customer_id'     => $customer->id,
                        'company_name'    => $dest['companyName'] ?? null,
                        'email'           => $validated['email'],
                        'phone'           => $dest['phone'] ?? null,
                        'address_line_1'  => $dest['addressLine1'],
                        'address_line_2'  => $dest['addressLine2'] ?? null,
                        'city'            => $dest['city'],
                        'state'           => $dest['state'],
                        'zip'             => $dest['zip'],
                        'country'         => $dest['country'],
                        'payment_method'  => $validated['paymentMethod'],
                        'shipping_method' => $dest['shippingMethod'],
                        'shipping_data'   => $dest['shippingData'] ?? null,
                    ]);

                    $orderPayload = array_merge([
                        'store_id'               => $storeId,
                        'user_id'                => $user?->id ?? $existingDraft?->user_id,
                        'customer_id'            => $customer->id,
                        'billing_information_id' => $billing->id,
                        'status'                 => 'Awaiting Payment',
                        'shipping_cost'          => $dest['shippingCost'],
                        'comments'               => $validated['comments'] ?? null,
                        'device_type'            => $validated['deviceType'] ?? null,
                        'user_type'              => $isGuest ? 'guest' : ($validated['userType'] ?? null),
                        'total_amount'           => $totalAmount,
                        'discountAmount'         => $discount,
                        'manualDiscount'         => $manualDiscount,
                        'coupon_code'            => $validated['couponCode'] ?? null,
                        'is_multi_address'       => true,
                        'newsletter'             => $validated['newsletter'] ?? false,
                    ], $commonOrderFields);

                    if ($existingDraft && $destIndex === 0) {
                        $existingDraft->items()->delete();
                        $existingDraft->update(array_merge($orderPayload, $draftConvertFields));
                        $order = $existingDraft->fresh();
                    } else {
                        $order = Order::create(array_merge($orderPayload, [
                            'order_number' => '',
                        ]));
                        $order->update(['order_number' => $order->id]);
                    }

                    foreach ($dest['products'] as $item) {
                        $product = Product::find($item['product_id']);
                        if ($product) {
                            $order->items()->create([
                                'store_id'   => $storeId,
                                'product_id' => $product->id,
                                'quantity'   => $item['quantity'],
                                'price'      => $resolvePrice($product->id, $product->price),
                            ]);

                            $product->decrement('current_stock', $item['quantity']);
                        }
                    }

                    $orders[] = $order;
                    $totalOrderAmount += $totalAmount;
                    $destIndex++;
                }
            }

            $stripeBillingDetails = null;
            try {
                \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
                $stripeIntent = \Stripe\PaymentIntent::retrieve($validated['paymentIntentId']);

                if ($stripeIntent->payment_method) {
                    $paymentMethod = \Stripe\PaymentMethod::retrieve($stripeIntent->payment_method);

                    if ($paymentMethod->billing_details) {
                        $bd = $paymentMethod->billing_details;
                        $stripeBillingDetails = [
                            'name'         => $bd->name ?? null,
                            'email'        => $bd->email ?? null,
                            'phone'        => $bd->phone ?? null,
                            'addressLine1' => $bd->address->line1 ?? null,
                            'addressLine2' => $bd->address->line2 ?? null,
                            'city'         => $bd->address->city ?? null,
                            'state'        => $bd->address->state ?? null,
                            'zip'          => $bd->address->postal_code ?? null,
                            'country'      => $bd->address->country ?? null,
                        ];
                    }
                }
            } catch (\Exception $stripeEx) {
                Log::warning('Could not fetch Stripe billing details: ' . $stripeEx->getMessage());
            }

            if ($stripeBillingDetails) {
                foreach ($orders as $ord) {
                    $ord->update(['billing_address' => $stripeBillingDetails]);
                }
            }

            $shippingData = null;

            if ($validated['isSaveAddressForShipping'] ?? false) {
                $shippingData = $isMulti
                    ? $validated['destinations'][0]
                    : [
                        'firstName'    => $validated['firstName'] ?? null,
                        'lastName'     => $validated['lastName'] ?? null,
                        'companyName'  => $validated['companyName'] ?? null,
                        'phone'        => $validated['phone'] ?? null,
                        'addressLine1' => $validated['addressLine1'] ?? null,
                        'addressLine2' => $validated['addressLine2'] ?? null,
                        'city'         => $validated['city'] ?? null,
                        'state'        => $validated['state'] ?? null,
                        'zip'          => $validated['zip'] ?? null,
                        'country'      => $validated['country'] ?? null,
                    ];
            }

            $billingData = null;

            if ($validated['isSaveAddressForBilling'] ?? false) {
                $billingData = $stripeBillingDetails ?? [
                    'name'         => ($validated['firstName'] ?? '') . ' ' . ($validated['lastName'] ?? ''),
                    'email'        => $validated['email'] ?? null,
                    'phone'        => $validated['phone'] ?? null,
                    'addressLine1' => $validated['addressLine1'] ?? null,
                    'addressLine2' => $validated['addressLine2'] ?? null,
                    'city'         => $validated['city'] ?? null,
                    'state'        => $validated['state'] ?? null,
                    'zip'          => $validated['zip'] ?? null,
                    'country'      => $validated['country'] ?? null,
                ];
            }

            if (($shippingData || $billingData) && $user) {
                SavedAddress::create([
                    'store_id'         => $storeId,
                    'user_id'          => $user->id,
                    'order_id'         => $orders[0]->id,
                    'shipping_address' => $shippingData,
                    'billing_address'  => $billingData,
                ]);
            }

            $riskData = [
                'risk_score' => null,
                'risk_level' => 'normal',
            ];

            try {
                \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

                $stripeIntent = \Stripe\PaymentIntent::retrieve(
                    $validated['paymentIntentId'],
                    ['expand' => ['latest_charge', 'latest_charge.review', 'review']]
                );

                if (empty($stripeIntent->latest_charge)) {
                    usleep(800000);
                    $stripeIntent = \Stripe\PaymentIntent::retrieve(
                        $validated['paymentIntentId'],
                        ['expand' => ['latest_charge', 'latest_charge.review', 'review']]
                    );
                }

                $charge = null;
                if (!empty($stripeIntent->latest_charge)) {
                    $charge = is_string($stripeIntent->latest_charge)
                        ? \Stripe\Charge::retrieve($stripeIntent->latest_charge)
                        : $stripeIntent->latest_charge;
                }

                $classicScore  = null;
                $classicLevel  = null;
                $outcomeType   = null;
                $outcomeReason = null;

                if ($charge && !empty($charge->outcome)) {
                    $classicScore  = isset($charge->outcome->risk_score)
                        ? (int) $charge->outcome->risk_score
                        : null;
                    $classicLevel  = $charge->outcome->risk_level ?? null;
                    $outcomeType   = $charge->outcome->type ?? null;
                    $outcomeReason = $charge->outcome->reason ?? null;
                }

                $hasReview = !empty($stripeIntent->review)
                    || (!empty($charge) && !empty($charge->review))
                    || $outcomeType === 'manual_review';

                $overallScore = $classicScore;
                $overallLevel = $classicLevel ?: 'normal';

                if ($outcomeReason === 'highest_risk_level' || $classicLevel === 'highest') {
                    $overallLevel = 'highest';
                } elseif ($hasReview || $classicLevel === 'elevated' || $outcomeReason === 'elevated_risk_level') {
                    $overallLevel = 'elevated';
                }

                if ($overallScore !== null) {
                    if ($overallScore >= 75) {
                        $overallLevel = 'highest';
                    } elseif ($overallScore >= 50 && $overallLevel === 'normal') {
                        $overallLevel = 'highest';
                    } elseif ($overallScore >= 65 && $overallLevel === 'normal') {
                        $overallLevel = 'elevated';
                    }
                }

                try {
                    if (!empty($stripeIntent->payment_method)) {
                        $pm = is_string($stripeIntent->payment_method)
                            ? \Stripe\PaymentMethod::retrieve($stripeIntent->payment_method)
                            : $stripeIntent->payment_method;

                        $checks = $pm->card->checks ?? null;
                        $streetFail = $checks && (($checks->address_line1_check ?? null) === 'fail');
                        $cvcFail    = $checks && (($checks->cvc_check ?? null) === 'fail');

                        if (($streetFail || $cvcFail) && in_array($overallLevel, ['normal', null], true)) {
                            $overallLevel = 'elevated';
                        }
                    }
                } catch (\Exception $pmEx) {
                    \Log::warning('Could not fetch PM checks: ' . $pmEx->getMessage());
                }

                $riskData = [
                    'risk_score' => $overallScore,
                    'risk_level' => $overallLevel,
                ];
            } catch (\Exception $e) {
                \Log::warning('Could not fetch Stripe risk data: ' . $e->getMessage());
            }

            Payment::create(array_merge([
                'order_id'          => $orders[0]->id,
                'stripe_session_id' => $validated['paymentIntentId'],
                'amount'            => $totalOrderAmount,
                'discountAmount'    => $discount,
                'payment_status'    => 'authorized',
            ], $riskData));

            // if ($coupon) {
            //     CouponUsage::create([
            //         'store_id'    => $storeId,
            //         'user_id'     => $user?->id ?? null,
            //         'session_id'  => $user ? null : $request->header('X-Session-ID'),
            //         'coupon_id'   => $coupon->id,
            //         'coupon_code' => $coupon->couponCode,
            //         'email'       => $orderEmail,
            //     ]);
            // }

            foreach ($orders as $ord) {
                $ord->load('billingInformation');

                OrderTimeline::addEvent($ord->id, $storeId, 'order_created', 'Order created', [
                    'status' => $ord->status,
                ], 'dot');

                OrderTimeline::addEvent($ord->id, $storeId, 'status_changed', 'Order status changed', [
                    'previous_status' => 'Incomplete',
                    'new_status'      => $ord->status,
                ], 'dot');

                OrderTimeline::addEvent($ord->id, $storeId, 'shipping_quote', 'Shipping quote persisted', [
                    'shipping_cost'   => '$' . number_format($ord->shipping_cost, 2),
                    'shipping_method' => $ord->billingInformation->shipping_method ?? null,
                ], 'dot');

                OrderTimeline::addEvent($ord->id, $storeId, 'order_confirmed', 'Order confirmed', [], 'dot');

                OrderTimeline::addEvent($ord->id, $storeId, 'payment_authorized', 'Payment authorized', [
                    'source'         => ucwords(str_replace('_', ' ', $validated['paymentMethod'] ?? 'N/A')),
                    'amount'         => '$' . number_format($ord->total_amount, 2),
                    'payment_intent' => $validated['paymentIntentId'] ?? null,
                ], 'payment');
            }

            foreach ($orders as $order) {
                $order->load('items.product.images', 'billingInformation', 'customer');
            }

            try {
                foreach ($orders as $ord) {
                    $ord->load('items.product.images', 'items.product.brand', 'billingInformation', 'customer');

                    Mail::to($validated['email'])->send(new OrderPlacedMail($ord, $customer, $storeId));

                    $adminEmail = env('MAIL_FROM_SB_ADMIN', 'info@serverblink.uk');
                    Mail::to($adminEmail)->send(new OrderPlacedMail($ord, $customer, $storeId));
                }
            } catch (\Exception $mailError) {
                Log::error('ORDER EMAIL FAILED', [
                    'error' => $mailError->getMessage(),
                ]);
            }

            return response()->json([
                'status'  => true,
                'message' => 'Order placed successfully.',
                'data'    => OrderResource::collection($orders),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Order creation failed',
                'error'   => $e->getMessage(),
                'line'    => $e->getLine(),
                'file'    => $e->getFile(),
            ], 500);
        }
    }

    public function getCustomerOrderDiscounts(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected',
                ], 400);
            }

            $customerId = null;
            $orderId    = null;

            $quoteToken = $request->input('quoteToken') ?? $request->query('quoteToken');

            if ($quoteToken) {
                try {
                    $payload    = $this->draftQuote->decode($quoteToken);
                    $customerId = $payload['customer_id'] ?? null;
                    $orderId    = $payload['order_id'] ?? null;
                    $userId     = $payload['user_id'] ?? null;
                } catch (\Exception $e) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Invalid or expired token',
                    ], 401);
                }
            }

            if (!$customerId) {
                $user = auth('sanctum')->user();
                $customerId = $user?->customer_id;
            }

            if (!$customerId && !empty($userId)) {
                $tokenUser = User::where('store_id', $storeId)->where('id', $userId)->first();
                $customerId = $tokenUser?->customer_id;
            }

            $query = Order::query()->where('store_id', $storeId);

            if ($orderId) {
                $query->where('id', $orderId)->where('is_draft', 1);
            } elseif ($customerId) {
                $query->where('customer_id', $customerId)->latest('id');
            } else {
                return response()->json([
                    'status'  => false,
                    'message' => 'customer_id not found in token',
                ], 404);
            }

            $order = $query->first();

            if (!$order) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Order not found',
                ], 404);
            }

            $data = [
                'customerId'     => $order->customer_id,
                'couponCode'     => $order->coupon_code,
                'discountAmount' => round((float) ($order->discountAmount ?? 0), 2),
                'manualDiscount' => round((float) ($order->manual_discount ?? 0), 2),
            ];

            if ((int) $order->is_draft === 1) {
                $data['orderId'] = $order->id;
            }

            return response()->json([
                'status'  => true,
                'message' => 'Discounts retrieved successfully',
                'data'    => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve discounts',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // User ke saved addresses get karo
    public function getSavedAddress(Request $request)
    {
        try {
            $user = auth('sanctum')->user();
            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not authenticated.'
                ], 401);
            }

            $storeId = current_store_id();

            $addresses = SavedAddress::where('user_id', $user->id)
                ->where('store_id', $storeId)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Saved addresses retrieved successfully.',
                'data' => $addresses->map(function ($addr) {
                    return [
                        'id'              => $addr->id,
                        'orderId'         => $addr->order_id,
                        'shippingAddress' => $addr->shipping_address,
                        'billingAddress'  => $addr->billing_address,
                        'createdAt'       => $addr->created_at?->toDateTimeString(),
                    ];
                }),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // Saved address delete karo
    public function destroySavedAddress($id)
    {
        try {
            $user = auth('sanctum')->user();
            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not authenticated.'
                ], 401);
            }

            $storeId = current_store_id();

            $address = SavedAddress::where('id', $id)
                ->where('user_id', $user->id)
                ->where('store_id', $storeId)
                ->first();

            if (!$address) {
                return response()->json([
                    'status' => false,
                    'message' => 'Address not found.'
                ], 404);
            }

            $address->delete();

            return response()->json([
                'status' => true,
                'message' => 'Address deleted successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    /**
     * Update an existing order
     */
    public function update(Request $request, $id)
    {
        try {
            $order = Order::with('billingInformation')
                ->where('id', $id)
                ->where('store_id', current_store_id())
                ->firstOrFail();

            // Step 1: Update order fields
            $orderFields = [
                'shipping_cost',
                'total_amount',
                'coupon_code',
                'comments',
                'staff_notes',
                'status',
                'is_draft',
                'channel',
                'fulfillment_source',
                'tracking_number',
                'tracking_carrier_code',
                'tracking_carrier_name',
                'shipped_at',
            ];

            foreach ($orderFields as $field) {
                if ($request->has($field)) {
                    $order->{$field} = $request->input($field);
                }
            }

            // Step 2: Update billing information
            $billing = $order->billingInformation;
            if ($billing) {
                $billingMap = [
                    'first_name'      => ['first_name', 'firstName'],
                    'last_name'       => ['last_name', 'lastName'],
                    'company_name'    => ['company_name', 'companyName'],
                    'email'           => ['email'],
                    'phone'           => ['phone'],
                    'address_line_1'  => ['address_line_1', 'addressLine1'],
                    'address_line_2'  => ['address_line_2', 'addressLine2'],
                    'city'            => ['city'],
                    'state'           => ['state'],
                    'zip'             => ['zip'],
                    'country'         => ['country'],
                    'payment_method'  => ['payment_method', 'paymentMethod'],
                    'shipping_method' => ['shipping_method', 'shippingMethod'],
                ];

                foreach ($billingMap as $column => $aliases) {
                    foreach ($aliases as $key) {
                        if ($request->has($key)) {
                            $billing->{$column} = $request->input($key);
                            break;
                        }
                    }
                }

                $billing->save();
            }

            $order->save();

            return response()->json([
                'status'  => true,
                'message' => 'Order updated successfully.',
                'data'    => new OrderResource($order->fresh()->load('items.product.images', 'billingInformation', 'customer')),
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Order not found or unauthorized.',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Order update failed.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function getCustomerWithOrders(Request $request, $customerId)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected or token invalid.'
                ], 400);
            }

            $customer = Customer::where('id', $customerId)
                ->where('store_id', $storeId)
                ->first();

            if (!$customer) {
                return response()->json([
                    'status' => false,
                    'message' => 'Customer not found.'
                ], 404);
            }

            $orders = Order::with(['items.product.images', 'customer', 'billingInformation', 'notes', 'payment'])
                ->where('customer_id', $customerId)
                ->where('store_id', $storeId)
                ->where('is_draft', 0)
                ->latest()
                ->get();

            $currentOrders = $orders->filter(function ($order) {
                return !in_array($order->status, ['Completed', 'Cancelled', 'Refunded', 'Declined']);
            })->values();

            $pastOrders = $orders->filter(function ($order) {
                return in_array($order->status, ['Completed', 'Cancelled', 'Refunded', 'Declined']);
            })->values();

            return response()->json([
                'status' => true,
                'message' => 'Customer details retrieved successfully.',
                'data' => [
                    'customer' => [
                        'id'                   => $customer->id,
                        'firstName'            => $customer->first_name,
                        'lastName'             => $customer->last_name,
                        'email'                => $customer->email,
                        'phone'                => $customer->phone,
                        'companyName'          => $customer->company_name,
                        'address'              => $customer->address,
                        'addressLine2'         => $customer->address_line_2,
                        'city'                 => $customer->city,
                        'state'                => $customer->state,
                        'zip'                  => $customer->zip,
                        'country'              => $customer->country,
                        'customerGroup'        => $customer->customer_group ?? null,
                        'storeCredit'          => $customer->store_credit ?? 0,
                        'totalOrders'          => $orders->count(),
                        'joinDate'             => $customer->created_at?->format('M d, Y'),
                        'receiveReviewEmails'  => $customer->receive_review_emails ?? false,
                    ],
                    'currentOrders' => OrderResource::collection($currentOrders),
                    'pastOrders'    => OrderResource::collection($pastOrders),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve customer details.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateOrderStatus(Request $request, $id)
    {
        $request->validate(['status' => 'required|string']);

        try {
            $order = Order::where('id', $id)
                ->where('store_id', current_store_id())
                ->firstOrFail();

            $previousStatus = $order->status;
            $newStatus = $request->input('status');
            $order->status = $newStatus;
            $order->save();

            $storeId = current_store_id();

            // ===================== TIMELINE: STATUS CHANGED =====================
            OrderTimeline::addEvent($order->id, $storeId, 'status_changed', 'Order status changed', [
                'previous_status' => $previousStatus,
                'new_status'      => $newStatus,
            ], 'dot');

            // // ===================== STRIPE CAPTURE =====================
            // if ($newStatus === 'Awaiting Fulfillment') {
            //     $payment = Payment::where('order_id', $order->id)->first();

            //     if ($payment && $payment->stripe_session_id && $payment->payment_status === 'authorized') {
            //         try {
            //             \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
            //             $intent = \Stripe\PaymentIntent::retrieve($payment->stripe_session_id);

            //             if ($intent->status === 'requires_capture') {
            //                 $intent->capture();
            //                 $payment->update(['payment_status' => 'completed']);

            //                 OrderTimeline::addEvent($order->id, $storeId, 'payment_captured', 'Capture request successfully processed for the order #' . $order->order_number, [
            //                     'source'         => 'Credit Card (via Stripe)',
            //                     'payment_intent' => $payment->stripe_session_id,
            //                     'amount'         => '$' . number_format($payment->amount, 2),
            //                 ], 'payment');
            //             }
            //         } catch (\Exception $captureError) {
            //             \Log::error('STRIPE CAPTURE FAILED', [
            //                 'order_id'       => $order->id,
            //                 'payment_intent' => $payment->stripe_session_id,
            //                 'error'          => $captureError->getMessage(),
            //             ]);
            //         }
            //     }
            // }

            // // ===================== STRIPE CANCEL/REFUND =====================
            // if ($newStatus === 'Cancelled') {
            //     $payment = Payment::where('order_id', $order->id)->first();

            //     if ($payment && $payment->stripe_session_id) {
            //         try {
            //             \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
            //             $intent = \Stripe\PaymentIntent::retrieve($payment->stripe_session_id);

            //             if ($intent->status === 'requires_capture') {
            //                 $intent->cancel();
            //                 $payment->update(['payment_status' => 'cancelled']);

            //                 OrderTimeline::addEvent($order->id, $storeId, 'payment_cancelled', 'Payment cancelled for order #' . $order->order_number, [
            //                     'payment_intent' => $payment->stripe_session_id,
            //                 ], 'payment');
            //             } elseif ($intent->status === 'succeeded') {
            //                 \Stripe\Refund::create([
            //                     'payment_intent' => $payment->stripe_session_id,
            //                 ]);
            //                 $payment->update(['payment_status' => 'refunded']);

            //                 OrderTimeline::addEvent($order->id, $storeId, 'payment_refunded', 'Payment refunded for order #' . $order->order_number, [
            //                     'payment_intent' => $payment->stripe_session_id,
            //                     'amount'         => '$' . number_format($payment->amount, 2),
            //                 ], 'payment');
            //             }
            //         } catch (\Exception $stripeError) {
            //             \Log::error('STRIPE CANCEL/REFUND FAILED', [
            //                 'order_id' => $order->id,
            //                 'payment_intent' => $payment->stripe_session_id,
            //                 'error' => $stripeError->getMessage(),
            //             ]);
            //         }
            //     }
            // }

            // ===================== SEND STATUS UPDATE EMAIL =====================
            $emailStatuses = [
                'Awaiting Fulfillment',
                'Awaiting Shipment',
                'Awaiting Pickup',
                'Partially Shipped',
                'Shipped',
                'Completed',
                'Refunded',
            ];

            if (in_array($newStatus, $emailStatuses)) {
                $order->load('billingInformation', 'customer');

                $shipment = null;
                if ($newStatus === 'Shipped') {
                    $shipment = \App\Models\Shipment::where('order_id', $order->id)
                        ->where('store_id', $storeId)
                        ->latest()
                        ->first();
                }

                $customerEmail = $order->billingInformation->email
                    ?? $order->customer->email
                    ?? null;

                if ($customerEmail) {
                    try {
                        Mail::to($customerEmail)->send(new OrderStatusUpdatedMail($order, $newStatus, $storeId, $shipment));

                        $adminEmail = env('MAIL_FROM_SB_ADMIN', 'info@serverblink.uk');
                        Mail::to($adminEmail)->send(new OrderStatusUpdatedMail($order, $newStatus, $storeId, $shipment));

                        OrderTimeline::addEvent($order->id, $storeId, 'email_sent', 'Status update email sent', [
                            'email'  => $customerEmail,
                            'status' => $newStatus,
                        ], 'email');
                    } catch (\Exception $mailError) {
                        Log::error('ORDER STATUS EMAIL FAILED', [
                            'error'   => $mailError->getMessage(),
                            'order'   => $order->order_number,
                            'status'  => $newStatus,
                            'email'   => $customerEmail,
                        ]);
                    }
                }
            }

            return response()->json([
                'status'  => true,
                'message' => 'Order status updated.',
                'data'    => new OrderResource($order->load('items.product.images', 'billingInformation')),
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => false, 'message' => 'Order not found'], 404);
        }
    }

    public function exportToExcel()
    {
        try {
            $storeId = current_store_id();

            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.'
                ], 400);
            }

            $orderExists = Order::where('store_id', $storeId)->exists();

            if (!$orderExists) {
                return response()->json([
                    'status'  => false,
                    'message' => 'No orders found for this store to export.'
                ], 404);
            }

            return Excel::download(new OrdersExport, 'orders.xlsx');
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to export orders: ' . $e->getMessage()
            ], 500);
        }
    }

    public function listDraftOrders(Request $request)
    {
        $orders = Order::with(['billingInformation'])
            ->where('store_id', current_store_id())
            ->where('is_draft', true)
            ->latest()
            ->paginate(20);

        return response()->json([
            'status'  => true,
            'message' => 'Draft orders retrieved.',
            'data'    => OrderResource::collection($orders),
        ]);
    }

    public function createDraftOrder(Request $request)
    {
        $validated = $request->validate([
            'channel' => 'required|string|max:255',
        ]);

        $user = auth('sanctum')->user();

        $order = Order::create([
            'store_id'     => current_store_id(),
            'user_id'      => $user?->id,
            'session_id'   => $user ? null : session()->getId(),
            'order_number' => '',
            'status'       => 'Draft',
            'is_draft'     => true,
            'channel'      => $validated['channel'],
        ]);
        $order->update([
            'order_number' => $order->id,
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Draft order created.',
            'data'    => new OrderResource($order),
        ]);
    }

    public function deleteDraftOrder($id)
    {
        $order = Order::where('store_id', current_store_id())
            ->where('is_draft', true)
            ->findOrFail($id);

        $order->delete();

        return response()->json([
            'status'  => true,
            'message' => 'Draft order deleted.'
        ]);
    }

    public function submitReturnRequest(Request $request)
    {
        $user = auth('sanctum')->user();

        $request->validate([
            'orderId'       => 'required|exists:orders,id|unique:return_requests,order_id',
            'reason'        => 'nullable|string|max:500',
            'returnAction'  => 'nullable|string|max:500',
            'comments'      => 'nullable|string|max:500',
            'isSubmit'      => 'nullable|boolean',
        ]);

        $order = Order::where('id', $request->orderId)
            ->where('store_id', current_store_id())
            ->first();

        if (!$order) {
            return response()->json([
                'status' => false,
                'message' => 'Order not found.'
            ], 404);
        }

        $returnRequest = ReturnRequest::create([
            'store_id'     => current_store_id(),
            'order_id'     => $order->id,
            'user_id'      => $user->id,
            'reason'       => $request->reason,
            'returnAction' => $request->returnAction,
            'comments'     => $request->comments,
            'status'       => 'Pending',
            'is_submit'    => $request->isSubmit ?? false,
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Return request submitted successfully.',
            'data'    => new ReturnRequestResource($returnRequest)
        ]);
    }


    public function listReturnRequestsForAdmin(Request $request)
    {
        $requests = ReturnRequest::with([
            'order.items.product.images',
            'user',
            'order.customer',
        ])
            ->latest()
            ->paginate(20);

        return response()->json([
            'status' => true,
            'data'   => ReturnRequestResource::collection($requests),
        ]);
    }

    public function listReturnRequestsForCustomer(Request $request)
    {
        $user = auth()->user();

        $requests = ReturnRequest::with([
            'order.items.product.images',
            'user',
            'order.customer',
        ])
            ->where('store_id', current_store_id())
            ->whereHas('order', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            })
            ->latest()
            ->paginate(20);

        return response()->json([
            'status' => true,
            'data'   => ReturnRequestResource::collection($requests),
        ]);
    }

    public function cancelOrder(Request $request, $id)
    {
        try {
            $user = auth('sanctum')->user();

            $cancelableStatuses = [
                'Pending',
                'Awaiting Payment',
                'Awaiting Fulfillment',
                'Manual Verification Required',
            ];

            $order = Order::where('id', $id)
                ->where('store_id', current_store_id())
                ->where('user_id', $user->id)
                ->whereIn('status', $cancelableStatuses)
                ->firstOrFail();

            $order->status = 'Cancelled';
            $order->save();

            return response()->json([
                'status'  => true,
                'message' => 'Order cancelled successfully.',
                'data'    => new OrderResource($order->load('items.product.images', 'billingInformation')),
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Order not found or cannot be cancelled.',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function trackOrder(Request $request)
    {
        try {
            $validated = $request->validate([
                'orderNumber' => 'required|string|max:255',
                'email'       => 'required|email',
            ]);

            $order = Order::with(['items.product.images', 'billingInformation'])
                ->where('order_number', $validated['orderNumber'])
                ->where('store_id', current_store_id())
                ->whereHas('billingInformation', function ($q) use ($validated) {
                    $q->where('email', $validated['email']);
                })
                ->firstOrFail();

            $productNames = $order->items->map(fn($item) => optional($item->product)->name)->filter()->values();

            return response()->json([
                'status'  => true,
                'message' => 'Order found successfully.',
                'data'    => [
                    'status'   => $order->status,
                    'products' => $productNames
                ]
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Order not found with provided details.',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function addOrderNote(Request $request, $orderId)
    {
        $user = auth('sanctum')->user();

        $request->validate([
            'note' => 'required|string|max:1000',
        ]);

        $order = Order::where('store_id', current_store_id())
            ->where('id', $orderId)
            ->firstOrFail();

        $note = OrderNote::create([
            'order_id' => $order->id,
            'user_id'  => $user->id,
            'note'     => $request->note,
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Note added successfully.',
            'data'    => new OrderNoteResource($note),
        ]);
    }

    public function getOrderNotes($orderId)
    {
        $order = Order::where('store_id', current_store_id())
            ->where('id', $orderId)
            ->firstOrFail();

        $notes = $order->notes()->with('user')->latest()->get();

        return response()->json([
            'status' => true,
            'data'   => OrderNoteResource::collection($notes),
        ]);
    }

    // public function storeFromDashboard(Request $request)
    // {
    //     $storeId = current_store_id();

    //     $validated = $request->validate([
    //         // Existing customer
    //         'customerId' => 'nullable|exists:customers,id',

    //         // New customer (if no customerId)
    //         'email'         => 'required_without:customerId|email|unique:customers,email',
    //         'password'      => 'nullable|string|min:6|confirmed',
    //         'firstName'     => 'required_without:customerId|string|max:255',
    //         'lastName'      => 'required_without:customerId|string|max:255',
    //         'phone'         => 'nullable|string|max:20',
    //         'companyName'   => 'nullable|string|max:255',
    //         'customerGroup' => 'nullable|string|max:255',
    //         'receiveOffers' => 'nullable|boolean',

    //         // Nested Billing Information
    //         'billingInformation' => 'array',
    //         'billingInformation.firstName'      => 'nullable|string|max:255',
    //         'billingInformation.lastName'       => 'nullable|string|max:255',
    //         // 'billingInformation.email'          => 'required|email',
    //         'billingInformation.phone'          => 'nullable|string|max:20',
    //         'billingInformation.companyName'    => 'nullable|string|max:255',
    //         'billingInformation.addressLine1'   => 'nullable|string|max:255',
    //         'billingInformation.addressLine2'   => 'nullable|string|max:255',
    //         'billingInformation.city'           => 'nullable|string|max:100',
    //         'billingInformation.state'          => 'nullable|string|max:100',
    //         'billingInformation.zip'            => 'nullable|string|max:20',
    //         'billingInformation.country'        => 'nullable|string|max:100',
    //         'billingInformation.paymentMethod'  => 'nullable|string|max:100',
    //         'billingInformation.shippingMethod' => 'nullable|string|max:100',

    //         // Order Info
    //         'comments'   => 'nullable|string|max:1000',
    //         'staffNotes' => 'nullable|string|max:1000',

    //         // Product List
    //         'products'               => 'required|array|min:1',
    //         'products.*.productId'   => 'required|exists:products,id',
    //         'products.*.quantity'    => 'required|integer|min:1',
    //     ]);

    //     try {
    //         DB::beginTransaction();
    //         $user = auth('sanctum')->user();

    //         // Step 1: Find or create customer
    //         if (!empty($validated['customerId'])) {
    //             $customer = Customer::where('store_id', $storeId)->findOrFail($validated['customerId']);
    //         } else {
    //             $customer = Customer::create([
    //                 'store_id'              => $storeId,
    //                 'email'                 => $validated['email'],
    //                 'first_name'            => $validated['firstName'],
    //                 'last_name'             => $validated['lastName'],
    //                 'company_name'          => $validated['companyName'] ?? null,
    //                 'phone'                 => $validated['phone'] ?? null,
    //                 'customer_group'        => $validated['customerGroup'] ?? null,
    //                 'receive_review_emails' => $validated['receiveOffers'] ?? false,
    //                 'force_password_reset'  => true,
    //                 'country'               => $validated['billingInformation']['country'] ?? null,
    //                 'state'                 => $validated['billingInformation']['state'] ?? null,
    //             ]);

    //             if (!empty($validated['password'])) {
    //                 User::create([
    //                     'name'        => $validated['firstName'] . ' ' . $validated['lastName'],
    //                     'email'       => $validated['email'],
    //                     'password'    => Hash::make($validated['password']),
    //                     'customer_id' => $customer->id,
    //                     'store_id'    => $storeId,
    //                 ]);

    //             }
    //         }

    //         // Step 2: Save billing information
    //         $b = $validated['billingInformation'] ?? [];
    //         $billing = $this->saveBillingInformation([
    //             'store_id'         => $storeId,
    //             'customer_id'      => $customer?->id,
    //             'user_id'          => $user?->id,
    //             'first_name'       => $b['firstName'] ?? $validated['firstName'],
    //             'last_name'        => $b['lastName'] ?? $validated['lastName'],
    //             'company_name'     => $b['companyName'] ?? null,
    //             // 'email'            => $b['email'],
    //             'phone'            => $b['phone'] ?? null,
    //             'address_line_1'   => $b['addressLine1'] ?? null,
    //             'address_line_2'   => $b['addressLine2'] ?? null,
    //             'city'             => $b['city'] ?? null,
    //             'state'            => $b['state'] ?? null,
    //             'zip'              => $b['zip'] ?? null,
    //             'country'          => $b['country'] ?? null,
    //             'payment_method'   => $b['paymentMethod'],
    //             'shipping_method'  => $b['shippingMethod'],
    //         ], $user, $customer);

    //         // Step 3: Create the order
    //         $order = Order::create([
    //             'store_id'               => $storeId,
    //             'customer_id'            => $customer->id,
    //             'user_id'                => $user->id,
    //             'billing_information_id' => $billing->id,
    //             'order_number'           => 'ORD-' . strtoupper(Str::random(8)),
    //             'status'                 => 'pending',
    //             'is_draft'               => false,
    //             'channel'                => 'admin-panel',
    //             'shipping_cost'          => 0,
    //             'total_amount'           => 0,
    //             'comments'               => $validated['comments'] ?? null,
    //             'staff_notes'            => $validated['staffNotes'] ?? null,
    //         ]);

    //         // Step 4: Add order items & total
    //         $total = 0;
    //         foreach ($validated['products'] as $item) {
    //             $product   = Product::where('store_id', $storeId)->findOrFail($item['productId']);
    //             $qty       = $item['quantity'];
    //             $lineTotal = $product->price * $qty;

    //             OrderItem::create([
    //                 'order_id'   => $order->id,
    //                 'product_id' => $product->id,
    //                 'quantity'   => $qty,
    //                 'price'      => $product->price,
    //                 'total'      => $lineTotal,
    //                 'store_id'   => $storeId,
    //             ]);

    //             $total += $lineTotal;
    //             $product->increment('sold_count', $qty);
    //         }

    //         // Step 5: Finalize order total
    //         $order->update(['total_amount' => $total]);

    //         DB::commit();

    //         return response()->json([
    //             'status'   => true,
    //             'message'  => 'Order placed successfully.',
    //             'customer' => new CustomerResource($customer),
    //             'order'    => new OrderResource($order->load('items.product.images', 'billingInformation')),
    //         ]);
    //     } catch (ModelNotFoundException $e) {
    //         DB::rollBack();
    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Order failed.',
    //             'error'   => 'One or more selected products could not be found.',
    //         ], 200);
    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Order failed.',
    //             'error'   => $e->getMessage(),
    //         ], 500);
    //     }
    // }

    //  public function storeFromDashboard(Request $request)
    // {
    //     $storeId = current_store_id();

    //     $validated = $request->validate([
    //         'customerId' => 'required|exists:customers,id',
    //         'customerGroup' => 'nullable|string|max:255',
    //         'receiveOffers' => 'nullable|boolean',
    //         'comments'      => 'nullable|string|max:1000',
    //         'staffNotes'    => 'nullable|string|max:1000',
    //         'billingInformation' => 'required|array',
    //         'billingInformation.firstName' => 'nullable|string|max:255',
    //         'billingInformation.lastName'  => 'nullable|string|max:255',
    //         'billingInformation.phone'     => 'nullable|string|max:20',
    //         'billingInformation.companyName' => 'nullable|string|max:255',
    //         'billingInformation.addressLine1' => 'nullable|string|max:255',
    //         'billingInformation.addressLine2' => 'nullable|string|max:255',
    //         'billingInformation.city'         => 'nullable|string|max:100',
    //         'billingInformation.state'        => 'nullable|string|max:100',
    //         'billingInformation.zip'          => 'nullable|string|max:20',
    //         'billingInformation.country'      => 'nullable|string|max:100',
    //         'billingInformation.email'        => 'nullable|email',
    //         'billingInformation.paymentMethod' => 'nullable|string',
    //         'billingInformation.shippingMethod' => 'nullable|string',
    //         'paymentMethod' => 'nullable|array',
    //         'paymentMethod.method' => 'nullable|string',
    //         'paymentMethod.cardType' => 'nullable|string',
    //         'paymentMethod.cardholderName' => 'nullable|string',
    //         'paymentMethod.creditCardNo' => 'nullable|string',
    //         'paymentMethod.ccv2Value' => 'nullable|string',
    //         'paymentMethod.expirationMonth' => 'nullable|string',
    //         'paymentMethod.expirationYear' => 'nullable|string',
    //         'paymentMethod.emailInvoice' => 'nullable|boolean',
    //         'products' => 'required|array|min:1',
    //         'products.*.productId' => 'required|exists:products,id',
    //         'products.*.quantity' => 'required|integer|min:1',
    //         'shippingDestinations' => 'nullable|array',
    //         'shippingDestinations.*.address' => 'required|array',
    //         'shippingDestinations.*.products' => 'required|array',
    //         'shippingMethod' => 'nullable|array',
    //         'shippingMethod.provider' => 'nullable|string',
    //         'shippingMethod.cost' => 'nullable|string',
    //         'shippingMethod.method' => 'nullable|string',
    //     ]);

    //     try {
    //         DB::beginTransaction();
    //         $user = auth('sanctum')->user();
    //         $customer = Customer::where('store_id', $storeId)->findOrFail($validated['customerId']);

    //         // Update customer preferences
    //         $customer->update([
    //             'customer_group'        => $validated['customerGroup'] ?? $customer->customer_group,
    //             'receive_review_emails' => $validated['receiveOffers'] ?? $customer->receive_review_emails,
    //         ]);

    //         // Prepare shipping destinations (one per product if not provided)
    //         $shippingDestinations = $validated['shippingDestinations'] ?? collect($validated['products'])->map(function($product, $index) use ($validated) {
    //             return [
    //                 'address' => array_merge($validated['billingInformation'], [
    //                     'lastName' => $validated['billingInformation']['lastName'] . ($index + 1)
    //                 ]),
    //                 'products' => [$product]
    //             ];
    //         })->toArray();

    //         $orders = [];

    //         foreach ($shippingDestinations as $destination) {
    //             $address  = $destination['address'];
    //             $products = $destination['products'];

    //             // Save billing/shipping info per destination
    //             $billing = $this->saveBillingInformation([
    //                 'store_id'        => $storeId,
    //                 'customer_id'     => $customer->id,
    //                 'user_id'         => $user?->id,
    //                 'first_name'      => $address['firstName'] ?? null,
    //                 'last_name'       => $address['lastName'] ?? null,
    //                 'company_name'    => $address['companyName'] ?? null,
    //                 'email'           => $address['email'] ?? null,
    //                 'phone'           => $address['phone'] ?? null,
    //                 'address_line_1'  => $address['addressLine1'] ?? null,
    //                 'address_line_2'  => $address['addressLine2'] ?? null,
    //                 'city'            => $address['city'] ?? null,
    //                 'state'           => $address['state'] ?? null,
    //                 'zip'             => $address['zip'] ?? null,
    //                 'country'         => $address['country'] ?? null,
    //                 'payment_method'  => $validated['paymentMethod']['method'] ?? null,
    //                 'shipping_method' => $address['shippingMethod'] ?? null,
    //             ], $user, $customer);

    //             // Create order per shipping destination with total_amount = 0
    //             $order = Order::create([
    //                 'store_id'               => $storeId,
    //                 'customer_id'            => $customer->id,
    //                 'user_id'                => $user->id,
    //                 'billing_information_id' => $billing->id,
    //                 'order_number'           => 'ORD-' . strtoupper(Str::random(8)),
    //                 'status'                 => 'pending',
    //                 'is_draft'               => false,
    //                 'channel'                => 'admin-panel',
    //                 'shipping_cost'          => (float)($validated['shippingMethod']['cost'] ?? 0),
    //                 'shipping_provider'      => $validated['shippingMethod']['provider'] ?? null,
    //                 'shipping_method'        => $validated['shippingMethod']['method'] ?? null,
    //                 'comments'               => $validated['comments'] ?? null,
    //                 'staff_notes'            => $validated['staffNotes'] ?? null,
    //                 'total_amount'           => 0,
    //             ]);

    //             // Add products
    //             $total = 0;
    //             foreach ($products as $item) {
    //                 $product = Product::where('store_id', $storeId)->findOrFail($item['productId']);
    //                 $qty = $item['quantity'];
    //                 $lineTotal = $product->price * $qty;

    //                 OrderItem::create([
    //                     'order_id'   => $order->id,
    //                     'product_id' => $product->id,
    //                     'quantity'   => $qty,
    //                     'price'      => $product->price,
    //                     'total'      => $lineTotal,
    //                     'store_id'   => $storeId,
    //                 ]);

    //                 $product->increment('sold_count', $qty);
    //                 $total += $lineTotal;
    //             }

    //             $order->update(['total_amount' => $total]);
    //             $orders[] = ['order' => $order, 'destination' => $destination];
    //         }

    //         DB::commit();

    //         // Build response matching payload + full order details
    //         $ordersResponse = [];
    //         foreach ($orders as $entry) {
    //             $order = $entry['order'];
    //             $destination = $entry['destination'];

    //             $ordersResponse[] = [
    //                 "id" => $order->id,
    //                 "orderNumber" => $order->order_number,
    //                 "status" => ucfirst($order->status),
    //                 "shippingCost" => $order->shipping_cost,
    //                 "comments" => $order->comments,
    //                 "staffNotes" => $order->staff_notes,
    //                 "isDraft" => $order->is_draft,
    //                 "totalAmount" => $order->total_amount, // ✅ exact DB value
    //                 "trackingNumber" => $order->tracking_number,
    //                 "carrierCode" => $order->shipping_provider,
    //                 "carrierName" => $order->shipping_provider,
    //                 "shippedAt" => $order->shipped_at,
    //                 "customerId" => $customer->id,
    //                 "customerGroup" => $customer->customer_group,
    //                 "receiveOffers" => $customer->receive_review_emails,
    //                 "customer" => new CustomerResource($customer),
    //                 "billingInformation" => [
    //                     "firstName" => $destination['address']['firstName'] ?? null,
    //                     "lastName" => $destination['address']['lastName'] ?? null,
    //                     "phone" => $destination['address']['phone'] ?? null,
    //                     "companyName" => $destination['address']['companyName'] ?? null,
    //                     "addressLine1" => $destination['address']['addressLine1'] ?? null,
    //                     "addressLine2" => $destination['address']['addressLine2'] ?? null,
    //                     "city" => $destination['address']['city'] ?? null,
    //                     "state" => $destination['address']['state'] ?? null,
    //                     "zip" => $destination['address']['zip'] ?? null,
    //                     "country" => $destination['address']['country'] ?? null,
    //                     "email" => $destination['address']['email'] ?? $customer->email ?? null,
    //                     "paymentMethod" => $order->billingInformation->payment_method ?? null,
    //                     "shippingMethod" => $order->billingInformation->shipping_method ?? null
    //                 ],
    //                 "paymentMethod" => [
    //                     "method" => $validated['paymentMethod']['method'] ?? null,
    //                     "cardType" => $validated['paymentMethod']['cardType'] ?? null,
    //                     "cardholderName" => $validated['paymentMethod']['cardholderName'] ?? null,
    //                     "creditCardNo" => $validated['paymentMethod']['creditCardNo'] ?? null,
    //                     "ccv2Value" => $validated['paymentMethod']['ccv2Value'] ?? null,
    //                     "expirationMonth" => $validated['paymentMethod']['expirationMonth'] ?? null,
    //                     "expirationYear" => $validated['paymentMethod']['expirationYear'] ?? null,
    //                     "emailInvoice" => $validated['paymentMethod']['emailInvoice'] ?? null
    //                 ],
    //                  // ✅ Full product details
    //                 "products" => collect($destination['products'])->map(function($item) use ($storeId) {
    //                     $product = Product::where('store_id', $storeId)->find($item['productId']);
    //                     return $product 
    //                         ? (new ProductResource($product))->additional([
    //                             'quantity' => $item['quantity'],
    //                             'price' => $product->price,
    //                         ])
    //                         : null;
    //                 })->filter()->values(),
    //                 "shippingDestinations" => [
    //                     [
    //                         "address" => $destination['address'],
    //                         "products" => $destination['products']
    //                     ]
    //                 ],
    //                 "shippingMethod" => [
    //                     "provider" => $order->shipping_provider,
    //                     "cost" => $order->shipping_cost,
    //                     "method" => $order->shipping_method
    //                 ]
    //             ];
    //         }

    //         return response()->json([
    //             'status' => true,
    //             'message' => 'Orders placed successfully.',
    //             'orders' => $ordersResponse
    //         ]);

    //     } catch (ModelNotFoundException $e) {
    //         DB::rollBack();
    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Order failed.',
    //             'error'   => 'One or more selected products could not be found.',
    //         ], 200);
    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Order failed.',
    //             'error'   => $e->getMessage(),
    //         ], 500);
    //     }
    // }



    public function storeFromDashboard(Request $request)
    {
        $storeId = current_store_id();
        if (!$storeId) {
            return response()->json(['status' => false, 'message' => 'Store not selected'], 400);
        }

        try {
            $validated = $request->validate([
                'customerId'   => 'required|exists:customers,id',
                'isDraft'      => 'nullable|boolean',
                'deviceType'   => 'nullable|string|max:1000',
                'comments'     => 'nullable|string|max:1000',
                'staffNotes'   => 'nullable|string|max:1000',
                'billingSame'  => 'nullable|boolean',
                'isSaveAddressForBilling'  => 'nullable|boolean',
                'isSaveAddressForShipping' => 'nullable|boolean',
                'newsletter'   => 'nullable|boolean',
                'receiveOffers' => 'nullable|boolean',
                'userType'     => 'nullable|string|max:50',
                'couponCode'   => 'nullable|string|max:255',
                'discountAmount' => 'nullable|numeric',
                'manualDiscount' => 'nullable|numeric|min:0',
                'ipAddress'    => 'nullable|string|max:45',
                'emailInvoice' => 'nullable|boolean',

                'billingAddress' => 'nullable|array',
                'billingAddress.firstName'    => 'nullable|string',
                'billingAddress.lastName'     => 'nullable|string',
                'billingAddress.name'         => 'nullable|string',
                'billingAddress.email'        => 'nullable|email',
                'billingAddress.phone'        => 'nullable|string',
                'billingAddress.companyName'  => 'nullable|string',
                'billingAddress.addressLine1' => 'nullable|string',
                'billingAddress.addressLine2' => 'nullable|string',
                'billingAddress.city'         => 'nullable|string',
                'billingAddress.state'        => 'nullable|string',
                'billingAddress.zip'          => 'nullable|string',
                'billingAddress.country'      => 'nullable|string',
                'billingAddress.saveToAddressBook' => 'nullable|boolean',

                'billingInformation' => 'nullable|array',
                'billingInformation.firstName'    => 'nullable|string',
                'billingInformation.lastName'     => 'nullable|string',
                'billingInformation.email'        => 'nullable|email',
                'billingInformation.phone'        => 'nullable|string',
                'billingInformation.companyName'  => 'nullable|string',
                'billingInformation.addressLine1' => 'nullable|string',
                'billingInformation.addressLine2' => 'nullable|string',
                'billingInformation.city'         => 'nullable|string',
                'billingInformation.state'        => 'nullable|string',
                'billingInformation.zip'          => 'nullable|string',
                'billingInformation.country'      => 'nullable|string',
                'billingInformation.saveToAddressBook' => 'nullable|boolean',

                'paymentMethod' => 'required_unless:isDraft,true,1|array',
                'paymentMethod.method'           => 'required_unless:isDraft,true,1|string',
                'paymentMethod.paymentMethodId'  => 'nullable|string',
                'paymentMethod.cardholderName'   => 'nullable|string',
                'paymentMethod.creditCardNo'     => 'nullable|string',
                'paymentMethod.ccv2Value'        => 'nullable|string',
                'paymentMethod.expirationMonth'  => 'nullable|string',
                'paymentMethod.expirationYear'   => 'nullable|string',
                'paymentMethod.cardType'         => 'nullable|string',
                'paymentMethod.emailInvoice'     => 'nullable|boolean',

                'products'              => 'required|array|min:1',
                'products.*.productId'  => 'required_without:products.*.product_id|integer|exists:products,id',
                'products.*.product_id' => 'required_without:products.*.productId|integer|exists:products,id',
                'products.*.quantity'   => 'required|integer|min:1',
                'products.*.price'      => 'nullable|numeric|min:0',

                'shippingDestinations'  => 'nullable|array',
                'shippingMethod'        => 'nullable|array',
                'shippingMethod.provider' => 'nullable|string',
                'shippingMethod.method'   => 'nullable',
                'shippingMethod.cost'     => 'nullable',
                'shippingMethod.data'     => 'nullable',
            ]);

            $isDraft = (bool) ($validated['isDraft'] ?? false);
            $rawMethod = strtolower((string) ($validated['paymentMethod']['method'] ?? ''));
            $isCard = in_array($rawMethod, [
                'stripe',
                'credit_card',
                'card',
                'credit card',
                'credit card (via stripe)',
            ], true);

            $paymentMethodId = $validated['paymentMethod']['paymentMethodId'] ?? null;

            if (!$isDraft && $isCard && empty($paymentMethodId)) {
                $cardErrors = $this->validateStripeCard($validated['paymentMethod']);
                if (!empty($cardErrors)) {
                    return response()->json([
                        'status'  => false,
                        'message' => collect($cardErrors)->flatten()->first(),
                        'errors'  => $cardErrors,
                    ], 422);
                }

                $stripeResult = $this->createStripePaymentMethod($validated['paymentMethod']);
                if (!$stripeResult['ok']) {
                    return response()->json([
                        'status'  => false,
                        'message' => $stripeResult['message'],
                        'errors'  => $stripeResult['errors'] ?? null,
                    ], 422);
                }
                $paymentMethodId = $stripeResult['payment_method_id'];
            }

            $validated['paymentMethod']['paymentMethodId'] = $paymentMethodId;
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => collect($e->errors())->flatten()->first(),
                'errors'  => $e->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $user = auth('sanctum')->user();

            $customer = Customer::where('store_id', $storeId)
                ->findOrFail($validated['customerId']);

            $formAddress  = $validated['billingAddress'] ?? [];
            $billingInput = $validated['billingInformation'] ?? [];

            $shippingInfo = [
                'firstName'    => $billingInput['firstName'] ?? $formAddress['firstName'] ?? $customer->first_name ?? null,
                'lastName'     => $billingInput['lastName'] ?? $formAddress['lastName'] ?? $customer->last_name ?? null,
                'email'        => $billingInput['email'] ?? $formAddress['email'] ?? $customer->email ?? null,
                'phone'        => $billingInput['phone'] ?? $formAddress['phone'] ?? $customer->phone ?? null,
                'companyName'  => $billingInput['companyName'] ?? $formAddress['companyName'] ?? $customer->company_name ?? null,
                'addressLine1' => $billingInput['addressLine1'] ?? $customer->address ?? $customer->address_line_1 ?? null,
                'addressLine2' => $billingInput['addressLine2'] ?? $customer->address_line_2 ?? null,
                'city'         => $billingInput['city'] ?? $customer->city ?? null,
                'state'        => $billingInput['state'] ?? $customer->state ?? null,
                'zip'          => $billingInput['zip'] ?? $customer->zip ?? null,
                'country'      => $billingInput['country'] ?? $customer->country ?? null,
            ];

            $billingInfo = [
                'firstName'    => $formAddress['firstName'] ?? $customer->first_name ?? null,
                'lastName'     => $formAddress['lastName'] ?? $customer->last_name ?? null,
                'name'         => $formAddress['name'] ?? null,
                'email'        => $formAddress['email'] ?? $customer->email ?? null,
                'phone'        => $formAddress['phone'] ?? $customer->phone ?? null,
                'companyName'  => $formAddress['companyName'] ?? $customer->company_name ?? null,
                'addressLine1' => $formAddress['addressLine1'] ?? null,
                'addressLine2' => $formAddress['addressLine2'] ?? null,
                'city'         => $formAddress['city'] ?? null,
                'state'        => $formAddress['state'] ?? null,
                'zip'          => $formAddress['zip'] ?? null,
                'country'      => $formAddress['country'] ?? null,
            ];

            $comments     = $validated['comments'] ?? $billingInput['comments'] ?? null;
            $staffNotes   = $validated['staffNotes'] ?? $billingInput['staffNotes'] ?? null;
            $saveBilling  = (bool) ($validated['isSaveAddressForBilling'] ?? $formAddress['saveToAddressBook'] ?? false);
            $saveShipping = (bool) ($validated['isSaveAddressForShipping'] ?? $billingInput['saveToAddressBook'] ?? false);

            $shippingMethod   = $validated['shippingMethod'] ?? $request->input('shippingMethod', []);
            $shippingCost     = (float) (
                $shippingMethod['cost']
                ?? $shippingMethod['total_charge']
                ?? $request->input('shippingMethod.cost')
                ?? $request->input('shippingMethod.total_charge')
                ?? 0
            );
            $shippingProvider = $shippingMethod['provider']
                ?? $shippingMethod['method']
                ?? $shippingMethod['display_name']
                ?? null;
            $shippingData     = $shippingMethod['data']
                ?? $shippingMethod['display_name']
                ?? $shippingMethod['method']
                ?? $shippingProvider;
            $discount         = (float) ($validated['discountAmount'] ?? 0);
            $manualDiscount   = (float) ($validated['manualDiscount'] ?? $request->input('manualDiscount', 0));

            $products = collect($validated['products'])->map(function ($item) use ($storeId) {
                $productId = $item['product_id'] ?? $item['productId'];
                $hasPrice  = array_key_exists('price', $item) && $item['price'] !== null && $item['price'] !== '';

                if ($hasPrice) {
                    $price = (float) $item['price'];
                } else {
                    $price = (float) Product::where('store_id', $storeId)->findOrFail($productId)->price;
                }

                return [
                    'product_id' => $productId,
                    'quantity'   => (int) $item['quantity'],
                    'price'      => $price,
                ];
            })->all();

            $cartTotal = 0;
            foreach ($products as $item) {
                Product::where('store_id', $storeId)->findOrFail($item['product_id']);
                $cartTotal += $item['price'] * $item['quantity'];
            }

            $totalAmount = $cartTotal + $shippingCost - $discount - $manualDiscount;
            if ($totalAmount < 0) {
                $totalAmount = 0;
            }

            $displayPaymentMethod = $isCard
                ? 'Credit Card (Via Stripe)'
                : ($validated['paymentMethod']['method'] ?? 'N/A');

            $intent   = null;
            $riskData = [];

            if (!$isDraft && $paymentMethodId) {
                \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

                $intent = \Stripe\PaymentIntent::create([
                    'amount'               => (int) round($totalAmount * 100),
                    'currency'             => 'usd',
                    'payment_method'       => $paymentMethodId,
                    'confirmation_method'  => 'manual',
                    'payment_method_types' => ['card'],
                    'confirm'              => true,
                    'capture_method'       => 'manual',
                    'metadata'             => [
                        'user_id'  => $user?->id,
                        'channel'  => 'admin-panel',
                        'store_id' => $storeId,
                        'products' => json_encode($products),
                    ],
                ], [
                    'idempotency_key' => uniqid('dashboard_payment_', true),
                ]);

                if (in_array($intent->status, ['requires_action', 'requires_source_action'])) {
                    DB::rollBack();
                    return response()->json([
                        'status'                       => 'requires_action',
                        'payment_intent_client_secret' => $intent->client_secret,
                    ]);
                }

                if ($intent->status !== 'requires_capture') {
                    DB::rollBack();
                    return response()->json([
                        'status'  => false,
                        'message' => 'Payment authorization failed. Status: ' . $intent->status,
                    ], 400);
                }

                $existingPayment = Payment::where('stripe_session_id', $intent->id)->first();
                if ($existingPayment) {
                    DB::rollBack();
                    if ($existingPayment->payment_status === 'completed') {
                        $order = Order::with('items.product.images', 'billingInformation', 'customer')
                            ->find($existingPayment->order_id);
                        return response()->json([
                            'status'  => true,
                            'message' => 'Order already placed successfully.',
                            'data'    => new OrderResource($order),
                        ]);
                    }
                    return response()->json([
                        'status'  => false,
                        'message' => 'This payment is already being processed.',
                    ], 409);
                }

                $riskData = [
                    'risk_score' => null,
                    'risk_level' => 'normal',
                ];

                try {
                    $freshIntent = \Stripe\PaymentIntent::retrieve(
                        $intent->id,
                        ['expand' => ['latest_charge', 'latest_charge.review', 'review']]
                    );

                    if (empty($freshIntent->latest_charge)) {
                        usleep(800000);
                        $freshIntent = \Stripe\PaymentIntent::retrieve(
                            $intent->id,
                            ['expand' => ['latest_charge', 'latest_charge.review', 'review']]
                        );
                    }

                    $charge = null;
                    if (!empty($freshIntent->latest_charge)) {
                        $charge = is_string($freshIntent->latest_charge)
                            ? \Stripe\Charge::retrieve($freshIntent->latest_charge)
                            : $freshIntent->latest_charge;
                    }

                    $classicScore  = null;
                    $classicLevel  = null;
                    $outcomeType   = null;
                    $outcomeReason = null;

                    if ($charge && !empty($charge->outcome)) {
                        $classicScore  = isset($charge->outcome->risk_score)
                            ? (int) $charge->outcome->risk_score
                            : null;
                        $classicLevel  = $charge->outcome->risk_level ?? null;
                        $outcomeType   = $charge->outcome->type ?? null;
                        $outcomeReason = $charge->outcome->reason ?? null;
                    }

                    $hasReview = !empty($freshIntent->review)
                        || (!empty($charge) && !empty($charge->review))
                        || $outcomeType === 'manual_review';

                    $overallScore = $classicScore;
                    $overallLevel = $classicLevel ?: 'normal';

                    if ($outcomeReason === 'highest_risk_level' || $classicLevel === 'highest') {
                        $overallLevel = 'highest';
                    } elseif ($hasReview || $classicLevel === 'elevated' || $outcomeReason === 'elevated_risk_level') {
                        $overallLevel = 'elevated';
                    }

                    if ($overallScore !== null) {
                        if ($overallScore >= 75) {
                            $overallLevel = 'highest';
                        } elseif ($overallScore >= 50 && $overallLevel === 'normal') {
                            $overallLevel = 'highest';
                        } elseif ($overallScore >= 65 && $overallLevel === 'normal') {
                            $overallLevel = 'elevated';
                        }
                    }

                    try {
                        $pmId = $freshIntent->payment_method ?? $paymentMethodId;
                        if (!empty($pmId)) {
                            $pm = is_string($pmId)
                                ? \Stripe\PaymentMethod::retrieve($pmId)
                                : $pmId;

                            $checks = $pm->card->checks ?? null;
                            $streetFail = $checks && (($checks->address_line1_check ?? null) === 'fail');
                            $cvcFail    = $checks && (($checks->cvc_check ?? null) === 'fail');

                            if (($streetFail || $cvcFail) && in_array($overallLevel, ['normal', null], true)) {
                                $overallLevel = 'elevated';
                            }
                        }
                    } catch (\Exception $pmEx) {
                        \Log::warning('Dashboard order: could not fetch PM checks: ' . $pmEx->getMessage());
                    }

                    $riskData = [
                        'risk_score' => $overallScore,
                        'risk_level' => $overallLevel,
                    ];
                } catch (\Exception $e) {
                    \Log::warning('Dashboard order: could not fetch Stripe risk data: ' . $e->getMessage());
                }
            }

            $billing = BillingInformation::create([
                'store_id'        => $storeId,
                'customer_id'     => $customer->id,
                'user_id'         => $user?->id,
                'first_name'      => $shippingInfo['firstName'],
                'last_name'       => $shippingInfo['lastName'],
                'email'           => $shippingInfo['email'] ?? $customer->email,
                'phone'           => $shippingInfo['phone'],
                'company_name'    => $shippingInfo['companyName'],
                'address_line_1'  => $shippingInfo['addressLine1'],
                'address_line_2'  => $shippingInfo['addressLine2'],
                'city'            => $shippingInfo['city'],
                'state'           => $shippingInfo['state'],
                'zip'             => $shippingInfo['zip'],
                'country'         => $shippingInfo['country'],
                'payment_method'  => $displayPaymentMethod,
                'shipping_method' => $shippingProvider,
                'shipping_data'   => [
                    'display_name' => $shippingMethod['display_name'] ?? $shippingData,
                    'label'        => $shippingData,
                    'data'         => $shippingMethod['data'] ?? $shippingData,
                ],
            ]);

            $addressName = $billingInfo['name']
                ?? trim(($billingInfo['firstName'] ?? '') . ' ' . ($billingInfo['lastName'] ?? ''));

            $billingAddressJson = [
                'name'         => $addressName ?: null,
                'firstName'    => $billingInfo['firstName'],
                'lastName'     => $billingInfo['lastName'],
                'companyName'  => $billingInfo['companyName'],
                'email'        => $billingInfo['email'] ?? $customer->email,
                'phone'        => $billingInfo['phone'],
                'addressLine1' => $billingInfo['addressLine1'],
                'addressLine2' => $billingInfo['addressLine2'],
                'city'         => $billingInfo['city'],
                'state'        => $billingInfo['state'],
                'zip'          => $billingInfo['zip'],
                'country'      => $billingInfo['country'],
            ];

            $order = Order::create([
                'store_id'                     => $storeId,
                'customer_id'                  => $customer->id,
                'user_id'                      => $user?->id,
                'billing_information_id'       => $billing->id,
                'order_number'                 => '',
                'status'                       => 'Awaiting Payment',
                'is_draft'                     => $isDraft,
                'isdrafturl'                   => null,
                'device_type'                  => $validated['deviceType'] ?? 'desktop',
                'channel'                      => 'admin-panel',
                'ip_address'                   => $validated['ipAddress'] ?? null,
                'email_invoice'                => $validated['emailInvoice'] ?? true,
                'billing_same'                 => $validated['billingSame'] ?? true,
                'is_save_address_for_billing'  => $saveBilling,
                'is_save_address_for_shipping' => $saveShipping,
                'newsletter'                   => $validated['newsletter'] ?? $validated['receiveOffers'] ?? false,
                'shipping_cost'                => $shippingCost,
                'comments'                     => $comments,
                'staff_notes'                  => $staffNotes,
                'total_amount'                 => $totalAmount,
                'discountAmount'               => $discount,
                'manual_discount'              => $manualDiscount,
                'coupon_code'                  => $validated['couponCode'] ?? null,
                'is_multi_address'             => false,
                'user_type'                    => $validated['userType'] ?? null,
                'billing_address'              => $billingAddressJson,
            ]);

            $order->update(['order_number' => $order->id]);

            if ($saveBilling || $saveShipping) {
                $shippingAddressJson = [
                    'name'         => trim(($shippingInfo['firstName'] ?? '') . ' ' . ($shippingInfo['lastName'] ?? '')) ?: null,
                    'firstName'    => $shippingInfo['firstName'] ?? null,
                    'lastName'     => $shippingInfo['lastName'] ?? null,
                    'companyName'  => $shippingInfo['companyName'] ?? null,
                    'email'        => $shippingInfo['email'] ?? $customer->email,
                    'phone'        => $shippingInfo['phone'] ?? null,
                    'addressLine1' => $shippingInfo['addressLine1'] ?? null,
                    'addressLine2' => $shippingInfo['addressLine2'] ?? null,
                    'city'         => $shippingInfo['city'] ?? null,
                    'state'        => $shippingInfo['state'] ?? null,
                    'zip'          => $shippingInfo['zip'] ?? null,
                    'country'      => $shippingInfo['country'] ?? null,
                ];

                SavedAddress::create([
                    'store_id'          => $storeId,
                    'user_id'           => $user?->id,
                    'order_id'          => $order->id,
                    'billing_address'   => $saveBilling ? $billingAddressJson : null,
                    'shipping_address'  => $saveShipping ? $shippingAddressJson : null,
                ]);
            }

            foreach ($products as $item) {
                $product = Product::where('store_id', $storeId)->findOrFail($item['product_id']);
                $order->items()->create([
                    'store_id'   => $storeId,
                    'product_id' => $product->id,
                    'quantity'   => $item['quantity'],
                    'price'      => $item['price'],
                ]);
                $product->decrement('current_stock', $item['quantity']);
            }

            if ($isDraft) {
                $cartUser = User::where('store_id', $storeId)
                    ->where('customer_id', $validated['customerId'])
                    ->first();

                if (!$cartUser) {
                    $cartUser = User::where('store_id', $storeId)
                        ->where('id', $validated['customerId'])
                        ->first();
                }

                if (!$cartUser && !empty($customer->user_id)) {
                    $cartUser = User::where('store_id', $storeId)
                        ->where('id', $customer->user_id)
                        ->first();
                }

                $formUserId = $cartUser?->id ?? $customer->user_id ?? $user?->id;

                $quoteUrl = $this->draftQuote->makeDraftQuoteUrl(
                    $order->fresh('customer'),
                    $cartUser ?? null,
                    $storeId
                );

                $order->update([
                    'isdrafturl' => $quoteUrl,
                ]);

                $quoteToken = null;
                if ($quoteUrl && str_contains($quoteUrl, 'quoteToken=')) {
                    $quoteToken = substr($quoteUrl, strpos($quoteUrl, 'quoteToken=') + 11);
                }

                if ($cartUser) {
                    foreach ($validated['products'] as $raw) {
                        $productId = $raw['product_id'] ?? $raw['productId'];
                        $qty       = (int) $raw['quantity'];
                        $hasPrice  = array_key_exists('price', $raw) && $raw['price'] !== null && $raw['price'] !== '';

                        $cartRow = Cart::create([
                            'store_id'    => $storeId,
                            'user_id'     => $cartUser->id,
                            'product_id'  => $productId,
                            'quantity'    => $qty,
                            'isdraftcart' => $hasPrice ? 1 : 0,
                            'quote_token' => $quoteToken,
                        ]);

                        if ($hasPrice) {
                            OrderCartItemProduct::create([
                                'order_id'   => $order->id,
                                'user_id'    => $formUserId,
                                'product_id' => $productId,
                                'cart_id'    => $cartRow->id,
                                'price'      => (float) $raw['price'],
                                'qty'        => $qty,
                            ]);
                        }
                    }
                }

                $billingFormData = [
                    'firstName'    => $formAddress['firstName'] ?? null,
                    'lastName'     => $formAddress['lastName'] ?? null,
                    'name'         => $formAddress['name'] ?? null,
                    'email'        => $formAddress['email'] ?? null,
                    'phone'        => $formAddress['phone'] ?? null,
                    'companyName'  => $formAddress['companyName'] ?? null,
                    'addressLine1' => $formAddress['addressLine1'] ?? null,
                    'addressLine2' => $formAddress['addressLine2'] ?? null,
                    'city'         => $formAddress['city'] ?? null,
                    'state'        => $formAddress['state'] ?? null,
                    'zip'          => $formAddress['zip'] ?? null,
                    'country'      => $formAddress['country'] ?? null,
                ];

                $shippingFormData = [
                    'firstName'    => $billingInput['firstName'] ?? $shippingInfo['firstName'] ?? null,
                    'lastName'     => $billingInput['lastName'] ?? $shippingInfo['lastName'] ?? null,
                    'email'        => $billingInput['email'] ?? $shippingInfo['email'] ?? null,
                    'phone'        => $billingInput['phone'] ?? $shippingInfo['phone'] ?? null,
                    'companyName'  => $billingInput['companyName'] ?? $shippingInfo['companyName'] ?? null,
                    'addressLine1' => $billingInput['addressLine1'] ?? $shippingInfo['addressLine1'] ?? null,
                    'addressLine2' => $billingInput['addressLine2'] ?? $shippingInfo['addressLine2'] ?? null,
                    'city'         => $billingInput['city'] ?? $shippingInfo['city'] ?? null,
                    'state'        => $billingInput['state'] ?? $shippingInfo['state'] ?? null,
                    'zip'          => $billingInput['zip'] ?? $shippingInfo['zip'] ?? null,
                    'country'      => $billingInput['country'] ?? $shippingInfo['country'] ?? null,
                ];

                CheckoutFormData::create([
                    'store_id'           => $storeId,
                    'user_id'            => $formUserId,
                    'session_id'         => null,
                    'billing_form_data'  => $billingFormData,
                    'shipping_form_data' => $shippingFormData,
                ]);

                $cartIds = [];
                if ($cartUser) {
                    $cartIds = Cart::where('store_id', $storeId)
                        ->where('user_id', $cartUser->id)
                        ->where('quote_token', $quoteToken)
                        ->pluck('id')
                        ->values()
                        ->all();
                }

                CartShippingRate::create([
                    'store_id'     => $storeId,
                    'user_id'      => $formUserId,
                    'session_id'   => null,
                    'cart_ids'     => $cartIds,
                    'country'      => $shippingFormData['country'] ?? null,
                    'state'        => $shippingFormData['state'] ?? null,
                    'city'         => $shippingFormData['city'] ?? null,
                    'zip'          => $shippingFormData['zip'] ?? null,
                    'service_type' => $shippingMethod['service_type'] ?? $shippingMethod['method'] ?? 'custom',
                    'method_type'  => $shippingMethod['method_type'] ?? $shippingMethod['method'] ?? $shippingProvider ?? 'custom',
                    'total_charge' => (float) (
                        $shippingMethod['total_charge']
                        ?? $shippingMethod['cost']
                        ?? $shippingCost
                        ?? 0
                    ),
                ]);
            }


            if ($intent) {
                Payment::create(array_merge([
                    'order_id'          => $order->id,
                    'store_id'          => $storeId,
                    'stripe_session_id' => $intent->id,
                    'amount'            => $totalAmount,
                    'discountAmount'    => $discount,
                    'payment_status'    => 'authorized',
                ], $riskData));
            }

            $manualUserId     = $user?->id ?? null;
            $manualCustomerId = $customer->id ?? ($validated['customerId'] ?? null);

            foreach ($products as $item) {
                ManualDiscount::create([
                    'store_id'        => $storeId ?: null,
                    'user_id'         => $manualUserId,
                    'customer_id'     => $manualCustomerId ?: null,
                    'order_id'        => $order->id,
                    'product_id'      => $item['product_id'] ?? null,
                    'discount_amount' => $manualDiscount,
                    'total_amount'    => $totalAmount,
                ]);
            }

            $order->load('billingInformation');

            OrderTimeline::addEvent($order->id, $storeId, 'order_created', 'Order created', [
                'status' => $order->status,
            ], 'dot');

            OrderTimeline::addEvent($order->id, $storeId, 'status_changed', 'Order status changed', [
                'previous_status' => 'Incomplete',
                'new_status'      => $order->status,
            ], 'dot');

            OrderTimeline::addEvent($order->id, $storeId, 'shipping_quote', 'Shipping quote persisted', [
                'shipping_cost'   => '$' . number_format((float) $order->shipping_cost, 2),
                'shipping_method' => $order->billingInformation->shipping_method ?? $shippingProvider,
            ], 'dot');

            OrderTimeline::addEvent($order->id, $storeId, 'order_confirmed', 'Order confirmed', [], 'dot');

            if ($intent) {
                OrderTimeline::addEvent($order->id, $storeId, 'payment_authorized', 'Payment authorized', [
                    'source'         => $displayPaymentMethod,
                    'amount'         => '$' . number_format((float) $order->total_amount, 2),
                    'payment_intent' => $intent->id,
                ], 'payment');
            }

            $order->load('items.product.images', 'items.product.brand', 'billingInformation', 'customer');

            $emailInvoice = (bool) ($validated['emailInvoice'] ?? true);

            if ($emailInvoice) {
                try {
                    $email = $billingAddressJson['email'] ?? $customer->email;
                    if ($email) {
                        Mail::to($email)->send(new OrderPlacedMail($order, $customer, $storeId));
                    }
                    $adminEmail = env('MAIL_FROM_SB_ADMIN', 'info@serverblink.uk');
                    Mail::to($adminEmail)->send(new OrderPlacedMail($order, $customer, $storeId));
                } catch (\Exception $mailError) {
                    \Log::error('DASHBOARD ORDER EMAIL FAILED', [
                        'error' => $mailError->getMessage(),
                    ]);
                }
            }

            DB::commit();
            $order->refresh();
            return response()->json([
                'status'  => true,
                'message' => 'Order placed successfully.',
                'data'    => new OrderResource($order),
            ]);
        } catch (\Stripe\Exception\CardException $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => $e->getError()->message,
            ], 402);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
                'line'    => $e->getLine(),
                'file'    => $e->getFile(),
            ], 500);
        }
    }

    public function loadSavedQuote(Request $request)
    {
        try {
            $token = $request->input('quoteToken') ?? $request->query('quoteToken');

            if (!$token) {
                return response()->json([
                    'status'  => false,
                    'message' => 'quoteToken is required',
                ], 422);
            }

            try {
                $payload = $this->draftQuote->decode($token);
            } catch (\Exception $e) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Invalid or expired quote token',
                ], 401);
            }

            $storeId = (int) ($payload['store_id'] ?? 0);
            $orderId = (int) ($payload['order_id'] ?? 0);
            $userId  = (int) ($payload['user_id'] ?? 0);
            $email   = $payload['email'] ?? null;

            $order = Order::query()
                ->with([
                    'items.product.images',
                    'items.product.brand',
                    'customer',
                    'billingInformation',
                    'notes:id,order_id,note',
                    'payment',
                    'shipment',
                    'manualDiscounts:id,order_id,discount_amount',
                ])
                ->withCount('messages')
                ->where('id', $orderId)
                ->where('store_id', $storeId)
                ->where('is_draft', 1)
                ->first();

            if (!$order) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Draft order not found',
                ], 404);
            }

            $user = null;

            if ($userId) {
                $user = User::where('store_id', $storeId)->where('id', $userId)->first();
            }

            if (!$user && $email) {
                $user = User::where('store_id', $storeId)->where('email', $email)->first();
            }

            if (!$user && $order->customer_id) {
                $user = User::where('store_id', $storeId)
                    ->where('customer_id', $order->customer_id)
                    ->first();
            }

            $headerUser = auth('sanctum')->user();

            if (!$headerUser || !$user || (int) $headerUser->id !== (int) $user->id) {
                return response()->json([
                    'status'  => false,
                    'message' => 'User not found',
                ], 403);
            }

            $alreadyLoggedIn = true;
            $authToken = null;

            $data = (new OrderResource($order))->resolve();
            unset($data['products']);

            $billingPm = $data['billingInformation']['paymentMethod']
                ?? $data['billingInformation']['payment_method']
                ?? null;

            $hasPayment = !empty($data['payment'])
                || (!empty($billingPm) && strtolower((string) $billingPm) !== 'n/a');

            $data['paymentMethod'] = $hasPayment
                ? [
                    'method'          => $billingPm ?: 'Credit Card (Via Stripe)',
                    'paymentStatus'   => $data['payment']['payment_status'] ?? null,
                    'paymentIntentId' => $data['payment']['payment_intent_id'] ?? null,
                    'amount'          => $data['payment']['amount'] ?? null,
                    'riskLevel'       => $data['payment']['risk_level'] ?? null,
                ]
                : ['method' => 'draft'];

            $data['isDraft']    = true;
            $data['isDraftUrl'] = $order->isdrafturl;

            $savedShipping = $order->billingInformation->shipping_data ?? [];
            if (is_string($savedShipping)) {
                $savedShipping = json_decode($savedShipping, true) ?: [];
            }

            $rate = \App\Models\CartShippingRate::query()
                ->where('store_id', $storeId)
                ->where('user_id', $user->id)
                ->latest('id')
                ->first();

            $displayName = $savedShipping['display_name']
                ?? $savedShipping['label']
                ?? $order->billingInformation->shipping_method
                ?? null;

            $data['shippingMethod'] = [
                'method_id'     => $savedShipping['method_id'] ?? $order->shipping_method ?? null,
                'method_type'   => $savedShipping['method_type'] ?? $rate?->method_type ?? null,
                'display_name'  => $displayName,
                'total_charge'  => $savedShipping['total_charge'] ?? $rate?->total_charge ?? $order->shipping_cost,
                'currency'      => $savedShipping['currency'] ?? 'USD',
                'transit_days'  => $savedShipping['transit_days'] ?? null,
                'delivery_date' => $savedShipping['delivery_date'] ?? null,
                'service_type'  => $savedShipping['service_type'] ?? $rate?->service_type ?? null,
                'is_fedex'      => $savedShipping['is_fedex']
                    ?? str_contains(strtolower((string) $displayName), 'fedex'),
                'provider'      => $savedShipping['provider']
                    ?? $order->shipping_provider
                    ?? $displayName,
                'method'        => $savedShipping['method']
                    ?? $savedShipping['data']
                    ?? $displayName,
                'cost'          => $savedShipping['cost'] ?? $order->shipping_cost,
                'data'          => $savedShipping['data'] ?? $savedShipping['label'] ?? $displayName,
            ];

            return response()->json([
                'status'  => true,
                'message' => 'Saved quote loaded',
                'alreadyLoggedIn' => $alreadyLoggedIn,
                'token'   => $authToken,
                'user'    => [
                    'id'    => $user->id,
                    'email' => $user->email,
                    'name'  => $user->name,
                ],
                'data'    => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to load saved quote',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
    private function validateStripeCard(array $pm): array
    {
        $errors = [];

        $number = preg_replace('/\s+/', '', (string) ($pm['creditCardNo'] ?? ''));
        $cvc    = preg_replace('/\s+/', '', (string) ($pm['ccv2Value'] ?? ''));
        $name   = trim((string) ($pm['cardholderName'] ?? ''));
        $month  = $this->parseExpiryMonth($pm['expirationMonth'] ?? null);
        $year   = $this->parseExpiryYear($pm['expirationYear'] ?? null);

        if ($name === '' || !preg_match("/^[a-zA-Z][a-zA-Z\s\.'\-]{1,79}$/", $name) || preg_match('/\d/', $name)) {
            $errors['paymentMethod.cardholderName'][] = 'Cardholder name is invalid.';
        }

        if (!preg_match('/^[0-9]{13,19}$/', $number) || !$this->isValidLuhn($number)) {
            $errors['paymentMethod.creditCardNo'][] = 'Your card number is invalid.';
        } else {
            $brand = $this->detectCardBrand($number);

            $validLengths = [
                'visa'       => [13, 16, 19],
                'mastercard' => [16],
                'amex'       => [15],
                'discover'   => [16, 19],
                'diners'     => [14, 16],
                'jcb'        => [16],
                'unionpay'   => [16, 17, 18, 19],
            ];

            if (!$brand) {
                $errors['paymentMethod.creditCardNo'][] = 'Your card brand is not supported.';
            } elseif (!in_array(strlen($number), $validLengths[$brand], true)) {
                $errors['paymentMethod.creditCardNo'][] = 'Your card number is invalid.';
            }

            $cvcLen = $brand === 'amex' ? 4 : 3;
            if (!preg_match('/^[0-9]+$/', $cvc) || strlen($cvc) !== $cvcLen) {
                $errors['paymentMethod.ccv2Value'][] = $brand === 'amex'
                    ? 'Amex CVC must be 4 digits.'
                    : 'CVC must be 3 digits.';
            }
        }

        if (!$month) {
            $errors['paymentMethod.expirationMonth'][] = 'Your card\'s expiration month is invalid.';
        }

        if (!$year) {
            $errors['paymentMethod.expirationYear'][] = 'Your card\'s expiration year is invalid.';
        }

        if ($month && $year) {
            $exp = \Carbon\Carbon::create($year, $month, 1)->endOfMonth();
            if ($exp->lt(now())) {
                $errors['paymentMethod.expirationYear'][] = 'Your card has expired.';
            }
        }

        return $errors;
    }

    private function createStripePaymentMethod(array $pm): array
    {
        \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

        if (!empty($pm['paymentMethodId'])) {
            return [
                'ok'                => true,
                'payment_method_id' => $pm['paymentMethodId'],
                'message'           => null,
                'errors'            => [],
            ];
        }

        $token = $pm['stripeToken'] ?? $this->mapTestCardToStripeToken($pm['creditCardNo'] ?? '');

        if (!$token) {
            $msg = 'Raw card numbers cannot be sent to Stripe from the server. Use test card 4242424242424242 or send paymentMethodId from Stripe.js.';
            return [
                'ok'                => false,
                'payment_method_id' => null,
                'message'           => $msg,
                'errors'            => ['paymentMethod.creditCardNo' => [$msg]],
            ];
        }

        try {
            $paymentMethod = \Stripe\PaymentMethod::create([
                'type' => 'card',
                'card' => [
                    'token' => $token,
                ],
                'billing_details' => [
                    'name' => $pm['cardholderName'] ?? null,
                ],
            ]);

            return [
                'ok'                => true,
                'payment_method_id' => $paymentMethod->id,
                'message'           => null,
                'errors'            => [],
            ];
        } catch (\Stripe\Exception\CardException $e) {
            $code = $e->getError()->code ?? '';
            $msg  = $e->getError()->message ?? 'Your card was declined.';

            $field = match ($code) {
                'invalid_number', 'incorrect_number' => 'paymentMethod.creditCardNo',
                'invalid_cvc', 'incorrect_cvc'       => 'paymentMethod.ccv2Value',
                'invalid_expiry_month'               => 'paymentMethod.expirationMonth',
                'invalid_expiry_year', 'expired_card' => 'paymentMethod.expirationYear',
                default                              => 'paymentMethod.creditCardNo',
            };

            return [
                'ok'                => false,
                'payment_method_id' => null,
                'message'           => $msg,
                'errors'            => [$field => [$msg]],
            ];
        } catch (\Stripe\Exception\ApiErrorException $e) {
            return [
                'ok'                => false,
                'payment_method_id' => null,
                'message'           => $e->getMessage(),
                'errors'            => ['paymentMethod' => [$e->getMessage()]],
            ];
        }
    }

    private function mapTestCardToStripeToken(?string $cardNumber): ?string
    {
        $num = preg_replace('/\s+/', '', (string) $cardNumber);

        $map = [
            '4242424242424242' => 'tok_visa',
            '4000056655665556' => 'tok_visa_debit',
            '5555555555554444' => 'tok_mastercard',
            '2223003122003222' => 'tok_mastercard',
            '378282246310005'  => 'tok_amex',
            '6011111111111117' => 'tok_discover',
            '4000000000000002' => 'tok_chargeDeclined',
            '4000000000009995' => 'tok_chargeDeclinedInsufficientFunds',
            '4000000000009987' => 'tok_chargeDeclinedLostCard',
            '4000000000009979' => 'tok_chargeDeclinedStolenCard',
            '4000000000000069' => 'tok_chargeDeclinedExpiredCard',
            '4000000000000127' => 'tok_chargeDeclinedIncorrectCvc',
            '4000000000000119' => 'tok_chargeDeclinedProcessingError',
            '4000000000003220' => 'tok_threeDSecure2Required',
            '4000002500003155' => 'tok_threeDSecure2Required',

            // Radar / risk
            '4000000000009235' => 'tok_riskLevelElevated',
            '4000000000004954' => 'tok_riskLevelHighest',
            '4100000000000019' => 'tok_radarBlock',
        ];

        return $map[$num] ?? null;
    }

    private function detectCardBrand(string $number): ?string
    {
        $patterns = [
            'amex'       => '/^3[47]/',
            'diners'     => '/^3(0[0-5]|[68])/',
            'visa'       => '/^4/',
            'mastercard' => '/^(5[1-5]|2[2-7])/',
            'discover'   => '/^(6011|65|64[4-9]|622)/',
            'jcb'        => '/^35/',
            'unionpay'   => '/^62/',
        ];

        foreach ($patterns as $brand => $regex) {
            if (preg_match($regex, $number)) {
                return $brand;
            }
        }

        return null;
    }

    private function parseExpiryMonth($month): ?int
    {
        if ($month === null || $month === '') {
            return null;
        }

        $map = [
            'jan' => 1,
            'january' => 1,
            'feb' => 2,
            'february' => 2,
            'mar' => 3,
            'march' => 3,
            'apr' => 4,
            'april' => 4,
            'may' => 5,
            'jun' => 6,
            'june' => 6,
            'jul' => 7,
            'july' => 7,
            'aug' => 8,
            'august' => 8,
            'sep' => 9,
            'september' => 9,
            'oct' => 10,
            'october' => 10,
            'nov' => 11,
            'november' => 11,
            'dec' => 12,
            'december' => 12,
        ];

        $key = strtolower(trim((string) $month));
        $num = $map[$key] ?? (int) $month;

        return ($num >= 1 && $num <= 12) ? $num : null;
    }

    private function parseExpiryYear($year): ?int
    {
        if ($year === null || $year === '') {
            return null;
        }

        $year = (int) $year;

        if ($year < 100) {
            $year += 2000;
        }

        return ($year >= 2000 && $year <= 2100) ? $year : null;
    }

    private function isValidLuhn(string $number): bool
    {
        $sum = 0;
        $alt = false;

        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $n = (int) $number[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alt = !$alt;
        }

        return $sum % 10 === 0;
    }

    // getDraftOrders Api

    public function getDraftOrders(Request $request)
    {
        try {
            $storeId = current_store_id();

            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.',
                ], 400);
            }

            if (!$request->boolean('isDraft')) {
                return response()->json([
                    'status'  => false,
                    'message' => 'isDraft must be true',
                ], 400);
            }

            $orders = Order::query()
                ->with([
                    'items' => function ($q) {
                        $q->select('id', 'order_id', 'product_id', 'quantity', 'price')
                            ->with([
                                'product' => function ($p) {
                                    $p->with([
                                        'images',
                                        'brand',
                                        'categories:id',
                                    ]);
                                },
                            ]);
                    },
                    'customer',
                    'billingInformation',
                    'notes:id,order_id,note',
                    'payment',
                    'shipment:id,order_id,tracking_number,tracking_carrie,date_shipped,shipping_method,shipped_by,packing_slip_note',
                    'manualDiscounts:id,order_id,discount_amount',
                ])
                ->withCount('messages')
                ->where('store_id', $storeId)
                ->where('is_draft', 1)
                ->latest('id')
                ->get();

            $data = collect(OrderResource::collection($orders)->resolve())
                ->map(function (array $item) {
                    $billingPm = $item['billingInformation']['paymentMethod']
                        ?? $item['billingInformation']['payment_method']
                        ?? null;

                    $hasPayment = !empty($item['payment']) || (!empty($billingPm) && strtolower((string) $billingPm) !== 'n/a');

                    if ($hasPayment) {
                        $item['paymentMethod'] = [
                            'method'          => $billingPm ?: 'Credit Card (Via Stripe)',
                            'paymentStatus'   => $item['payment']['payment_status'] ?? null,
                            'paymentIntentId' => $item['payment']['payment_intent_id'] ?? $item['payment']['stripe_session_id'] ?? null,
                            'amount'          => $item['payment']['amount'] ?? null,
                            'riskLevel'       => $item['payment']['risk_level'] ?? null,
                        ];
                    } else {
                        $item['paymentMethod'] = [
                            'method' => 'draft',
                        ];
                    }

                    $item['isDraft'] = true;
                    $item['shippingMethod'] = $item['shippingMethod'] ?? [
                        'provider' => $item['carrierName'] ?? null,
                        'method'   => $item['carrierCode'] ?? null,
                        'cost'     => (string) ($item['shippingCost'] ?? '0.00'),
                    ];

                    return $item;
                })
                ->values();

            return response()->json([
                'status'  => true,
                'message' => 'Draft orders retrieved successfully',
                'data'    => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function updateFromDashboard(Request $request, $orderId)
    {
        $storeId = current_store_id();
        if (!$storeId) {
            return response()->json(['status' => false, 'message' => 'Store not selected'], 400);
        }

        $validated = $request->validate([
            'customerId'     => 'nullable|exists:customers,id',
            'customerGroup'  => 'nullable|string|max:255',
            'receiveOffers'  => 'nullable|boolean',
            'comments'       => 'nullable|string|max:1000',
            'staffNotes'     => 'nullable|string|max:1000',
            'staff_notes'    => 'nullable|string|max:1000',
            'isDraft'        => 'nullable|boolean',
            'billingSame'    => 'nullable|boolean',
            'newsletter'     => 'nullable|boolean',
            'couponCode'     => 'nullable|string|max:255',
            'discountAmount' => 'nullable|numeric',
            'deviceType'     => 'nullable|string|max:1000',
            'userType'       => 'nullable|string|max:50',
            'emailInvoice'   => 'nullable|boolean',

            'billingAddress' => 'nullable|array',
            'billingInformation' => 'nullable|array',
            'paymentMethod' => 'nullable|array',

            'products' => 'nullable|array|min:1',
            'products.*.productId'  => 'required_without:products.*.product_id|exists:products,id',
            'products.*.product_id' => 'required_without:products.*.productId|exists:products,id',
            'products.*.quantity'   => 'required_with:products|integer|min:1',
            'products.*.price'      => 'nullable|numeric|min:0',

            'shippingDestinations' => 'nullable|array',
            'shippingMethod' => 'nullable|array',
        ]);

        try {
            DB::beginTransaction();

            $user  = auth('sanctum')->user();
            $order = Order::where('store_id', $storeId)->findOrFail($orderId);

            $wasDraft = (bool) $order->is_draft;
            $isDraft  = $request->exists('isDraft') ? (bool) $validated['isDraft'] : $wasDraft;

            $customerId = $validated['customerId'] ?? $order->customer_id;
            $customer   = Customer::where('store_id', $storeId)->findOrFail($customerId);

            if ($request->exists('customerGroup') || $request->exists('receiveOffers')) {
                $customer->update([
                    'customer_group'        => $validated['customerGroup'] ?? $customer->customer_group,
                    'receive_review_emails' => $validated['receiveOffers'] ?? $customer->receive_review_emails,
                ]);
            }

            $formAddress  = $validated['billingAddress'] ?? [];
            $billingInput = $validated['billingInformation'] ?? [];
            $shippingMethod = $validated['shippingMethod'] ?? $request->input('shippingMethod', []);

            $shippingCost = isset($shippingMethod['cost']) || isset($shippingMethod['total_charge'])
                ? (float) ($shippingMethod['cost'] ?? $shippingMethod['total_charge'])
                : (float) $order->shipping_cost;

            $shippingProvider = $shippingMethod['provider']
                ?? $shippingMethod['method']
                ?? $order->shipping_provider;

            $shippingMethodId = is_numeric($shippingMethod['method'] ?? null)
                ? (int) $shippingMethod['method']
                : $order->shipping_method;

            if ($request->exists('billingInformation') && $order->billingInformation) {
                $billing = $order->billingInformation;
                $billing->update([
                    'first_name'      => $billingInput['firstName'] ?? $billing->first_name,
                    'last_name'       => $billingInput['lastName'] ?? $billing->last_name,
                    'company_name'    => $billingInput['companyName'] ?? $billing->company_name,
                    'email'           => $billingInput['email'] ?? $billing->email,
                    'phone'           => $billingInput['phone'] ?? $billing->phone,
                    'address_line_1'  => $billingInput['addressLine1'] ?? $billing->address_line_1,
                    'address_line_2'  => $billingInput['addressLine2'] ?? $billing->address_line_2,
                    'city'            => $billingInput['city'] ?? $billing->city,
                    'state'           => $billingInput['state'] ?? $billing->state,
                    'zip'             => $billingInput['zip'] ?? $billing->zip,
                    'country'         => $billingInput['country'] ?? $billing->country,
                    'payment_method'  => $validated['paymentMethod']['method']
                        ?? $billingInput['paymentMethod']
                        ?? $billing->payment_method,
                    'shipping_method' => $shippingProvider ?? $billingInput['shippingMethod'] ?? $billing->shipping_method,
                    'shipping_data'   => $shippingMethod['data'] ?? $billing->shipping_data,
                ]);
            }

            if (!empty($formAddress)) {
                $old = $order->billing_address ?? [];
                if (is_string($old)) {
                    $old = json_decode($old, true) ?? [];
                }
                $first = $formAddress['firstName'] ?? ($old['firstName'] ?? null);
                $last  = $formAddress['lastName'] ?? ($old['lastName'] ?? null);

                $order->update([
                    'billing_address' => array_merge($old, [
                        'name'         => $formAddress['name'] ?? (trim(($first ?? '') . ' ' . ($last ?? '')) ?: ($old['name'] ?? null)),
                        'firstName'    => $first,
                        'lastName'     => $last,
                        'companyName'  => $formAddress['companyName'] ?? ($old['companyName'] ?? null),
                        'email'        => $formAddress['email'] ?? ($old['email'] ?? null),
                        'phone'        => $formAddress['phone'] ?? ($old['phone'] ?? null),
                        'addressLine1' => $formAddress['addressLine1'] ?? ($old['addressLine1'] ?? null),
                        'addressLine2' => $formAddress['addressLine2'] ?? ($old['addressLine2'] ?? null),
                        'city'         => $formAddress['city'] ?? ($old['city'] ?? null),
                        'state'        => $formAddress['state'] ?? ($old['state'] ?? null),
                        'zip'          => $formAddress['zip'] ?? ($old['zip'] ?? null),
                        'country'      => $formAddress['country'] ?? ($old['country'] ?? null),
                    ]),
                ]);
            }

            $products = [];
            $itemsTotal = (float) $order->items()->sum(\DB::raw('price * quantity'));

            if (!empty($validated['products'])) {
                $order->items()->delete();
                $itemsTotal = 0;

                foreach ($validated['products'] as $item) {
                    $productId = $item['productId'] ?? $item['product_id'];
                    $product   = Product::where('store_id', $storeId)->findOrFail($productId);
                    $qty       = (int) $item['quantity'];
                    $price     = isset($item['price']) ? (float) $item['price'] : (float) $product->price;

                    OrderItem::create([
                        'order_id'   => $order->id,
                        'product_id' => $product->id,
                        'quantity'   => $qty,
                        'price'      => $price,
                        'total'      => $price * $qty,
                        'store_id'   => $storeId,
                    ]);

                    $itemsTotal += $price * $qty;
                    $products[] = [
                        'product_id' => $product->id,
                        'quantity'   => $qty,
                        'price'      => $price,
                    ];
                }
            } else {
                $products = $order->items->map(fn($i) => [
                    'product_id' => $i->product_id,
                    'quantity'   => $i->quantity,
                    'price'      => $i->price,
                ])->all();
            }

            $discount = $request->exists('discountAmount')
                ? (float) $validated['discountAmount']
                : (float) ($order->discountAmount ?? 0);

            $staffNotes = $validated['staffNotes'] ?? $validated['staff_notes'] ?? $order->staff_notes;

            $order->update([
                'customer_id'       => $customer->id,
                'comments'          => $request->exists('comments') ? ($validated['comments'] ?? null) : $order->comments,
                'staff_notes'       => ($request->exists('staffNotes') || $request->exists('staff_notes'))
                    ? $staffNotes
                    : $order->staff_notes,
                'billing_same'      => $request->exists('billingSame') ? $validated['billingSame'] : $order->billing_same,
                'newsletter'        => $request->exists('newsletter') ? $validated['newsletter'] : $order->newsletter,
                'device_type'       => $validated['deviceType'] ?? $order->device_type,
                'user_type'         => $validated['userType'] ?? $order->user_type,
                'coupon_code'       => $request->exists('couponCode') ? ($validated['couponCode'] ?? null) : $order->coupon_code,
                'discountAmount'    => $discount,
                'shipping_cost'     => $shippingCost,
                'shipping_provider' => $shippingProvider,
                'shipping_method'   => $shippingMethodId,
                'total_amount'      => $itemsTotal + $shippingCost - $discount,
                'is_draft'          => $isDraft,
                'isdrafturl'        => $isDraft ? $order->isdrafturl : null,
            ]);

            if ($isDraft) {
                $cartUser = User::where('store_id', $storeId)
                    ->where('customer_id', $customer->id)
                    ->first();

                if (!$cartUser && !empty($customer->user_id)) {
                    $cartUser = User::where('store_id', $storeId)
                        ->where('id', $customer->user_id)
                        ->first();
                }

                if ($cartUser && !empty($validated['products'])) {
                    foreach ($products as $item) {
                        $existingCart = Cart::where('store_id', $storeId)
                            ->where('user_id', $cartUser->id)
                            ->where('product_id', $item['product_id'])
                            ->first();

                        if ($existingCart) {
                            $existingCart->update(['quantity' => $item['quantity']]);
                        } else {
                            Cart::create([
                                'store_id'   => $storeId,
                                'user_id'    => $cartUser->id,
                                'product_id' => $item['product_id'],
                                'quantity'   => $item['quantity'],
                            ]);
                        }
                    }
                }

                if (empty($order->isdrafturl) || !$wasDraft) {
                    $quoteUrl = $this->draftQuote->makeDraftQuoteUrl(
                        $order->fresh('customer'),
                        $cartUser ?? null,
                        $storeId
                    );
                    $order->update(['isdrafturl' => $quoteUrl]);
                }

                if ($request->exists('billingAddress') || $request->exists('billingInformation') || $request->exists('shippingMethod')) {
                    $sessionId  = $request->header('X-Session-ID') ?? session()->getId();
                    $formUserId = $cartUser?->id ?? $customer->user_id ?? $user?->id;

                    CheckoutFormData::create([
                        'store_id'           => $storeId,
                        'user_id'            => $formUserId,
                        'session_id'         => $sessionId,
                        'billing_form_data'  => $formAddress,
                        'shipping_form_data' => $billingInput,
                    ]);

                    $cartIds = [];
                    if ($cartUser) {
                        $cartIds = Cart::where('store_id', $storeId)
                            ->where('user_id', $cartUser->id)
                            ->whereIn('product_id', collect($products)->pluck('product_id'))
                            ->pluck('id')
                            ->values()
                            ->all();
                    }

                    CartShippingRate::create([
                        'store_id'     => $storeId,
                        'user_id'      => $formUserId,
                        'session_id'   => $sessionId,
                        'cart_ids'     => $cartIds,
                        'country'      => $billingInput['country'] ?? null,
                        'state'        => $billingInput['state'] ?? null,
                        'city'         => $billingInput['city'] ?? null,
                        'zip'          => $billingInput['zip'] ?? null,
                        'service_type' => $shippingMethod['service_type'] ?? $shippingMethod['method'] ?? 'custom',
                        'method_type'  => $shippingMethod['method_type'] ?? $shippingMethod['method'] ?? $shippingProvider ?? 'custom',
                        'total_charge' => (float) ($shippingMethod['total_charge'] ?? $shippingMethod['cost'] ?? $shippingCost),
                    ]);
                }
            }

            DB::commit();

            $order->refresh()->load(['items.product.images', 'items.product.brand', 'billingInformation', 'customer']);

            return response()->json([
                'status'  => true,
                'message' => 'Order updated successfully.',
                'data'    => new OrderResource($order),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Order update failed.',
                'error'   => $e->getMessage(),
                'line'    => $e->getLine(),
            ], 500);
        }
    }


    public function storeNewCustomerOrder(Request $request)
    {
        $storeId = current_store_id();
        if (!$storeId) {
            return response()->json(['status' => false, 'message' => 'Store not selected'], 400);
        }

        try {
            $validated = $request->validate([
                'email'          => 'required|email|unique:customers,email',
                'password'       => 'required|string|min:6|confirmed',
                'firstName'      => 'required|string|max:255',
                'lastName'       => 'required|string|max:255',
                'companyName'    => 'nullable|string|max:255',
                'phone'          => 'nullable|string|max:20',
                'receiveOffers'  => 'nullable|boolean',
                'customerGroup'  => 'nullable|string|max:255',
                'comments'       => 'nullable|string|max:1000',
                'staffNotes'     => 'nullable|string|max:1000',
                'isDraft'        => 'nullable|boolean',
                'deviceType'     => 'nullable|string|max:255',
                'userType'       => 'nullable|string|max:50',
                'couponCode'     => 'nullable|string|max:255',
                'discountAmount' => 'nullable|numeric',
                'manualDiscount' => 'nullable|numeric|min:0',
                'ipAddress'      => 'nullable|string|max:45',
                'billingSame'    => 'nullable|boolean',
                'isSaveAddressForBilling'  => 'nullable|boolean',
                'isSaveAddressForShipping' => 'nullable|boolean',
                'newsletter'     => 'nullable|boolean',
                'emailInvoice' => 'nullable|boolean',

                'billingAddress' => 'nullable|array',
                'billingAddress.firstName'    => 'nullable|string|max:255',
                'billingAddress.lastName'     => 'nullable|string|max:255',
                'billingAddress.name'         => 'nullable|string',
                'billingAddress.email'        => 'nullable|email',
                'billingAddress.phone'        => 'nullable|string|max:20',
                'billingAddress.companyName'  => 'nullable|string|max:255',
                'billingAddress.addressLine1' => 'nullable|string|max:255',
                'billingAddress.addressLine2' => 'nullable|string|max:255',
                'billingAddress.city'         => 'nullable|string|max:100',
                'billingAddress.state'        => 'nullable|string|max:100',
                'billingAddress.zip'          => 'nullable|string|max:20',
                'billingAddress.country'      => 'nullable|string|max:100',
                'billingAddress.saveToAddressBook' => 'nullable|boolean',

                'billingInformation' => 'required|array',
                'billingInformation.firstName'     => 'nullable|string|max:255',
                'billingInformation.lastName'      => 'nullable|string|max:255',
                'billingInformation.phone'         => 'nullable|string|max:20',
                'billingInformation.companyName'   => 'nullable|string|max:255',
                'billingInformation.addressLine1'  => 'nullable|string|max:255',
                'billingInformation.addressLine2'  => 'nullable|string|max:255',
                'billingInformation.city'          => 'nullable|string|max:100',
                'billingInformation.state'         => 'nullable|string|max:100',
                'billingInformation.zip'           => 'nullable|string|max:20',
                'billingInformation.country'       => 'nullable|string|max:100',
                'billingInformation.email'         => 'nullable|email',
                'billingInformation.paymentMethod' => 'nullable|string',
                'billingInformation.shippingMethod' => 'nullable|string',
                'billingInformation.saveToAddressBook' => 'nullable|boolean',

                'paymentMethod'                      => 'required_unless:isDraft,true,1|array',
                'paymentMethod.method'               => 'required_unless:isDraft,true,1|string',
                'paymentMethod.paymentMethodId'      => 'nullable|string',
                'paymentMethod.emailInvoice'         => 'nullable|boolean',
                'paymentMethod.cardholderName'       => 'nullable|string',
                'paymentMethod.creditCardNo'         => 'nullable|string',
                'paymentMethod.ccv2Value'            => 'nullable|string',
                'paymentMethod.expirationMonth'      => 'nullable|string',
                'paymentMethod.expirationYear'       => 'nullable|string',
                'paymentMethod.cardType'             => 'nullable|string',

                'products'              => 'required|array|min:1',
                'products.*.productId'  => 'required_without:products.*.product_id|integer|exists:products,id',
                'products.*.product_id' => 'required_without:products.*.productId|integer|exists:products,id',
                'products.*.quantity'   => 'required|integer|min:1',
                'products.*.price'      => 'nullable|numeric|min:0',

                'shippingDestinations'            => 'nullable|array',
                'shippingDestinations.*.address'  => 'required|array',
                'shippingDestinations.*.products' => 'required|array',

                'shippingMethod'          => 'nullable|array',
                'shippingMethod.provider' => 'nullable|string',
                'shippingMethod.cost'     => 'nullable',
                'shippingMethod.method'   => 'nullable',
                'shippingMethod.data'     => 'nullable|string',
            ]);

            $isDraft = (bool) ($validated['isDraft'] ?? false);
            $rawMethod = strtolower((string) ($validated['paymentMethod']['method'] ?? ''));
            $isCard = in_array($rawMethod, [
                'stripe',
                'credit_card',
                'card',
                'credit card',
                'credit card (via stripe)',
            ], true);

            $paymentMethodId = $validated['paymentMethod']['paymentMethodId'] ?? null;

            if (!$isDraft && $isCard && empty($paymentMethodId)) {
                $cardErrors = $this->validateStripeCard($validated['paymentMethod']);
                if (!empty($cardErrors)) {
                    return response()->json([
                        'status'  => false,
                        'message' => collect($cardErrors)->flatten()->first(),
                        'errors'  => $cardErrors,
                    ], 422);
                }

                $stripeResult = $this->createStripePaymentMethod($validated['paymentMethod']);
                if (!$stripeResult['ok']) {
                    return response()->json([
                        'status'  => false,
                        'message' => $stripeResult['message'],
                        'errors'  => $stripeResult['errors'] ?? null,
                    ], 422);
                }
                $paymentMethodId = $stripeResult['payment_method_id'];
            }

            $validated['paymentMethod']['paymentMethodId'] = $paymentMethodId;
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => collect($e->errors())->flatten()->first(),
                'errors'  => $e->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $user = auth('sanctum')->user();

            $customer = Customer::create([
                'store_id'              => $storeId,
                'first_name'            => $validated['firstName'],
                'last_name'             => $validated['lastName'],
                'company_name'          => $validated['companyName'] ?? null,
                'phone'                 => $validated['phone'] ?? null,
                'email'                 => $validated['email'],
                'password'              => bcrypt($validated['password']),
                'customer_group'        => $validated['customerGroup'] ?? null,
                'receive_review_emails' => $validated['receiveOffers'] ?? true,
            ]);

            $storeUser = User::where('email', $validated['email'])
                ->where('store_id', $storeId)
                ->first();

            if (!$storeUser) {
                $storeUser = User::create([
                    'store_id'    => $storeId,
                    'name'        => trim($validated['firstName'] . ' ' . $validated['lastName']),
                    'email'       => $validated['email'],
                    'password'    => bcrypt($validated['password']),
                    'userRole'    => 2,
                    'customer_id' => $customer->id,
                ]);
            } else {
                $storeUser->customer_id = $customer->id;
                $storeUser->save();
            }

            if (\Illuminate\Support\Facades\Schema::hasColumn($customer->getTable(), 'user_id')) {
                $customer->user_id = $storeUser->id;
                $customer->save();
            }

            try {
                $store = Store::find($storeId);
                $frontendUrl = rtrim($store?->base_url ?? '#', '/');

                $resetToken = \Illuminate\Support\Facades\Password::broker()->createToken($storeUser);
                $resetLink  = $frontendUrl . '/auth/reset-password?token=' . $resetToken
                    . '&email=' . urlencode($validated['email']);

                Mail::to($validated['email'])->send(
                    new \App\Mail\WelcomeLoginMail(
                        $storeUser->name,
                        $validated['email'],
                        $resetLink,
                        $storeId
                    )
                );
            } catch (\Exception $mailEx) {
                \Log::error('NEW CUSTOMER WELCOME LOGIN EMAIL FAILED', [
                    'error' => $mailEx->getMessage(),
                    'email' => $validated['email'],
                ]);
            }

            $formAddress  = $validated['billingAddress'] ?? [];
            $billingInput = $validated['billingInformation'] ?? [];

            $shippingMethod   = $request->input('shippingMethod', $validated['shippingMethod'] ?? []);
            $shippingMethodId = is_numeric($shippingMethod['method'] ?? null)
                ? (int) $shippingMethod['method']
                : null;
            $shippingCost     = (float) (
                $shippingMethod['cost']
                ?? $shippingMethod['total_charge']
                ?? $request->input('shippingMethod.cost')
                ?? $request->input('shippingMethod.total_charge')
                ?? 0
            );
            $shippingProvider = $shippingMethod['provider']
                ?? $shippingMethod['method']
                ?? $shippingMethod['display_name']
                ?? null;
            $shippingData     = $shippingMethod['data']
                ?? $shippingMethod['display_name']
                ?? $shippingMethod['method']
                ?? $billingInput['shippingMethod']
                ?? $shippingProvider;

            $discount       = (float) ($validated['discountAmount'] ?? 0);
            $manualDiscount = (float) ($validated['manualDiscount'] ?? $request->input('manualDiscount', 0));

            $mapProducts = function ($items) use ($storeId) {
                return collect($items)->map(function ($item) use ($storeId) {
                    $productId = $item['product_id'] ?? $item['productId'];
                    $hasPrice  = array_key_exists('price', $item) && $item['price'] !== null && $item['price'] !== '';

                    $price = $hasPrice
                        ? (float) $item['price']
                        : (float) Product::where('store_id', $storeId)->findOrFail($productId)->price;

                    return [
                        'product_id' => $productId,
                        'quantity'   => (int) $item['quantity'],
                        'price'      => $price,
                    ];
                })->all();
            };

            $shippingDestinations = $validated['shippingDestinations'] ?? [];
            if (empty($shippingDestinations)) {
                $shippingDestinations = [[
                    'address'    => $billingInput,
                    'products'   => $validated['products'],
                    'staffNotes' => '',
                ]];
            }

            $displayPaymentMethod = $isCard
                ? 'Credit Card (Via Stripe)'
                : ($validated['paymentMethod']['method'] ?? 'N/A');

            $orders = [];
            $requiresAction = null;

            foreach ($shippingDestinations as $destination) {
                $shippingAddress = $destination['address'] ?? $billingInput;
                $products        = $mapProducts($destination['products'] ?? $validated['products']);

                $cartTotal = 0;
                foreach ($products as $item) {
                    Product::where('store_id', $storeId)->findOrFail($item['product_id']);
                    $cartTotal += $item['price'] * $item['quantity'];
                }

                $totalAmount = $cartTotal + $shippingCost - $discount - $manualDiscount;
                if ($totalAmount < 0) {
                    $totalAmount = 0;
                }

                $shippingLabel = $shippingMethod['display_name']
                    ?? $shippingMethod['data']
                    ?? $shippingMethod['method']
                    ?? $shippingProvider;

                $billing = BillingInformation::create([
                    'store_id'        => $storeId,
                    'customer_id'     => $customer->id,
                    'user_id'         => $user?->id,
                    'first_name'      => $shippingAddress['firstName'] ?? $validated['firstName'] ?? null,
                    'last_name'       => $shippingAddress['lastName'] ?? $validated['lastName'] ?? null,
                    'company_name'    => $shippingAddress['companyName'] ?? $validated['companyName'] ?? null,
                    'email'           => $shippingAddress['email'] ?? $validated['email'] ?? $customer->email,
                    'phone'           => $shippingAddress['phone'] ?? $validated['phone'] ?? null,
                    'address_line_1'  => $shippingAddress['addressLine1'] ?? null,
                    'address_line_2'  => $shippingAddress['addressLine2'] ?? null,
                    'city'            => $shippingAddress['city'] ?? null,
                    'state'           => $shippingAddress['state'] ?? null,
                    'zip'             => $shippingAddress['zip'] ?? null,
                    'country'         => $shippingAddress['country'] ?? null,
                    'payment_method'  => $displayPaymentMethod,
                    'shipping_method' => $shippingProvider ?? $shippingAddress['shippingMethod'] ?? null,
                    'shipping_data'   => [
                        'display_name' => $shippingLabel,
                        'label'        => $shippingLabel,
                        'data'         => $shippingMethod['data'] ?? $shippingLabel,
                    ],
                ]);

                $billFirst = $formAddress['firstName'] ?? $validated['firstName'] ?? $customer->first_name;
                $billLast  = $formAddress['lastName'] ?? $validated['lastName'] ?? $customer->last_name;

                $billingAddressJson = [
                    'name'         => $formAddress['name'] ?? (trim(($billFirst ?? '') . ' ' . ($billLast ?? '')) ?: null),
                    'firstName'    => $billFirst,
                    'lastName'     => $billLast,
                    'companyName'  => $formAddress['companyName'] ?? $validated['companyName'] ?? $customer->company_name,
                    'email'        => $formAddress['email'] ?? $validated['email'] ?? $customer->email,
                    'phone'        => $formAddress['phone'] ?? $validated['phone'] ?? $customer->phone,
                    'addressLine1' => $formAddress['addressLine1'] ?? null,
                    'addressLine2' => $formAddress['addressLine2'] ?? null,
                    'city'         => $formAddress['city'] ?? null,
                    'state'        => $formAddress['state'] ?? null,
                    'zip'          => $formAddress['zip'] ?? null,
                    'country'      => $formAddress['country'] ?? null,
                ];

                $intent   = null;
                $riskData = [];

                if (!$isDraft && $paymentMethodId) {
                    \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

                    $intent = \Stripe\PaymentIntent::create([
                        'amount'               => (int) round($totalAmount * 100),
                        'currency'             => 'usd',
                        'payment_method'       => $paymentMethodId,
                        'confirmation_method'  => 'manual',
                        'payment_method_types' => ['card'],
                        'confirm'              => true,
                        'capture_method'       => 'manual',
                        'metadata'             => [
                            'user_id'  => $user?->id,
                            'channel'  => 'admin-panel',
                            'store_id' => $storeId,
                            'products' => json_encode($products),
                        ],
                    ], [
                        'idempotency_key' => uniqid('dashboard_new_customer_payment_', true),
                    ]);

                    if (in_array($intent->status, ['requires_action', 'requires_source_action'])) {
                        $requiresAction = $intent->client_secret;
                    } elseif ($intent->status !== 'requires_capture') {
                        DB::rollBack();
                        return response()->json([
                            'status'  => false,
                            'message' => 'Payment authorization failed. Status: ' . $intent->status,
                        ], 400);
                    } else {
                        $riskData = [
                            'risk_score' => null,
                            'risk_level' => 'normal',
                        ];

                        try {
                            $freshIntent = \Stripe\PaymentIntent::retrieve(
                                $intent->id,
                                ['expand' => ['latest_charge', 'latest_charge.review', 'review']]
                            );

                            if (empty($freshIntent->latest_charge)) {
                                usleep(800000);
                                $freshIntent = \Stripe\PaymentIntent::retrieve(
                                    $intent->id,
                                    ['expand' => ['latest_charge', 'latest_charge.review', 'review']]
                                );
                            }

                            $charge = null;
                            if (!empty($freshIntent->latest_charge)) {
                                $charge = is_string($freshIntent->latest_charge)
                                    ? \Stripe\Charge::retrieve($freshIntent->latest_charge)
                                    : $freshIntent->latest_charge;
                            }

                            $classicScore  = null;
                            $classicLevel  = null;
                            $outcomeType   = null;
                            $outcomeReason = null;

                            if ($charge && !empty($charge->outcome)) {
                                $classicScore  = isset($charge->outcome->risk_score)
                                    ? (int) $charge->outcome->risk_score
                                    : null;
                                $classicLevel  = $charge->outcome->risk_level ?? null;
                                $outcomeType   = $charge->outcome->type ?? null;
                                $outcomeReason = $charge->outcome->reason ?? null;
                            }

                            $hasReview = !empty($freshIntent->review)
                                || (!empty($charge) && !empty($charge->review))
                                || $outcomeType === 'manual_review';

                            $overallScore = $classicScore;
                            $overallLevel = $classicLevel ?: 'normal';

                            if ($outcomeReason === 'highest_risk_level' || $classicLevel === 'highest') {
                                $overallLevel = 'highest';
                            } elseif ($hasReview || $classicLevel === 'elevated' || $outcomeReason === 'elevated_risk_level') {
                                $overallLevel = 'elevated';
                            }

                            if ($overallScore !== null) {
                                if ($overallScore >= 75) {
                                    $overallLevel = 'highest';
                                } elseif ($overallScore >= 50 && $overallLevel === 'normal') {
                                    $overallLevel = 'highest';
                                } elseif ($overallScore >= 65 && $overallLevel === 'normal') {
                                    $overallLevel = 'elevated';
                                }
                            }

                            try {
                                $pmId = $freshIntent->payment_method ?? $paymentMethodId;
                                if (!empty($pmId)) {
                                    $pm = is_string($pmId)
                                        ? \Stripe\PaymentMethod::retrieve($pmId)
                                        : $pmId;

                                    $checks = $pm->card->checks ?? null;
                                    $streetFail = $checks && (($checks->address_line1_check ?? null) === 'fail');
                                    $cvcFail    = $checks && (($checks->cvc_check ?? null) === 'fail');

                                    if (($streetFail || $cvcFail) && in_array($overallLevel, ['normal', null], true)) {
                                        $overallLevel = 'elevated';
                                    }
                                }
                            } catch (\Exception $pmEx) {
                                \Log::warning('New customer order: could not fetch PM checks: ' . $pmEx->getMessage());
                            }

                            $riskData = [
                                'risk_score' => $overallScore,
                                'risk_level' => $overallLevel,
                            ];
                        } catch (\Exception $e) {
                            \Log::warning('New customer order: could not fetch Stripe risk data: ' . $e->getMessage());
                        }
                    }
                }

                $order = Order::create([
                    'store_id'                     => $storeId,
                    'customer_id'                  => $customer->id,
                    'user_id'                      => $user?->id,
                    'billing_information_id'       => $billing->id,
                    'order_number'                 => '',
                    'status'                       => 'Awaiting Payment',
                    'is_draft'                     => $isDraft,
                    'isdrafturl'                   => null,
                    'device_type'                  => $validated['deviceType'] ?? null,
                    'channel'                      => 'admin-panel',
                    'ip_address'                   => $validated['ipAddress'] ?? null,
                    'email_invoice'                => $validated['emailInvoice'] ?? true,
                    'billing_same'                 => $validated['billingSame'] ?? empty($validated['shippingDestinations']),
                    'is_save_address_for_billing'  => (bool) ($formAddress['saveToAddressBook'] ?? $validated['isSaveAddressForBilling'] ?? false),
                    'is_save_address_for_shipping' => (bool) ($billingInput['saveToAddressBook'] ?? $validated['isSaveAddressForShipping'] ?? false),
                    'newsletter'                   => $validated['newsletter'] ?? $validated['receiveOffers'] ?? false,
                    'shipping_cost'                => $shippingCost,
                    'shipping_provider'            => $shippingProvider,
                    'shipping_method'              => $shippingMethodId,
                    'comments'                     => $validated['comments'] ?? null,
                    'staff_notes'                  => $validated['staffNotes'] ?? $destination['staffNotes'] ?? null,
                    'total_amount'                 => $totalAmount,
                    'discountAmount'               => $discount,
                    'manual_discount'              => $manualDiscount,
                    'coupon_code'                  => $validated['couponCode'] ?? null,
                    'user_type'                    => $validated['userType'] ?? null,
                    'billing_address'              => $billingAddressJson,
                ]);

                $order->update(['order_number' => $order->id]);

                $saveBilling  = (bool) ($formAddress['saveToAddressBook'] ?? $validated['isSaveAddressForBilling'] ?? false);
                $saveShipping = (bool) ($billingInput['saveToAddressBook'] ?? $validated['isSaveAddressForShipping'] ?? false);

                if ($saveBilling || $saveShipping) {
                    $shippingAddressJson = [
                        'name'         => trim(($shippingAddress['firstName'] ?? '') . ' ' . ($shippingAddress['lastName'] ?? '')) ?: null,
                        'firstName'    => $shippingAddress['firstName'] ?? $validated['firstName'] ?? null,
                        'lastName'     => $shippingAddress['lastName'] ?? $validated['lastName'] ?? null,
                        'companyName'  => $shippingAddress['companyName'] ?? $validated['companyName'] ?? null,
                        'email'        => $shippingAddress['email'] ?? $validated['email'] ?? $customer->email,
                        'phone'        => $shippingAddress['phone'] ?? $validated['phone'] ?? null,
                        'addressLine1' => $shippingAddress['addressLine1'] ?? null,
                        'addressLine2' => $shippingAddress['addressLine2'] ?? null,
                        'city'         => $shippingAddress['city'] ?? null,
                        'state'        => $shippingAddress['state'] ?? null,
                        'zip'          => $shippingAddress['zip'] ?? null,
                        'country'      => $shippingAddress['country'] ?? null,
                    ];

                    SavedAddress::create([
                        'store_id'         => $storeId,
                        'user_id'          => $user?->id,
                        'order_id'         => $order->id,
                        'billing_address'  => $saveBilling ? $billingAddressJson : null,
                        'shipping_address' => $saveShipping ? $shippingAddressJson : null,
                    ]);
                }

                foreach ($products as $item) {
                    $product = Product::where('store_id', $storeId)->findOrFail($item['product_id']);
                    OrderItem::create([
                        'order_id'   => $order->id,
                        'product_id' => $product->id,
                        'quantity'   => $item['quantity'],
                        'price'      => $item['price'],
                        'total'      => $item['price'] * $item['quantity'],
                        'store_id'   => $storeId,
                    ]);
                    $product->increment('sold_count', $item['quantity']);
                    $product->decrement('current_stock', $item['quantity']);
                }

                if ($isDraft) {
                    $formUserId = $storeUser?->id ?? $customer->user_id ?? $user?->id;

                    $quoteUrl = $this->draftQuote->makeDraftQuoteUrl(
                        $order->fresh('customer'),
                        $storeUser ?? null,
                        $storeId
                    );

                    $order->update([
                        'isdrafturl' => $quoteUrl,
                    ]);

                    $quoteToken = null;
                    if ($quoteUrl && str_contains($quoteUrl, 'quoteToken=')) {
                        $quoteToken = substr($quoteUrl, strpos($quoteUrl, 'quoteToken=') + 11);
                    }

                    if (!empty($storeUser?->id)) {
                        foreach ($validated['products'] as $raw) {
                            $productId = $raw['product_id'] ?? $raw['productId'];
                            $qty       = (int) $raw['quantity'];
                            $hasPrice  = array_key_exists('price', $raw) && $raw['price'] !== null && $raw['price'] !== '';

                            $cartRow = Cart::create([
                                'store_id'    => $storeId,
                                'user_id'     => $storeUser->id,
                                'product_id'  => $productId,
                                'quantity'    => $qty,
                                'isdraftcart' => $hasPrice ? 1 : 0,
                                'quote_token' => $quoteToken,
                            ]);

                            if ($hasPrice) {
                                OrderCartItemProduct::create([
                                    'order_id'   => $order->id,
                                    'user_id'    => $formUserId,
                                    'product_id' => $productId,
                                    'cart_id'    => $cartRow->id,
                                    'price'      => (float) $raw['price'],
                                    'qty'        => $qty,
                                ]);
                            }
                        }
                    }

                    $sessionId = $request->header('X-Session-ID') ?? session()->getId();

                    CheckoutFormData::create([
                        'store_id'           => $storeId,
                        'user_id'            => $formUserId,
                        'session_id'         => $sessionId,
                        'billing_form_data'  => [
                            'firstName'    => $formAddress['firstName'] ?? $validated['firstName'] ?? null,
                            'lastName'     => $formAddress['lastName'] ?? $validated['lastName'] ?? null,
                            'name'         => $formAddress['name'] ?? null,
                            'email'        => $formAddress['email'] ?? $validated['email'] ?? null,
                            'phone'        => $formAddress['phone'] ?? $validated['phone'] ?? null,
                            'companyName'  => $formAddress['companyName'] ?? $validated['companyName'] ?? null,
                            'addressLine1' => $formAddress['addressLine1'] ?? null,
                            'addressLine2' => $formAddress['addressLine2'] ?? null,
                            'city'         => $formAddress['city'] ?? null,
                            'state'        => $formAddress['state'] ?? null,
                            'zip'          => $formAddress['zip'] ?? null,
                            'country'      => $formAddress['country'] ?? null,
                        ],
                        'shipping_form_data' => [
                            'firstName'    => $shippingAddress['firstName'] ?? $billingInput['firstName'] ?? $validated['firstName'] ?? null,
                            'lastName'     => $shippingAddress['lastName'] ?? $billingInput['lastName'] ?? $validated['lastName'] ?? null,
                            'email'        => $shippingAddress['email'] ?? $billingInput['email'] ?? $validated['email'] ?? null,
                            'phone'        => $shippingAddress['phone'] ?? $billingInput['phone'] ?? $validated['phone'] ?? null,
                            'companyName'  => $shippingAddress['companyName'] ?? $billingInput['companyName'] ?? $validated['companyName'] ?? null,
                            'addressLine1' => $shippingAddress['addressLine1'] ?? $billingInput['addressLine1'] ?? null,
                            'addressLine2' => $shippingAddress['addressLine2'] ?? $billingInput['addressLine2'] ?? null,
                            'city'         => $shippingAddress['city'] ?? $billingInput['city'] ?? null,
                            'state'        => $shippingAddress['state'] ?? $billingInput['state'] ?? null,
                            'zip'          => $shippingAddress['zip'] ?? $billingInput['zip'] ?? null,
                            'country'      => $shippingAddress['country'] ?? $billingInput['country'] ?? null,
                        ],
                    ]);

                    $cartIds = [];
                    if (!empty($storeUser?->id)) {
                        $cartIds = Cart::where('store_id', $storeId)
                            ->where('user_id', $storeUser->id)
                            ->where('quote_token', $quoteToken)
                            ->pluck('id')
                            ->values()
                            ->all();
                    }

                    CartShippingRate::create([
                        'store_id'     => $storeId,
                        'user_id'      => $formUserId,
                        'session_id'   => $sessionId,
                        'cart_ids'     => $cartIds,
                        'country'      => $shippingAddress['country'] ?? $billingInput['country'] ?? null,
                        'state'        => $shippingAddress['state'] ?? $billingInput['state'] ?? null,
                        'city'         => $shippingAddress['city'] ?? $billingInput['city'] ?? null,
                        'zip'          => $shippingAddress['zip'] ?? $billingInput['zip'] ?? null,
                        'service_type' => $shippingMethod['service_type'] ?? $shippingMethod['method'] ?? 'custom',
                        'method_type'  => $shippingMethod['method_type'] ?? $shippingMethod['method'] ?? $shippingProvider ?? 'custom',
                        'total_charge' => (float) (
                            $shippingMethod['total_charge']
                            ?? $shippingMethod['cost']
                            ?? $shippingCost
                            ?? 0
                        ),
                    ]);
                }

                if ($intent && ($intent->status ?? null) === 'requires_capture') {
                    Payment::create(array_merge([
                        'order_id'          => $order->id,
                        'store_id'          => $storeId,
                        'stripe_session_id' => $intent->id,
                        'amount'            => $totalAmount,
                        'discountAmount'    => $discount,
                        'payment_status'    => 'authorized',
                    ], $riskData));
                }

                foreach ($products as $item) {
                    ManualDiscount::create([
                        'store_id'        => $storeId ?: null,
                        'user_id'         => $user?->id ?? null,
                        'customer_id'     => $customer->id ?? null,
                        'order_id'        => $order->id,
                        'product_id'      => $item['product_id'] ?? null,
                        'discount_amount' => $manualDiscount,
                        'total_amount'    => $totalAmount,
                    ]);
                }

                $order->load('billingInformation');

                OrderTimeline::addEvent($order->id, $storeId, 'order_created', 'Order created', [
                    'status' => $order->status,
                ], 'dot');
                OrderTimeline::addEvent($order->id, $storeId, 'status_changed', 'Order status changed', [
                    'previous_status' => 'Incomplete',
                    'new_status'      => $order->status,
                ], 'dot');
                OrderTimeline::addEvent($order->id, $storeId, 'shipping_quote', 'Shipping quote persisted', [
                    'shipping_cost'   => '$' . number_format((float) $order->shipping_cost, 2),
                    'shipping_method' => $order->billingInformation->shipping_method ?? $shippingProvider,
                ], 'dot');
                OrderTimeline::addEvent($order->id, $storeId, 'order_confirmed', 'Order confirmed', [], 'dot');

                if ($intent) {
                    OrderTimeline::addEvent($order->id, $storeId, 'payment_authorized', 'Payment authorized', [
                        'source'         => $displayPaymentMethod,
                        'amount'         => '$' . number_format((float) $order->total_amount, 2),
                        'payment_intent' => $intent->id,
                    ], 'payment');
                }

                $order->load('items.product.images', 'items.product.brand', 'billingInformation', 'customer');

                $emailInvoice = (bool) ($validated['emailInvoice'] ?? true);

                if ($emailInvoice) {
                    try {
                        $email = $billingAddressJson['email'] ?? $customer->email;
                        if ($email) {
                            Mail::to($email)->send(new OrderPlacedMail($order, $customer, $storeId));
                        }
                        $adminEmail = env('MAIL_FROM_SB_ADMIN', 'info@serverblink.uk');
                        Mail::to($adminEmail)->send(new OrderPlacedMail($order, $customer, $storeId));
                    } catch (\Exception $mailError) {
                        \Log::error('NEW CUSTOMER ORDER EMAIL FAILED', [
                            'error' => $mailError->getMessage(),
                        ]);
                    }
                }

                $orders[] = $order;
            }

            foreach ($orders as $ord) {
                $ord->refresh();
            }
            DB::commit();

            if ($requiresAction) {
                return response()->json([
                    'status'                       => 'requires_action',
                    'payment_intent_client_secret' => $requiresAction,
                    'data'                         => OrderResource::collection($orders),
                ]);
            }

            return response()->json([
                'status'  => true,
                'message' => 'New customer created and orders placed successfully.',
                'data'    => OrderResource::collection($orders),
            ]);
        } catch (\Stripe\Exception\CardException $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => $e->getError()->message,
            ], 402);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Order creation failed.',
                'error'   => $e->getMessage(),
                'line'    => $e->getLine(),
                'file'    => $e->getFile(),
            ], 500);
        }
    }

    // public function updateFromDashboard(Request $request, $id)
    // {
    //     $storeId = current_store_id();

    //     $validated = $request->validate([
    //         'customerId' => 'required|exists:customers,id',

    //         // Optional updates to customer
    //         'email'         => 'nullable|email',
    //         'password'      => 'nullable|string|min:6|confirmed',
    //         'firstName'     => 'nullable|string|max:255',
    //         'lastName'      => 'nullable|string|max:255',
    //         'phone'         => 'nullable|string|max:20',
    //         'companyName'   => 'nullable|string|max:255',
    //         'customerGroup' => 'nullable|string|max:255',
    //         'receiveOffers' => 'nullable|boolean',

    //         // Billing
    //         'billingInformation' => 'required|array',
    //         'billingInformation.firstName'      => 'nullable|string|max:255',
    //         'billingInformation.lastName'       => 'nullable|string|max:255',
    //         'billingInformation.phone'          => 'nullable|string|max:20',
    //         'billingInformation.companyName'    => 'nullable|string|max:255',
    //         'billingInformation.addressLine1'   => 'nullable|string|max:255',
    //         'billingInformation.addressLine2'   => 'nullable|string|max:255',
    //         'billingInformation.city'           => 'nullable|string|max:100',
    //         'billingInformation.state'          => 'nullable|string|max:100',
    //         'billingInformation.zip'            => 'nullable|string|max:20',
    //         'billingInformation.country'        => 'nullable|string|max:100',
    //         'billingInformation.paymentMethod'  => 'nullable|string|max:100',
    //         'billingInformation.shippingMethod' => 'nullable|string|max:100',

    //         // Notes
    //         'comments'   => 'nullable|string|max:1000',
    //         'staffNotes' => 'nullable|string|max:1000',

    //         // Products
    //         'products'               => 'required|array|min:1',
    //         'products.*.productId'   => 'required|exists:products,id',
    //         'products.*.quantity'    => 'required|integer|min:1',
    //     ]);

    //     try {
    //         DB::beginTransaction();
    //         $user = auth('sanctum')->user();

    //         // 1. Load order
    //         $order = Order::where('store_id', $storeId)
    //                       ->with(['billingInformation', 'items'])
    //                       ->findOrFail($id);

    //         // 2. Load customer
    //         $customer = Customer::where('store_id', $storeId)
    //                             ->findOrFail($validated['customerId']);

    //         // 3. Optional customer update
    //         $customer->update(array_filter([
    //             'first_name'            => $validated['firstName'] ?? null,
    //             'last_name'             => $validated['lastName'] ?? null,
    //             'company_name'          => $validated['companyName'] ?? null,
    //             'phone'                 => $validated['phone'] ?? null,
    //             'customer_group'        => $validated['customerGroup'] ?? null,
    //             'receive_review_emails' => $validated['receiveOffers'] ?? null,
    //             'country'               => $validated['billingInformation']['country'] ?? null,
    //             'state'                 => $validated['billingInformation']['state'] ?? null,
    //         ]));

    //         // 4. Update billing info
    //         $b = $validated['billingInformation'];
    //         $billing = $order->billingInformation;

    //         $billing->update([
    //             'first_name'       => $b['firstName'] ?? $billing->first_name,
    //             'last_name'        => $b['lastName'] ?? $billing->last_name,
    //             'company_name'     => $b['companyName'] ?? null,
    //             'phone'            => $b['phone'] ?? null,
    //             'address_line_1'   => $b['addressLine1'] ?? null,
    //             'address_line_2'   => $b['addressLine2'] ?? null,
    //             'city'             => $b['city'] ?? null,
    //             'state'            => $b['state'] ?? null,
    //             'zip'              => $b['zip'] ?? null,
    //             'country'          => $b['country'] ?? null,
    //             'payment_method'   => $b['paymentMethod'] ?? null,
    //             'shipping_method'  => $b['shippingMethod'] ?? null,
    //             'user_id'          => $user?->id,
    //             'customer_id'      => $customer->id,
    //         ]);

    //         \Log::info('Billing updated:', $billing->toArray());

    //         // 5. Update order
    //         $order->update([
    //             'customer_id'            => $customer->id,
    //             'billing_information_id' => $billing->id,
    //             'comments'               => $validated['comments'] ?? null,
    //             'staff_notes'            => $validated['staffNotes'] ?? null,
    //         ]);

    //         // 6. Delete old items
    //         $order->items()->delete();

    //         // 7. Insert new items
    //         $total = 0;
    //         foreach ($validated['products'] as $item) {
    //             $product = Product::where('store_id', $storeId)->findOrFail($item['productId']);
    //             $qty = $item['quantity'];
    //             $lineTotal = $product->price * $qty;

    //             OrderItem::create([
    //                 'order_id'   => $order->id,
    //                 'product_id' => $product->id,
    //                 'quantity'   => $qty,
    //                 'price'      => $product->price,
    //                 'total'      => $lineTotal,
    //                 'store_id'   => $storeId,
    //             ]);

    //             $total += $lineTotal;
    //         }

    //         // 8. Update total
    //         $order->update(['total_amount' => $total]);

    //         DB::commit();

    //         // ✅ 9. Reload fresh order with full relationships (this fixes your stale data issue)
    //         $order = Order::with([
    //             'billingInformation',
    //             'items.product.images',
    //             'customer'
    //         ])->findOrFail($order->id);

    //         return response()->json([
    //             'status'   => true,
    //             'message'  => 'Order updated successfully.',
    //             'customer' => new CustomerResource($customer),
    //             'order'    => new OrderResource($order),
    //         ]);

    //     } catch (ModelNotFoundException $e) {
    //         DB::rollBack();
    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Update failed.',
    //             'error'   => 'Record not found.',
    //         ], 404);
    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Update failed.',
    //             'error'   => $e->getMessage(),
    //         ], 500);
    //     }
    // }


    // public function advancedOrderSearch(Request $request)
    // {
    //     try {
    //         $storeId = current_store_id();

    //         if (!$storeId) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Store not selected or token invalid.',
    //             ], 400);
    //         }

    //         $query = Order::with([
    //             'items.product.images',
    //             'billingInformation',
    //             'customer'
    //         ])->where('store_id', $storeId);

    //         // ✅ If 'keywords' is filled, ONLY apply keyword-based filters
    //         if ($request->filled('keywords')) {
    //             $keywords = $request->input('keywords');

    //             $query->where(function ($q) use ($keywords) {
    //                 if (is_numeric($keywords)) {
    //                     $q->orWhere('id', $keywords);
    //                 }

    //                 $q->orWhere('status', 'like', "%{$keywords}%")
    //                   ->orWhere('total_amount', 'like', "%{$keywords}%")
    //                   ->orWhereHas('customer', function ($c) use ($keywords) {
    //                       $c->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$keywords}%"])
    //                         ->orWhere('first_name', 'like', "%{$keywords}%")
    //                         ->orWhere('last_name', 'like', "%{$keywords}%")
    //                         ->orWhere('email', 'like', "%{$keywords}%")
    //                         ->orWhere('phone', 'like', "%{$keywords}%");
    //                   })
    //                   ->orWhereHas('billingInformation', function ($b) use ($keywords) {
    //                       $b->where('first_name', 'like', "%{$keywords}%")
    //                         ->orWhere('last_name', 'like', "%{$keywords}%")
    //                         ->orWhere('phone', 'like', "%{$keywords}%")
    //                         ->orWhere('payment_method', 'like', "%{$keywords}%")
    //                         ->orWhere('shipping_method', 'like', "%{$keywords}%");
    //                   });
    //             });
    //         } else {
    //             $query->where(function ($q) use ($request) {
    //                 if ($request->filled('status')) {
    //                     $q->where('status', $request->input('status'));
    //                 }

    //                 if ($request->filled('paymentMethod')) {
    //                     $q->whereHas('billingInformation', function ($b) use ($request) {
    //                         $b->where('payment_method', $request->input('paymentMethod'));
    //                     });
    //                 }

    //                 if ($request->filled('shippingMethod')) {
    //                     $q->whereHas('billingInformation', function ($b) use ($request) {
    //                         $b->where('shipping_method', $request->input('shippingMethod'));
    //                     });
    //                 }

    //                 if ($request->filled('shippingProvider') && $request->input('shippingProvider') !== 'any') {
    //                     $q->where('tracking_carrier_name', $request->input('shippingProvider'));
    //                 }

    //                 if ($request->filled('coupon') && $request->input('coupon') !== 'any') {
    //                     $q->where('coupon_code', $request->input('coupon'));
    //                 }

    //                 if ($request->filled('fulfillmentSource') && $request->input('fulfillmentSource') !== 'any') {
    //                     $q->where('fulfillment_source', $request->input('fulfillmentSource'));
    //                 }

    //                 if ($request->filled('guest')) {
    //                     $q->whereNull('user_id');
    //                 }

    //                 if ($request->filled('preorderYes')) {
    //                     $q->where('channel', 'preorder');
    //                 }

    //                 if ($request->filled('orderIdFrom')) {
    //                     $q->where('id', '>=', $request->input('orderIdFrom'));
    //                 }

    //                 if ($request->filled('orderIdTo')) {
    //                     $q->where('id', '<=', $request->input('orderIdTo'));
    //                 }

    //                 if ($request->filled('orderTotalFrom')) {
    //                     $q->where('total_amount', '>=', $request->input('orderTotalFrom'));
    //                 }

    //                 if ($request->filled('orderTotalTo')) {
    //                     $q->where('total_amount', '<=', $request->input('orderTotalTo'));
    //                 }

    //                 if ($request->filled('dateRange') && $request->filled('dateType')) {
    //                     $column = match ($request->input('dateType')) {
    //                         'order-date'   => 'created_at',
    //                         'shipped-date' => 'shipped_at',
    //                         default        => 'created_at'
    //                     };

    //                     switch ($request->input('dateRange')) {
    //                         case 'Yesterday':
    //                             $q->whereDate($column, today()->subDay());
    //                             break;
    //                         case 'Today':
    //                             $q->whereDate($column, today());
    //                             break;
    //                         case 'Last7Days':
    //                             $q->whereDate($column, '>=', now()->subDays(7));
    //                             break;
    //                         case 'Last30Days':
    //                             $q->whereDate($column, '>=', now()->subDays(30));
    //                             break;
    //                     }
    //                 }
    //             });
    //         }

    //         $query->orderBy(
    //             $request->input('sortBy', 'id'),
    //             $request->input('sortDirection', 'asc')
    //         );

    //         $perPage = $request->input('perPage', 20);
    //         $orders = $query->paginate($perPage);

    //         return response()->json([
    //             'status'     => true,
    //             'message'    => 'Filtered orders fetched.',
    //             'data'       => OrderResource::collection($orders),
    //             'pagination' => [
    //                 'total'        => $orders->total(),
    //                 'count'        => $orders->count(),
    //                 'perPage'      => $orders->perPage(),
    //                 'currentPage'  => $orders->currentPage(),
    //                 'totalPages'   => $orders->lastPage(),
    //             ]
    //         ]);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Failed to fetch orders.',
    //             'error'   => $e->getMessage(),
    //         ], 500);
    //     }
    // }

    public function advancedOrderSearch(Request $request)
    {
        try {
            $storeId = current_store_id();

            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.',
                ], 400);
            }

            $query = Order::with([
                'items.product.images',
                'billingInformation',
                'customer'
            ])->where('store_id', $storeId);

            // ✅ Keyword search
            if ($request->filled('keywords')) {
                $keywords = trim($request->input('keywords'));

                $customerIds = \App\Models\Customer::where('store_id', $storeId)
                    ->where(function ($c) use ($keywords) {
                        $c->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$keywords}%"])
                            ->orWhere('first_name', 'like', "%{$keywords}%")
                            ->orWhere('last_name', 'like', "%{$keywords}%");
                    })->pluck('id');

                $billingIds = \App\Models\BillingInformation::where('store_id', $storeId)
                    ->where(function ($b) use ($keywords) {
                        $b->where('first_name', 'like', "%{$keywords}%")
                            ->orWhere('last_name', 'like', "%{$keywords}%")
                            ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$keywords}%"])
                            ->orWhere('payment_method', 'like', "%{$keywords}%")
                            ->orWhere('shipping_method', 'like', "%{$keywords}%");
                    })->pluck('id');

                $query->where(function ($q) use ($keywords, $customerIds, $billingIds) {
                    $q->where('order_number', 'like', "%{$keywords}%")
                        ->orWhere('status', 'like', "%{$keywords}%");

                    if (is_numeric($keywords)) {
                        $q->orWhere('id', $keywords)
                            ->orWhere('total_amount', $keywords);
                    }

                    if ($customerIds->isNotEmpty()) {
                        $q->orWhereIn('customer_id', $customerIds);
                    }

                    if ($billingIds->isNotEmpty()) {
                        $q->orWhereIn('billing_information_id', $billingIds);
                    }

                    $q->orWhereHas('items.product', function ($p) use ($keywords) {
                        $p->where('name', 'like', "%{$keywords}%")
                            ->orWhere('sku', 'like', "%{$keywords}%");
                    });
                });
            }

            // ✅ Status filter
            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }

            // ✅ Payment Method
            if ($request->filled('paymentMethod')) {
                $query->whereHas('billingInformation', function ($b) use ($request) {
                    $b->where('payment_method', $request->input('paymentMethod'));
                });
            }

            // ✅ Shipping Provider
            if ($request->filled('shippingProvider') && $request->input('shippingProvider') !== 'any') {
                $query->where('tracking_carrier_name', $request->input('shippingProvider'));
            }

            // ✅ Shipping Method
            if ($request->filled('shippingMethod') && $request->input('shippingMethod') !== 'any') {
                $query->whereHas('billingInformation', function ($b) use ($request) {
                    $b->where('shipping_method', $request->input('shippingMethod'));
                });
            }

            // ✅ Fulfillment Source
            if ($request->filled('fulfillmentSource') && $request->input('fulfillmentSource') !== 'any') {
                $query->where('fulfillment_source', $request->input('fulfillmentSource'));
            }

            // ✅ Coupon Code
            if ($request->filled('coupon') && $request->input('coupon') !== 'any') {
                $query->where('coupon_code', $request->input('coupon'));
            }

            // ✅ Guest orders
            if ($request->filled('guest') && $request->input('guest') === 'true') {
                $query->whereNull('customer_id');
            }

            // ✅ Preorder filters
            if (
                $request->filled('preorderYes') && $request->input('preorderYes') === 'true'
                && (!$request->filled('preorderNo') || $request->input('preorderNo') === 'false')
            ) {
                $query->where('channel', 'preorder');
            } elseif (
                $request->filled('preorderNo') && $request->input('preorderNo') === 'true'
                && (!$request->filled('preorderYes') || $request->input('preorderYes') === 'false')
            ) {
                $query->where(function ($q) {
                    $q->where('channel', '!=', 'preorder')
                        ->orWhereNull('channel');
                });
            }

            // ✅ Order ID Range
            if ($request->filled('orderIdFrom')) {
                $query->where('id', '>=', (int) $request->input('orderIdFrom'));
            }

            if ($request->filled('orderIdTo')) {
                $query->where('id', '<=', (int) $request->input('orderIdTo'));
            }

            // ✅ Order Total Range
            if ($request->filled('orderTotalFrom')) {
                $query->where('total_amount', '>=', (float) $request->input('orderTotalFrom'));
            }

            if ($request->filled('orderTotalTo')) {
                $query->where('total_amount', '<=', (float) $request->input('orderTotalTo'));
            }

            // ✅ Date Range filter
            if ($request->filled('dateRange')) {
                $dateType = $request->input('dateType', 'order-date');

                // Determine column based on date type
                if ($dateType === 'shipped-date') {
                    $columns = ['shipped_at'];
                } elseif ($dateType === 'both') {
                    $columns = ['created_at', 'shipped_at'];
                } else {
                    $columns = ['created_at'];
                }

                $dateRange = $request->input('dateRange');

                if ($dateRange === 'custom' && $request->filled('dateFrom') && $request->filled('dateTo')) {
                    // Custom date range
                    $query->where(function ($q) use ($columns, $request) {
                        foreach ($columns as $i => $col) {
                            $method = $i === 0 ? 'where' : 'orWhere';
                            $q->$method(function ($sub) use ($col, $request) {
                                $sub->whereDate($col, '>=', $request->input('dateFrom'))
                                    ->whereDate($col, '<=', $request->input('dateTo'));
                            });
                        }
                    });
                } else {
                    $dateMap = [
                        'Yesterday'  => today()->subDay(),
                        'Today'      => today(),
                        'Last7Days'  => now()->subDays(7),
                        'Last30Days' => now()->subDays(30),
                        'Last90Days' => now()->subDays(90),
                        'Last365Days' => now()->subDays(365),
                    ];

                    if (isset($dateMap[$dateRange])) {
                        $query->where(function ($q) use ($columns, $dateRange, $dateMap) {
                            foreach ($columns as $i => $col) {
                                $method = $i === 0 ? 'where' : 'orWhere';
                                if ($dateRange === 'Yesterday' || $dateRange === 'Today') {
                                    $q->$method(function ($sub) use ($col, $dateMap, $dateRange) {
                                        $sub->whereDate($col, $dateMap[$dateRange]);
                                    });
                                } else {
                                    $q->$method(function ($sub) use ($col, $dateMap, $dateRange) {
                                        $sub->whereDate($col, '>=', $dateMap[$dateRange]);
                                    });
                                }
                            }
                        });
                    }
                }
            }

            // ✅ Deleted orders
            if ($request->filled('deletedOrder') && $request->input('deletedOrder') !== 'ignore') {
                $deleted = $request->input('deletedOrder');
                if ($deleted === 'only') {
                    $query->onlyTrashed();
                } elseif ($deleted === 'with') {
                    $query->withTrashed();
                }
            }

            // ✅ Sorting
            $sortBy = $request->input('sortBy', 'id');
            $sortDirection = $request->input('sortDirection', 'DESC');

            $allowedSorts = ['id', 'order_number', 'total_amount', 'created_at', 'updated_at', 'status', 'shipped_at'];
            if (!in_array($sortBy, $allowedSorts)) {
                $sortBy = 'id';
            }

            $query->orderBy($sortBy, $sortDirection);

            // ✅ Pagination
            $perPage = (int) $request->input('perPage', 20);
            $orders = $query->paginate($perPage);

            return response()->json([
                'status'     => true,
                'message'    => 'Filtered orders fetched.',
                'data'       => OrderResource::collection($orders),
                'pagination' => [
                    'total'        => $orders->total(),
                    'count'        => $orders->count(),
                    'perPage'      => $orders->perPage(),
                    'currentPage'  => $orders->currentPage(),
                    'totalPages'   => $orders->lastPage(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to fetch orders.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }


    public function downloadInvoice($ids)
    {
        $storeId = current_store_id();

        $idList = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $ids)))));

        if (empty($idList)) {
            return response()->json([
                'status'  => false,
                'message' => 'Please provide at least one order id.',
            ], 422);
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
                    $mime = $mimeTypes[$extension] ?? 'image/png';
                    $logoBase64 = 'data:' . $mime . ';base64,' . base64_encode($logoContent);
                }
            } catch (\Exception $e) {
                \Log::warning('Invoice logo fetch failed: ' . $e->getMessage());
            }
        }

        $orders = Order::with(['items.product.images', 'billingInformation', 'customer', 'payment'])
            ->where('store_id', $storeId)
            ->whereIn('id', $idList)
            ->get();

        if ($orders->isEmpty()) {
            abort(404);
        }

        $pdf = Pdf::loadView('pdf.invoice', [
            'orders'     => $orders,
            'order'      => $orders->first(),
            'logoBase64' => $logoBase64,
            'store'      => $store,
        ]);

        if ($orders->count() === 1) {
            return $pdf->download('invoice-' . $orders->first()->order_number . '.pdf');
        }

        return $pdf->download('invoices-' . now()->format('Ymd-His') . '.pdf');
    }

    public function sendPaymentInvoice($orderId)
    {
        $storeId = current_store_id();

        $order = Order::with(['items.product.images', 'billingInformation', 'customer', 'payment'])
            ->findOrFail($orderId);

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
                    $mime = $mimeTypes[$extension] ?? 'image/png';
                    $logoBase64 = 'data:' . $mime . ';base64,' . base64_encode($logoContent);
                }
            } catch (\Exception $e) {
                \Log::warning('Invoice logo fetch failed: ' . $e->getMessage());
            }
        }

        $pdf = Pdf::loadView('pdf.invoice', [
            'order'      => $order,
            'logoBase64' => $logoBase64,
            'store'      => $store,
        ]);

        // Use billing email
        $email = optional($order->billingInformation)->email;
        if ($email) {
            Mail::to($email)->send(new PaymentInvoiceMail($order, $pdf, $logoBase64, $storeId));
        }

        return response()->json(['message' => 'Invoice email sent successfully']);
    }
    public function resendInvoice($id)
    {
        try {
            $storeId = current_store_id();

            $order = Order::with(['items.product.images', 'billingInformation'])
                ->where('store_id', $storeId)
                ->findOrFail($id);

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
                        $mime = $mimeTypes[$extension] ?? 'image/png';
                        $logoBase64 = 'data:' . $mime . ';base64,' . base64_encode($logoContent);
                    }
                } catch (\Exception $e) {
                    \Log::warning('Invoice logo fetch failed: ' . $e->getMessage());
                }
            }

            $pdf = Pdf::loadView('pdf.invoice', [
                'order'      => $order,
                'logoBase64' => $logoBase64,
            ]);

            $email = optional($order->billingInformation)->email;
            if ($email) {
                Mail::to($email)->send(new PaymentInvoiceMail($order, $pdf, $logoBase64, $storeId));
            }

            return response()->json([
                'status'  => true,
                'message' => 'Invoice resent to ' . ($email ?? 'N/A'),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to resend invoice: ' . $e->getMessage(), [
                'order_id'  => $id,
                'exception' => $e,
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Failed to resend invoice. Please try again later.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function getOrderTimeline($id)
    {
        try {
            $storeId = current_store_id();

            $order = Order::where('store_id', $storeId)->findOrFail($id);

            $timelines = OrderTimeline::where('order_id', $id)
                ->where('store_id', $storeId)
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(function ($event) {
                    return [
                        'id'         => $event->id,
                        'eventType'  => $event->event_type,
                        'title'      => $event->title,
                        'details'    => $event->details,
                        'icon'       => $event->icon,
                        'date'       => $event->created_at->format('F d, Y'),
                        'time'       => $event->created_at->format('g:i A'),
                        'created_at' => $event->created_at,
                    ];
                });

            return response()->json([
                'status' => true,
                'data'   => [
                    'order_id'     => $order->id,
                    'order_number' => $order->order_number,
                    'timeline'     => $timelines,
                ],
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Order not found for this store.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error fetching order timeline', [
                'order_id' => $id,
                'error'    => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'An unexpected error occurred.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function refund($id)
    {
        try {
            $order = Order::where('id', $id)
                ->where('store_id', current_store_id())
                ->firstOrFail();

            if (in_array($order->status, ['Refunded', 'Cancelled'])) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Order already refunded or cancelled.',
                ], 400);
            }

            $order->status = 'Refunded';
            $order->save();

            return response()->json([
                'status'  => true,
                'message' => 'Order refunded successfully.',
                'data'    => new OrderResource($order->load('items.product.images', 'billingInformation')),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Refund failed.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    private function saveBillingInformation(array $data, $user = null, $customer = null)
    {
        return BillingInformation::create([
            'store_id'        => current_store_id(),
            'customer_id'     => $customer?->id,
            'user_id'         => $user?->id,
            'first_name'      => $data['first_name'],
            'last_name'       => $data['last_name'],
            'company_name'    => $data['company_name'] ?? null,
            'email'           => $data['email'] ?? null,
            'phone'           => $data['phone'] ?? null,
            'address_line_1'  => $data['address_line_1'],
            'address_line_2'  => $data['address_line_2'] ?? null,
            'city'            => $data['city'] ?? null,
            'state'           => $data['state'] ?? null,
            'zip'             => $data['zip'] ?? null,
            'country'         => $data['country'] ?? null,
            'payment_method'  => $data['payment_method'],
            'shipping_method' => $data['shipping_method'],
        ]);
    }

    public function getCustomerByOrder($orderId)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected.'
                ], 400);
            }

            $order = Order::where('id', $orderId)
                ->where('store_id', $storeId)
                ->first();

            if (!$order) {
                return response()->json([
                    'status' => false,
                    'message' => 'Order not found.'
                ], 404);
            }

            $customer = Customer::where('id', $order->customer_id)
                ->where('store_id', $storeId)
                ->first();

            if (!$customer) {
                return response()->json([
                    'status' => false,
                    'message' => 'Customer not found.'
                ], 404);
            }

            return response()->json([
                'status' => true,
                'message' => 'Customer retrieved successfully.',
                'data' => new CustomerResource($customer),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
