<?php

namespace App\Http\Controllers\api\Web;

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
use App\Http\Resources\OrderNoteResource;
use App\Http\Resources\ReturnRequestResource;
use App\Mail\OrderPlacedMail;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        try {
            $user = auth('sanctum')->user();
            $sessionId = $request->header('X-Session-ID') ?? session()->getId();
            $perPage = $request->query('perPage', 20);
            $keyword = $request->query('keyword');
            $status = $request->query('status');
            $productId = $request->query('productId');

            $storeId = current_store_id();

            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not found or unauthorized.',
                ], 403);
            }

            $orders = Order::with(['items.product.images', 'customer', 'billingInformation', 'notes'])
                ->where('store_id', $storeId)
                ->where('is_draft', 0)
                ->when($status, function ($q) use ($status) {
                    $q->where('status', $status);
                })
                ->when($productId, function ($q) use ($productId) {
                    $q->whereHas('items', function ($item) use ($productId) {
                        $item->where('product_id', (int) $productId);
                    });
                })
                ->when($keyword, function ($q) use ($keyword) {
                    $q->where(function ($query) use ($keyword) {
                        $query->where('id', 'like', "%{$keyword}%")
                            ->orWhere('order_number', 'like', "%{$keyword}%")
                            ->orWhere('status', 'like', "%{$keyword}%")
                            ->orWhere('total_amount', 'like', "%{$keyword}%")
                            ->orWhereHas('customer', function ($c) use ($keyword) {
                                $c->where('first_name', 'like', "%{$keyword}%")
                                    ->orWhere('last_name', 'like', "%{$keyword}%")
                                    ->orWhere('email', 'like', "%{$keyword}%")
                                    ->orWhere('phone', 'like', "%{$keyword}%");
                            })
                            ->orWhereHas('billingInformation', function ($b) use ($keyword) {
                                $b->where('first_name', 'like', "%{$keyword}%")
                                    ->orWhere('last_name', 'like', "%{$keyword}%")
                                    ->orWhere('phone', 'like', "%{$keyword}%")
                                    ->orWhere('payment_method', 'like', "%{$keyword}%")
                                    ->orWhere('shipping_method', 'like', "%{$keyword}%");
                            });
                    });
                })
                ->latest()
                ->paginate($perPage);

            return response()->json([
                'status' => true,
                'message' => 'Orders retrieved successfully',
                'data' => OrderResource::collection($orders),
                'pagination' => [
                    'total' => $orders->total(),
                    'count' => $orders->count(),
                    'perPage' => $orders->perPage(),
                    'currentPage' => $orders->currentPage(),
                    'totalPages' => $orders->lastPage(),
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve orders',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    public function show(Request $request, $id)
    {
        try {
            $user = auth('sanctum')->user();
            $sessionId = $request->header('X-Session-ID') ?? session()->getId();

            $orderQuery = Order::with(['items.product.images', 'customer', 'billingInformation'])
                ->where('id', $id)
                ->where('store_id', current_store_id());

            $isAdmin = $user && (int)($user->userRole ?? 0) === 1;

            if (!$isAdmin) {
                $orderQuery->when($user, fn($q) => $q->where('user_id', $user->id))
                    ->when(!$user, fn($q) => $q->where('session_id', $sessionId));
            }

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
                    "shippingMethod" => $order->billingInformation->shipping_method ?? null
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
                    return (new ProductResource($item->product))->additional([
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
                'orders' => [$orderResponse]
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
                    "shippingMethod" => $order->billingInformation->shipping_method ?? null
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

                // Single Address - Ab optional
                'shippingMethod' => 'nullable|string|max:100',
                'shippingCost'   => 'nullable|numeric',

                'comments'       => 'nullable|string|max:1000',
                'discountAmount' => 'nullable|numeric',
                'paymentIntentId' => 'required|string',
                'isMultiAddress' => 'required|boolean',

                // ===================== MULTI ADDRESS =====================
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

                // Multi Address mein shipping REQUIRED
                'destinations.*.shippingMethod' => 'required|string|max:100',
                'destinations.*.shippingCost'   => 'required|numeric',

                'destinations.*.products'       => 'required|array|min:1',
                'destinations.*.products.*.product_id' => 'required|integer|exists:products,id',
                'destinations.*.products.*.quantity'   => 'required|integer|min:1',

                // Single Address Products
                'products'       => 'required_if:isMultiAddress,false|array|min:1',
                'products.*.product_id' => 'required|integer|exists:products,id',
                'products.*.quantity'   => 'required|integer|min:1',
            ]);

            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json(['status' => false, 'message' => 'Store not selected'], 400);
            }

            // ===================== IMPROVED PAYMENT CHECK =====================
            $existingPayment = Payment::where('stripe_session_id', $validated['paymentIntentId'])->first();

            if ($existingPayment) {
                if ($existingPayment->payment_status === 'completed') {
                    // Already completed → return existing order
                    $order = Order::with('items.product.images', 'billingInformation', 'customer')
                        ->find($existingPayment->order_id);

                    return response()->json([
                        'status'  => true,
                        'message' => 'Order already placed successfully.',
                        'data'    => new OrderResource($order),
                    ]);
                } else {
                    // Pending or failed → block
                    return response()->json([
                        'status'  => false,
                        'message' => 'This payment is already being processed.'
                    ], 409);
                }
            }

            // ===================== USER & CUSTOMER =====================
            $user = auth('sanctum')->user();
            $customer = null;

            if ($user) {
                $customer = $user->customer;
            } else {
                $user = User::where('email', $validated['email'])->first();
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
                ]);

                if ($user) {
                    $user->customer_id = $customer->id;
                    $user->save();
                }
            }

            if (!$user) {
                $plainPassword = Str::random(10);
                $user = User::create([
                    'name'      => trim($customer->first_name . ' ' . $customer->last_name),
                    'email'     => $validated['email'],
                    'password'  => Hash::make($plainPassword),
                    'userRole'  => 2,
                    'store_id'  => $storeId,
                    'customer_id' => $customer->id,
                ]);

                Mail::raw(
                    "Hello {$customer->first_name},\n\nYour account has been created successfully.\n\nLogin Email: {$validated['email']}\nPassword: {$plainPassword}\n\nThanks,\nTeam",
                    function ($message) use ($validated) {
                        $message->to($validated['email'])->subject('Your Login Details');
                    }
                );
            }

            // ===================== ORDERS =====================
            $orders = [];
            $totalOrderAmount = 0;
            $discount = $validated['discountAmount'] ?? 0;
            $isMulti = $validated['isMultiAddress'];

            if (!$isMulti) {
                // Single Address
                $cartTotal = 0;
                foreach ($validated['products'] as $item) {
                    $product = Product::find($item['product_id']);
                    if ($product) $cartTotal += $product->price * $item['quantity'];
                }

                $totalAmount = $cartTotal + $validated['shippingCost'] - $discount;

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
                ]);

                $order = Order::create([
                    'store_id'               => $storeId,
                    'user_id'                => $user->id,
                    'customer_id'            => $customer->id,
                    'order_number'           => 'ORD-' . strtoupper(Str::random(8)),
                    'billing_information_id' => $billing->id,
                    'status'                 => 'Pending',
                    'shipping_cost'          => $validated['shippingCost'],
                    'comments'               => $validated['comments'] ?? null,
                    'device_type'            => $validated['deviceType'] ?? null,
                    'user_type'              => $validated['userType'] ?? null,
                    'total_amount'           => $totalAmount,
                    'discountAmount'         => $discount,
                    'is_multi_address'       => false,
                    'newsletter'             => $validated['newsletter'] ?? false,
                ]);

                foreach ($validated['products'] as $item) {
                    $product = Product::find($item['product_id']);
                    if ($product) {
                        $order->items()->create([
                            'store_id'   => $storeId,
                            'product_id' => $product->id,
                            'quantity'   => $item['quantity'],
                            'price'      => $product->price,
                        ]);
                    }
                }

                $orders[] = $order;
                $totalOrderAmount = $totalAmount;
            } else {
                // Multi Address
                foreach ($validated['destinations'] as $dest) {
                    $cartTotal = 0;
                    foreach ($dest['products'] as $item) {
                        $product = Product::find($item['product_id']);
                        if ($product) $cartTotal += $product->price * $item['quantity'];
                    }

                    $totalAmount = $cartTotal + $dest['shippingCost'] - $discount;

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
                    ]);

                    $order = Order::create([
                        'store_id'               => $storeId,
                        'user_id'                => $user->id,
                        'customer_id'            => $customer->id,
                        'order_number'           => 'ORD-' . strtoupper(Str::random(8)),
                        'billing_information_id' => $billing->id,
                        'status'                 => 'Pending',
                        'shipping_cost'          => $dest['shippingCost'],
                        'comments'               => $validated['comments'] ?? null,
                        'device_type'            => $validated['deviceType'] ?? null,
                        'user_type'              => $validated['userType'] ?? null,
                        'total_amount'           => $totalAmount,
                        'discountAmount'         => $discount,
                        'is_multi_address'       => true,
                        'newsletter'             => $validated['newsletter'] ?? false,
                    ]);

                    foreach ($dest['products'] as $item) {
                        $product = Product::find($item['product_id']);
                        if ($product) {
                            $order->items()->create([
                                'store_id'   => $storeId,
                                'product_id' => $product->id,
                                'quantity'   => $item['quantity'],
                                'price'      => $product->price,
                            ]);
                        }
                    }

                    $orders[] = $order;
                    $totalOrderAmount += $totalAmount;
                }
            }

            // ===================== PAYMENT =====================
            Payment::create([
                'order_id'           => $orders[0]->id,
                'stripe_session_id'  => $validated['paymentIntentId'],
                'amount'             => $totalOrderAmount,
                'discountAmount'     => $discount,
                'payment_status'     => 'authorized',  // ← CHANGE: 'completed' se 'authorized'
            ]);
            // Response
            foreach ($orders as $order) {
                $order->load('items.product.images', 'billingInformation', 'customer');
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

    public function updateOrderStatus(Request $request, $id)
    {
        $request->validate(['status' => 'required|string']);

        try {
            $order = Order::where('id', $id)
                ->where('store_id', current_store_id())
                ->firstOrFail();

            $order->status = $request->input('status');
            $order->save();

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
            'order_number' => 'DRAFT-' . strtoupper(Str::random(6)),
            'status'       => 'Draft',
            'is_draft'     => true,
            'channel'      => $validated['channel'],
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
            'order.customer'
        ])
            ->where('store_id', current_store_id())
            ->latest()
            ->paginate(20);

        return response()->json([
            'status' => true,
            'data'   => ReturnRequestResource::collection($requests)
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

        try {
            $validated = $request->validate([
                'customerId' => 'required|exists:customers,id',
                'isDraft' => 'nullable|boolean',
                'deviceType' => 'nullable|string|max:255',
                'customerGroup' => 'nullable|string|max:255',
                'receiveOffers' => 'nullable|boolean',
                'comments' => 'nullable|string|max:1000',
                'staffNotes' => 'nullable|string|max:1000',

                'billingInformation' => 'required|array',
                'billingInformation.firstName' => 'nullable|string',
                'billingInformation.lastName' => 'nullable|string',
                'billingInformation.email' => 'nullable|email',
                'billingInformation.phone' => 'nullable|string',
                'billingInformation.companyName' => 'nullable|string',
                'billingInformation.addressLine1' => 'nullable|string',
                'billingInformation.addressLine2' => 'nullable|string',
                'billingInformation.city' => 'nullable|string',
                'billingInformation.state' => 'nullable|string',
                'billingInformation.zip' => 'nullable|string',
                'billingInformation.country' => 'nullable|string',

                'paymentMethod' => 'nullable|array',
                'paymentMethod.method' => 'nullable|string',
                'paymentMethod.cardholderName' => 'nullable|string',
                'paymentMethod.creditCardNo' => 'nullable|string',
                'paymentMethod.ccv2Value' => 'nullable|string',
                'paymentMethod.expirationMonth' => 'nullable|string',
                'paymentMethod.expirationYear' => 'nullable|string',
                'paymentMethod.emailInvoice' => 'nullable|boolean',

                'products' => 'required|array|min:1',
                'products.*.productId' => 'required|exists:products,id',
                'products.*.quantity' => 'required|integer|min:1',

                'shippingDestinations' => 'nullable|array',

                'shippingMethod' => 'nullable|array',
                'shippingMethod.provider' => 'nullable|string',
                'shippingMethod.method' => 'nullable|string',
                'shippingMethod.cost' => 'nullable|string',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'errors' => $e->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $user = auth('sanctum')->user();

            $customer = Customer::where('store_id', $storeId)
                ->findOrFail($validated['customerId']);

            if (!empty($validated['shippingDestinations'])) {
                $shippingDestinations = $validated['shippingDestinations'];
            } else {
                $shippingDestinations = [
                    [
                        'address' => $validated['billingInformation'],
                        'products' => $validated['products'],
                    ]
                ];
            }

            $ordersResponse = [];

            foreach ($shippingDestinations as $destination) {

                $billing = $this->saveBillingInformation([
                    'store_id' => $storeId,
                    'customer_id' => $customer->id,
                    'user_id' => $user?->id,
                    'first_name' => $destination['address']['firstName'] ?? null,
                    'last_name' => $destination['address']['lastName'] ?? null,
                    'email' => $destination['address']['email'] ?? null,
                    'phone' => $destination['address']['phone'] ?? null,
                    'company_name' => $destination['address']['companyName'] ?? null,
                    'address_line_1' => $destination['address']['addressLine1'] ?? null,
                    'address_line_2' => $destination['address']['addressLine2'] ?? null,
                    'city' => $destination['address']['city'] ?? null,
                    'state' => $destination['address']['state'] ?? null,
                    'zip' => $destination['address']['zip'] ?? null,
                    'country' => $destination['address']['country'] ?? null,
                    'payment_method' => $validated['paymentMethod']['method'] ?? null,
                    'shipping_method' => $validated['shippingMethod']['method'] ?? null,
                ], $user, $customer);

                $order = Order::create([
                    'store_id' => $storeId,
                    'customer_id' => $customer->id,
                    'user_id' => $user->id,
                    'billing_information_id' => $billing->id,
                    'order_number' => 'ORD-' . strtoupper(Str::random(8)),
                    'status' => 'Awaiting Payment',
                    'is_draft' => $validated['isDraft'] ?? false,
                    'device_type' => $validated['deviceType'] ?? null,
                    'channel' => 'admin-panel',
                    'shipping_cost' => (float)($validated['shippingMethod']['cost'] ?? 0),
                    'shipping_provider' => $validated['shippingMethod']['provider'] ?? null,
                    'shipping_method' => $validated['shippingMethod']['method'] ?? null,
                    'comments' => $validated['comments'] ?? null,
                    'staff_notes' => $validated['staffNotes'] ?? null,
                    'total_amount' => 0,
                ]);

                $total = 0;

                foreach ($destination['products'] as $item) {

                    $product = Product::where('store_id', $storeId)
                        ->findOrFail($item['productId']);

                    $lineTotal = $product->price * $item['quantity'];

                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                        'quantity' => $item['quantity'],
                        'price' => $product->price,
                        'total' => $lineTotal,
                        'store_id' => $storeId,
                    ]);

                    $total += $lineTotal;
                }

                $order->update(['total_amount' => $total]);

                $orderData = [
                    'orderNumber' => $order->order_number,
                    'totalAmount' => $order->total_amount,
                    'status' => $order->status,
                ];

                $ordersResponse[] = $orderData;

                // ✅ EMAIL SEND - customer ki email pe
                $customerEmail = $destination['address']['email']
                    ?? $customer->email
                    ?? null;

                if ($customerEmail) {
                    Mail::to($customerEmail)->send(new OrderPlacedMail($orderData, $customer));
                }
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Order placed successfully',
                'orders' => $ordersResponse
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // getDraftOrders Api

    public function getDraftOrders(Request $request)
    {
        try {
            $storeId = current_store_id();

            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected or token invalid.'
                ], 400);
            }

            // ✅ isDraft must be true
            if (!$request->boolean('isDraft')) {
                return response()->json([
                    'status' => false,
                    'message' => 'isDraft must be true'
                ], 400);
            }

            $orders = Order::with([
                'customer',
                'billingInformation',
                'orderItems.product'
            ])
                ->where('store_id', $storeId)
                ->where('is_draft', 1) // 🔥 FIX
                ->orderBy('id', 'desc')
                ->get();
            $response = [];

            foreach ($orders as $order) {

                $products = [];

                foreach ($order->orderItems as $item) {
                    $products[] = [
                        'productId' => $item->product_id,
                        'quantity'  => $item->quantity,
                    ];
                }

                $response[] = [
                    'orderId'     => $order->id,
                    'customerId'     => $order->customer_id,
                    'email'          => $order->billingInformation?->email,
                    'firstName'      => $order->billingInformation?->first_name,
                    'lastName'       => $order->billingInformation?->last_name,
                    'phone'          => $order->billingInformation?->phone,
                    'companyName'    => $order->billingInformation?->company_name,
                    'customerGroup'  => null,
                    'receiveOffers'  => true,
                    'isDraft'        => true,
                    'comments'       => $order->comments,
                    'totalAmount'    => $order->total_amount,
                    'staffNotes'     => $order->staff_notes,
                    'created_at'     => $order->created_at,
                    'updated_at'     => $order->updated_at,

                    'billingInformation' => [
                        'firstName'    => $order->billingInformation?->first_name,
                        'lastName'     => $order->billingInformation?->last_name,
                        'phone'        => $order->billingInformation?->phone,
                        'companyName'  => $order->billingInformation?->company_name,
                        'addressLine1' => $order->billingInformation?->address_line_1,
                        'addressLine2' => $order->billingInformation?->address_line_2,
                        'city'         => $order->billingInformation?->city,
                        'state'        => $order->billingInformation?->state,
                        'zip'          => $order->billingInformation?->zip,
                        'country'      => $order->billingInformation?->country,
                    ],

                    'paymentMethod' => [
                        'method' => 'draft'
                    ],

                    'shippingMethod' => [
                        'provider' => $order->shipping_provider,
                        'method'   => $order->shipping_method,
                        'cost'     => (string)$order->shipping_cost,
                    ],

                    'products' => $products,

                    'shippingDestinations' => []
                ];
            }

            return response()->json([
                'status' => true,
                'data'   => $response
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateFromDashboard(Request $request, $orderId)
    {
        $storeId = current_store_id();

        $validated = $request->validate([
            'customerId' => 'required|exists:customers,id',
            'customerGroup' => 'nullable|string|max:255',
            'receiveOffers' => 'nullable|boolean',
            'comments'      => 'nullable|string|max:1000',
            'staffNotes'    => 'nullable|string|max:1000',
            'billingInformation' => 'required|array',
            'billingInformation.firstName' => 'nullable|string|max:255',
            'billingInformation.lastName'  => 'nullable|string|max:255',
            'billingInformation.phone'     => 'nullable|string|max:20',
            'billingInformation.companyName' => 'nullable|string|max:255',
            'billingInformation.addressLine1' => 'nullable|string|max:255',
            'billingInformation.addressLine2' => 'nullable|string|max:255',
            'billingInformation.city'         => 'nullable|string|max:100',
            'billingInformation.state'        => 'nullable|string|max:100',
            'billingInformation.zip'          => 'nullable|string|max:20',
            'billingInformation.country'      => 'nullable|string|max:100',
            'billingInformation.email'        => 'nullable|email',
            'billingInformation.paymentMethod' => 'nullable|string',
            'billingInformation.shippingMethod' => 'nullable|string',
            'paymentMethod' => 'nullable|array',
            'paymentMethod.method' => 'nullable|string',
            'paymentMethod.cardType' => 'nullable|string',
            'paymentMethod.cardholderName' => 'nullable|string',
            'paymentMethod.creditCardNo' => 'nullable|string',
            'paymentMethod.ccv2Value' => 'nullable|string',
            'paymentMethod.expirationMonth' => 'nullable|string',
            'paymentMethod.expirationYear' => 'nullable|string',
            'paymentMethod.emailInvoice' => 'nullable|boolean',
            'products' => 'required|array|min:1',
            'products.*.productId' => 'required|exists:products,id',
            'products.*.quantity' => 'required|integer|min:1',
            'shippingDestinations' => 'nullable|array',
            'shippingDestinations.*.address' => 'required|array',
            'shippingDestinations.*.products' => 'required|array',
            'shippingMethod' => 'nullable|array',
            'shippingMethod.provider' => 'nullable|string',
            'shippingMethod.cost' => 'nullable|string',
            'shippingMethod.method' => 'nullable|string',
            'isDraft' => 'nullable|boolean',

        ]);

        try {
            DB::beginTransaction();

            $user = auth('sanctum')->user();
            $order = Order::where('store_id', $storeId)->findOrFail($orderId);
            $customer = Customer::where('store_id', $storeId)->findOrFail($validated['customerId']);

            // Update customer preferences
            $customer->update([
                'customer_group'        => $validated['customerGroup'] ?? $customer->customer_group,
                'receive_review_emails' => $validated['receiveOffers'] ?? $customer->receive_review_emails,
            ]);

            // Update billing info
            $billing = $order->billingInformation;
            $billing->update([
                'first_name'      => $validated['billingInformation']['firstName'] ?? $billing->first_name,
                'last_name'       => $validated['billingInformation']['lastName'] ?? $billing->last_name,
                'company_name'    => $validated['billingInformation']['companyName'] ?? $billing->company_name,
                'email'           => $validated['billingInformation']['email'] ?? $billing->email,
                'phone'           => $validated['billingInformation']['phone'] ?? $billing->phone,
                'address_line_1'  => $validated['billingInformation']['addressLine1'] ?? $billing->address_line_1,
                'address_line_2'  => $validated['billingInformation']['addressLine2'] ?? $billing->address_line_2,
                'city'            => $validated['billingInformation']['city'] ?? $billing->city,
                'state'           => $validated['billingInformation']['state'] ?? $billing->state,
                'zip'             => $validated['billingInformation']['zip'] ?? $billing->zip,
                'country'         => $validated['billingInformation']['country'] ?? $billing->country,
                'payment_method'  => $validated['paymentMethod']['method'] ?? $billing->payment_method,
                'shipping_method' => $validated['billingInformation']['shippingMethod'] ?? $billing->shipping_method,
            ]);

            // Remove existing order items
            $order->items()->delete();

            $total = 0;
            foreach ($validated['products'] as $item) {
                $product = Product::where('store_id', $storeId)->findOrFail($item['productId']);
                $qty = $item['quantity'];
                $lineTotal = $product->price * $qty;

                OrderItem::create([
                    'order_id'   => $order->id,
                    'product_id' => $product->id,
                    'quantity'   => $qty,
                    'price'      => $product->price,
                    'total'      => $lineTotal,
                    'store_id'   => $storeId,
                ]);

                $total += $lineTotal;
            }

            // Update order
            $order->update([
                'customer_id'       => $customer->id,
                'user_id'           => $user->id,
                'comments'          => $validated['comments'] ?? $order->comments,
                'is_draft'          => $validated['isDraft'] ?? $order->is_draft,
                'staff_notes'       => $validated['staffNotes'] ?? $order->staff_notes,
                'shipping_cost'     => (float)($validated['shippingMethod']['cost'] ?? $order->shipping_cost),
                'shipping_provider' => $validated['shippingMethod']['provider'] ?? $order->shipping_provider,
                'shipping_method'   => $validated['shippingMethod']['method'] ?? $order->shipping_method,
                'total_amount'      => $total,
            ]);

            DB::commit();

            // Build response
            $ordersResponse = [
                [
                    'id' => $order->id,
                    'orderNumber' => $order->order_number,
                    'status' => ucfirst($order->status),
                    'shippingCost' => $order->shipping_cost,
                    'comments' => $order->comments,
                    'staffNotes' => $order->staff_notes,
                    'isDraft' => $order->is_draft,
                    'totalAmount' => $order->total_amount,
                    'trackingNumber' => $order->tracking_number,
                    'carrierCode' => $order->shipping_provider,
                    'carrierName' => $order->shipping_provider,
                    'shippedAt' => $order->shipped_at,
                    'customerId' => $customer->id,
                    'customerGroup' => $customer->customer_group,
                    'receiveOffers' => $customer->receive_review_emails,
                    'customer' => new CustomerResource($customer),
                    'billingInformation' => [
                        'firstName' => $validated['billingInformation']['firstName'] ?? null,
                        'lastName' => $validated['billingInformation']['lastName'] ?? null,
                        'phone' => $validated['billingInformation']['phone'] ?? null,
                        'companyName' => $validated['billingInformation']['companyName'] ?? null,
                        'addressLine1' => $validated['billingInformation']['addressLine1'] ?? null,
                        'addressLine2' => $validated['billingInformation']['addressLine2'] ?? null,
                        'city' => $validated['billingInformation']['city'] ?? null,
                        'state' => $validated['billingInformation']['state'] ?? null,
                        'zip' => $validated['billingInformation']['zip'] ?? null,
                        'country' => $validated['billingInformation']['country'] ?? null,
                        'email' => $validated['billingInformation']['email'] ?? $customer->email,
                        'paymentMethod' => $order->billingInformation->payment_method ?? null,
                        'shippingMethod' => $order->billingInformation->shipping_method ?? null,
                    ],
                    'paymentMethod' => [
                        'method' => $validated['paymentMethod']['method'] ?? null,
                        'cardType' => $validated['paymentMethod']['cardType'] ?? null,
                        'cardholderName' => $validated['paymentMethod']['cardholderName'] ?? null,
                        'creditCardNo' => $validated['paymentMethod']['creditCardNo'] ?? null,
                        'ccv2Value' => $validated['paymentMethod']['ccv2Value'] ?? null,
                        'expirationMonth' => $validated['paymentMethod']['expirationMonth'] ?? null,
                        'expirationYear' => $validated['paymentMethod']['expirationYear'] ?? null,
                        'emailInvoice' => $validated['paymentMethod']['emailInvoice'] ?? null,
                    ],
                    'products' => $order->items->map(function ($item) use ($storeId) {
                        $product = Product::where('store_id', $storeId)->find($item->product_id);
                        return $product
                            ? (new ProductResource($product))->additional([
                                'quantity' => $item->quantity,
                                'price' => $item->price,
                            ])
                            : null;
                    })->filter()->values(),
                    'shippingDestinations' => [
                        [
                            'address' => $validated['shippingDestinations'][0]['address'] ?? $validated['billingInformation'],
                            'products' => $validated['shippingDestinations'][0]['products'] ?? $validated['products']
                        ]
                    ],
                    'shippingMethod' => [
                        'provider' => $order->shipping_provider,
                        'cost' => $order->shipping_cost,
                        'method' => $order->shipping_method,
                    ],
                ]
            ];

            return response()->json([
                'status' => true,
                'message' => 'Order updated successfully.',
                'orders' => $ordersResponse
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Order update failed.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }


    public function storeNewCustomerOrder(Request $request)
    {
        $storeId = current_store_id();

        $validated = $request->validate([
            'email' => 'required|email|unique:customers,email',
            'password' => 'required|string|min:6|confirmed',
            'firstName' => 'required|string|max:255',
            'lastName' => 'required|string|max:255',
            'companyName' => 'nullable|string|max:255',
            'receiveOffers' => 'nullable|boolean',
            'comments' => 'nullable|string|max:1000',
            'staffNotes' => 'nullable|string|max:1000',
            'isDraft' => 'nullable|boolean',
            'deviceType' => 'nullable|string|max:255',

            'billingInformation' => 'required|array',
            'billingInformation.firstName' => 'nullable|string|max:255',
            'billingInformation.lastName' => 'nullable|string|max:255',
            'billingInformation.phone' => 'nullable|string|max:20',
            'billingInformation.companyName' => 'nullable|string|max:255',
            'billingInformation.addressLine1' => 'nullable|string|max:255',
            'billingInformation.addressLine2' => 'nullable|string|max:255',
            'billingInformation.city' => 'nullable|string|max:100',
            'billingInformation.state' => 'nullable|string|max:100',
            'billingInformation.zip' => 'nullable|string|max:20',
            'billingInformation.country' => 'nullable|string|max:100',
            'billingInformation.email' => 'nullable|email',
            'billingInformation.paymentMethod' => 'nullable|string',
            'billingInformation.shippingMethod' => 'nullable|string',

            'paymentMethod' => 'nullable|array',
            'paymentMethod.method' => 'nullable|string',
            'paymentMethod.cardType' => 'nullable|string',
            'paymentMethod.cardholderName' => 'nullable|string',
            'paymentMethod.creditCardNo' => 'nullable|string',
            'paymentMethod.ccv2Value' => 'nullable|string',
            'paymentMethod.expirationMonth' => 'nullable|string',
            'paymentMethod.expirationYear' => 'nullable|string',
            'paymentMethod.emailInvoice' => 'nullable|boolean',

            'products' => 'required|array|min:1',
            'products.*.productId' => 'required|exists:products,id',
            'products.*.quantity' => 'required|integer|min:1',

            'shippingDestinations' => 'nullable|array',
            'shippingDestinations.*.address' => 'required|array',
            'shippingDestinations.*.products' => 'required|array',

            'shippingMethod' => 'nullable|array',
            'shippingMethod.provider' => 'nullable|string',
            'shippingMethod.cost' => 'nullable|string',
            'shippingMethod.method' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            $user = auth('sanctum')->user();

            $customer = Customer::create([
                'store_id' => $storeId,
                'first_name' => $validated['firstName'],
                'last_name' => $validated['lastName'],
                'email' => $validated['email'],
                'password' => bcrypt($validated['password']),
                'customer_group' => $request->customerGroup ?? null,
                'receive_review_emails' => $request->receiveOffers ?? true,
            ]);

            $shippingDestinations = $validated['shippingDestinations'] ?? [];

            if (empty($shippingDestinations)) {
                $shippingDestinations = [
                    [
                        'address' => $validated['billingInformation'],
                        'products' => $validated['products'],
                        'staffNotes' => ''
                    ]
                ];
            }

            $orders = [];

            foreach ($shippingDestinations as $destination) {

                $address = $destination['address'];
                $products = $destination['products'];

                $billing = $this->saveBillingInformation([
                    'store_id' => $storeId,
                    'customer_id' => $customer->id,
                    'user_id' => $user?->id,
                    'first_name' => $address['firstName'] ?? null,
                    'last_name' => $address['lastName'] ?? null,
                    'company_name' => $address['companyName'] ?? null,
                    'email' => $address['email'] ?? $customer->email ?? null,
                    'phone' => $address['phone'] ?? null,
                    'address_line_1' => $address['addressLine1'] ?? null,
                    'address_line_2' => $address['addressLine2'] ?? null,
                    'city' => $address['city'] ?? null,
                    'state' => $address['state'] ?? null,
                    'zip' => $address['zip'] ?? null,
                    'country' => $address['country'] ?? null,
                    'payment_method' => $validated['paymentMethod']['method'] ?? $address['paymentMethod'] ?? null,
                    'shipping_method' => $address['shippingMethod'] ?? null,
                ], $user, $customer);

                $order = Order::create([
                    'store_id' => $storeId,
                    'customer_id' => $customer->id,
                    'user_id' => $user->id,
                    'billing_information_id' => $billing->id,
                    'order_number' => 'ORD-' . strtoupper(Str::random(8)),
                    'status' => 'Awaiting Payment',
                    'is_draft' => $validated['isDraft'] ?? false,
                    'device_type' => $validated['deviceType'] ?? null,
                    'channel' => 'admin-panel',
                    'shipping_cost' => (float)($validated['shippingMethod']['cost'] ?? 0),
                    'shipping_provider' => $validated['shippingMethod']['provider'] ?? null,
                    'shipping_method' => $validated['shippingMethod']['method'] ?? null,
                    'comments' => $validated['comments'] ?? null,
                    'staff_notes' => $validated['staffNotes'] ?? $destination['staffNotes'] ?? null,
                    'total_amount' => 0,
                ]);

                $total = 0;

                foreach ($products as $item) {
                    $product = Product::where('store_id', $storeId)
                        ->findOrFail($item['productId']);

                    $qty = $item['quantity'];
                    $lineTotal = $product->price * $qty;

                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                        'quantity' => $qty,
                        'price' => $product->price,
                        'total' => $lineTotal,
                        'store_id' => $storeId,
                    ]);

                    $product->increment('sold_count', $qty);
                    $total += $lineTotal;
                }

                $order->update(['total_amount' => $total]);

                $orders[] = [
                    'order' => $order,
                    'destination' => $destination
                ];
            }

            DB::commit();

            $ordersResponse = [];

            foreach ($orders as $entry) {
                $order = $entry['order'];

                $orderData = [
                    'id' => $order->id,
                    'orderNumber' => $order->order_number,
                    'status' => ucfirst($order->status),
                ];

                $ordersResponse[] = $orderData;

                // ✅ EMAIL SEND - customer ki email pe
                $customerEmail = $entry['destination']['address']['email']
                    ?? $customer->email
                    ?? null;

                if ($customerEmail) {
                    Mail::to($customerEmail)->send(new OrderPlacedMail($orderData, $customer));
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'New customer created and orders placed successfully.',
                'orders' => $ordersResponse,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Order creation failed.',
                'error' => $e->getMessage(),
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

            // ✅ If 'keywords' is filled, apply keyword-based filters ONLY
            if ($request->filled('keywords')) {
                $keywords = $request->input('keywords');

                $query->where(function ($q) use ($keywords) {
                    if (is_numeric($keywords)) {
                        $q->orWhere('id', $keywords);
                    }

                    $q->orWhere('status', 'like', "%{$keywords}%")
                        ->orWhere('total_amount', 'like', "%{$keywords}%")
                        ->orWhereHas('customer', function ($c) use ($keywords) {
                            $c->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$keywords}%"])
                                ->orWhere('first_name', 'like', "%{$keywords}%")
                                ->orWhere('last_name', 'like', "%{$keywords}%")
                                ->orWhere('email', 'like', "%{$keywords}%")
                                ->orWhere('phone', 'like', "%{$keywords}%");
                        })
                        ->orWhereHas('billingInformation', function ($b) use ($keywords) {
                            $b->where('first_name', 'like', "%{$keywords}%")
                                ->orWhere('last_name', 'like', "%{$keywords}%")
                                ->orWhere('phone', 'like', "%{$keywords}%")
                                ->orWhere('payment_method', 'like', "%{$keywords}%")
                                ->orWhere('shipping_method', 'like', "%{$keywords}%");
                        });
                });
            } else {
                $query->where(function ($q) use ($request) {
                    // ✅ Basic filters
                    if ($request->filled('status')) {
                        $q->where('status', $request->input('status'));
                    }

                    if ($request->filled('paymentMethod')) {
                        $q->whereHas('billingInformation', function ($b) use ($request) {
                            $b->where('payment_method', $request->input('paymentMethod'));
                        });
                    }

                    if ($request->filled('shippingMethod')) {
                        $q->whereHas('billingInformation', function ($b) use ($request) {
                            $b->where('shipping_method', $request->input('shippingMethod'));
                        });
                    }

                    if ($request->filled('shippingProvider') && $request->input('shippingProvider') !== 'any') {
                        $q->where('tracking_carrier_name', $request->input('shippingProvider'));
                    }

                    if ($request->filled('coupon') && $request->input('coupon') !== 'any') {
                        $q->where('coupon_code', $request->input('coupon'));
                    }

                    if ($request->filled('fulfillmentSource') && $request->input('fulfillmentSource') !== 'any') {
                        $q->where('fulfillment_source', $request->input('fulfillmentSource'));
                    }

                    // ✅ Boolean filters (only if true)
                    if ($request->boolean('guest') === true) {
                        $q->whereNull('user_id');
                    }

                    // ✅ Preorder filters: only one is applied if true
                    if ($request->boolean('preorderYes') === true && $request->boolean('preorderNo') !== true) {
                        $q->where('channel', 'preorder');
                    } elseif ($request->boolean('preorderNo') === true && $request->boolean('preorderYes') !== true) {
                        $q->where('channel', '!=', 'preorder');
                    }

                    // ✅ ID Range filters
                    if ($request->filled('orderIdFrom')) {
                        $q->where('id', '>=', $request->input('orderIdFrom'));
                    }

                    if ($request->filled('orderIdTo')) {
                        $q->where('id', '<=', $request->input('orderIdTo'));
                    }

                    // ✅ Total Range filters
                    if ($request->filled('orderTotalFrom')) {
                        $q->where('total_amount', '>=', $request->input('orderTotalFrom'));
                    }

                    if ($request->filled('orderTotalTo')) {
                        $q->where('total_amount', '<=', $request->input('orderTotalTo'));
                    }

                    // ✅ Date Range filter
                    if ($request->filled('dateRange') && $request->filled('dateType')) {
                        $column = match ($request->input('dateType')) {
                            'order-date'   => 'created_at',
                            'shipped-date' => 'shipped_at',
                            default        => 'created_at'
                        };

                        switch ($request->input('dateRange')) {
                            case 'Yesterday':
                                $q->whereDate($column, today()->subDay());
                                break;
                            case 'Today':
                                $q->whereDate($column, today());
                                break;
                            case 'Last7Days':
                                $q->whereDate($column, '>=', now()->subDays(7));
                                break;
                            case 'Last30Days':
                                $q->whereDate($column, '>=', now()->subDays(30));
                                break;
                        }
                    }

                    // ✅ Handle deletedOrder (only apply if not 'ignore')
                    if ($request->has('deletedOrder')) {
                        $deleted = $request->input('deletedOrder');
                        if ($deleted === 'only') {
                            $q->onlyTrashed(); // Show only deleted
                        } elseif ($deleted === 'with') {
                            $q->withTrashed(); // Include deleted
                        }
                    }
                });
            }

            // ✅ Sorting
            $query->orderBy(
                $request->input('sortBy', 'id'),
                $request->input('sortDirection', 'asc')
            );

            // ✅ Pagination
            $perPage = $request->input('perPage', 20);
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



    public function downloadInvoice($id)
    {
        $storeId = current_store_id();

        $order = Order::with(['items.product.images', 'billingInformation'])
            ->where('store_id', $storeId)
            ->findOrFail($id);

        $logoPath  = public_path('logo/nts-logo.png');
        $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));

        $pdf = Pdf::loadView('pdf.invoice', [
            'order'     => $order,
            'logoBase64' => $logoBase64,
        ]);

        return $pdf->download('invoice-' . $order->order_number . '.pdf');
    }

    public function sendPaymentInvoice($orderId)
    {
        $order = Order::with(['items.product.images', 'billingInformation'])->findOrFail($orderId);

        $logoPath  = public_path('logo/nts-logo.png');
        $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));

        $pdf = Pdf::loadView('pdf.invoice', [
            'order'     => $order,
            'logoBase64' => $logoBase64,
        ]);

        // Use billing email
        $email = optional($order->billingInformation)->email;
        if ($email) {
            Mail::to($email)->send(new PaymentInvoiceMail($order, $pdf, $logoBase64));
        }

        return response()->json(['message' => 'Invoice email sent successfully']);
    }

    public function resendInvoice($id)
    {
        try {
            $order = Order::with(['items.product.images', 'billingInformation'])
                ->where('store_id', current_store_id())
                ->findOrFail($id);

            $logoPath  = public_path('logo/nts-logo.png');
            $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));

            $pdf = Pdf::loadView('pdf.invoice', [
                'order'     => $order,
                'logoBase64' => $logoBase64,
            ]);

            $email = optional($order->billingInformation)->email;
            if ($email) {
                Mail::to($email)->send(new PaymentInvoiceMail($order, $pdf, $logoBase64));
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
            $order = Order::with([
                'notes.user',
                'items.product.images',
                'shipments',
                'billingInformation',
            ])
                ->where('store_id', current_store_id())
                ->findOrFail($id);

            return response()->json([
                'status' => true,
                'data' => [
                    'order'      => new OrderResource($order),
                    'notes'      => OrderNoteResource::collection($order->notes),
                    'shipments'  => ShipmentResource::collection($order->shipments),
                    'status'     => $order->status,
                    'created_at' => $order->created_at,
                    'updated_at' => $order->updated_at,
                ],
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Order not found for this store.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error fetching order timeline', [
                'order_id' => $id,
                'error'    => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'An unexpected error occurred while retrieving the order timeline.',
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
}
