<?php

namespace App\Http\Controllers\api\Web;

use Exception;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\Customer;
use App\Models\OrderItem;
use App\Models\OrderNote;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Exports\OrdersExport;
use App\Models\ReturnRequest;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Mail\PaymentInvoiceMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Mail;
use Maatwebsite\Excel\Facades\Excel;
use App\Http\Resources\OrderResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\ShipmentResource;
use App\Http\Resources\OrderNoteResource;
use App\Http\Resources\ReturnRequestResource;
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

            $orders = Order::with(['items.product', 'customer'])
                ->where('store_id', current_store_id())
                ->when($user, fn($q) => $q->where('user_id', $user->id))
                ->when(!$user, fn($q) => $q->where('session_id', $sessionId))
                ->when($keyword, function ($q) use ($keyword) {
                    $q->where(function ($query) use ($keyword) {
                        $query->where('id', 'like', "%{$keyword}%")
                              ->orWhere('order_number', 'like', "%{$keyword}%")
                              ->orWhere('status', 'like', "%{$keyword}%")
                              ->orWhere('total_amount', 'like', "%{$keyword}%")
                              ->orWhereHas('customer', function ($q2) use ($keyword) {
                                  $q2->where('first_name', 'like', "%{$keyword}%")
                                     ->orWhere('last_name', 'like', "%{$keyword}%")
                                     ->orWhere('name', 'like', "%{$keyword}%")
                                     ->orWhere('email', 'like', "%{$keyword}%")
                                     ->orWhere('phone', 'like', "%{$keyword}%");
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

            $order = Order::with('items.product')
                ->where('id', $id)
                ->where('store_id', current_store_id()) // ✅ Filter by store
                ->when($user, fn($q) => $q->where('user_id', $user->id))
                ->when(!$user, fn($q) => $q->where('session_id', $sessionId))
                ->firstOrFail();

            return response()->json([
                'status' => true,
                'message' => 'Order detail retrieved successfully',
                'data' => new OrderResource($order),
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

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name'           => 'required|string|max:255',
                'email'          => 'required|email',
                'phone'          => 'required|string|max:20',
                'address'        => 'required|string',
                'paymentMethod'  => 'required|string',
                'shippingMethod' => 'required|string',
                'shippingCost'   => 'required|numeric',
                'comments'       => 'nullable|string',
            ]);

            $user = auth('sanctum')->user();
            $sessionId = $request->header('X-Session-ID') ?? session()->getId();

            $cartItems = Cart::with('product')
                ->when($user, fn($q) => $q->where('user_id', $user->id))
                ->when(!$user, fn($q) => $q->where('session_id', $sessionId))
                ->get();

            if ($cartItems->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cart is empty.',
                ], 400);
            }

            $cartTotal = $cartItems->sum(fn($item) => optional($item->product)->price * $item->quantity);
            $shippingCost = $validated['shippingCost'];
            $totalAmount = $cartTotal + $shippingCost;

            $order = Order::create([
                'store_id'        => current_store_id(), // ✅ Multi-store
                'user_id'         => $user?->id,
                'session_id'      => $user ? null : $sessionId,
                'order_number'    => 'ORD-' . strtoupper(Str::random(8)), // ✅ Optional readable ID
                'name'            => $validated['name'],
                'email'           => $validated['email'],
                'phone'           => $validated['phone'],
                'status'          => 'Pending',
                'address'         => $validated['address'],
                'payment_method'  => $validated['paymentMethod'],
                'shipping_method' => $validated['shippingMethod'],
                'shipping_cost'   => $shippingCost,
                'comments'        => $validated['comments'] ?? null,
                'total_amount'    => $totalAmount,
            ]);
            $storeId = current_store_id(); // make sure this is called earlier
            foreach ($cartItems as $item) {
                if (!$item->product) continue;

                $order->items()->create([
                    'store_id'   => $storeId,
                    'product_id' => $item->product_id,
                    'quantity'   => $item->quantity,
                    'price'      => $item->product->price,
                ]);
            }

            // ✅ Safe cart deletion
            Cart::when($user, fn($q) => $q->where('user_id', $user->id))
                ->when(!$user, fn($q) => $q->where('session_id', $sessionId))
                ->delete();

            return response()->json([
                'status' => true,
                'message' => 'Order placed successfully.',
                'data' => new OrderResource($order->load('items.product')),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Order creation failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $order = Order::where('id', $id)
                          ->where('store_id', current_store_id())
                          ->firstOrFail();
        
            // List of updatable fields (whitelisted)
            $updatableFields = [
                'name',
                'email',
                'phone',
                'address',
                'payment_method',
                'shipping_method',
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
        
            // Loop through each field and update if present in request
            foreach ($updatableFields as $field) {
                if ($request->has($field)) {
                    $order->{$field} = $request->input($field);
                }
            }
        
            $order->save();
        
            return response()->json([
                'status' => true,
                'message' => 'Order updated successfully.',
                'data' => new OrderResource($order),
            ]);
        
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Order not found or unauthorized.',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Order update failed.',
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

            $order->status = $request->input('status');
            $order->save();

            return response()->json([
                'status' => true,
                'message' => 'Order status updated.',
                'data' => new OrderResource($order),
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => false, 'message' => 'Order not found'], 404);
        }
    }


    public function exportToExcel()
    {
        return Excel::download(new OrdersExport, 'orders.xlsx');
    }

    public function listDraftOrders(Request $request)
    {
        $orders = Order::where('store_id', current_store_id())
            ->where('is_draft', true)
            ->latest()
            ->paginate(20);

        return response()->json([
            'status' => true,
            'message' => 'Draft orders retrieved.',
            'data' => OrderResource::collection($orders),
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
            'order_number' => 'DRAFT-' . strtoupper(\Str::random(6)),
            'status'       => 'Draft',
            'is_draft'     => true,
            'channel'      => $validated['channel'],
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Draft order created.',
            'data' => new OrderResource($order),
        ]);
    }

    public function deleteDraftOrder($id)
    {
        $order = Order::where('store_id', current_store_id())
            ->where('is_draft', true)
            ->findOrFail($id);

        $order->delete();

        return response()->json([
            'status' => true,
            'message' => 'Draft order deleted.'
        ]);
    }


    // RETURN REQUEST


    public function submitReturnRequest(Request $request)
    {
        $user = auth('sanctum')->user();

        $request->validate([
            'orderId' => 'required|exists:orders,id',
            'orderItemId' => 'nullable|exists:order_items,id',
            'reason' => 'nullable|string|max:500',
        ]);

        $order = Order::where('id', $request->orderId)
            ->where('user_id', $user->id)
            ->where('store_id', current_store_id())
            ->firstOrFail();

        $returnRequest = ReturnRequest::create([
            'store_id' => current_store_id(),
            'order_id' => $request->orderId,
            'order_item_id' => $request->orderItemId,
            'user_id' => $user->id,
            'reason' => $request->reason,
            'status' => 'Pending',
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Return request submitted successfully.',
            'data' => new ReturnRequestResource($returnRequest->load('orderItem.product'))
        ]);
    }

    public function listReturnRequestsForAdmin(Request $request)
    {
        $requests = ReturnRequest::with(['order', 'user', 'orderItem.product'])
            ->where('store_id', current_store_id())
            ->latest()
            ->paginate(20);
    
        return response()->json([
            'status' => true,
            'data' => ReturnRequestResource::collection($requests)
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
                'status' => true,
                'message' => 'Order cancelled successfully.',
                'data' => new OrderResource($order),
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Order not found or cannot be cancelled.',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage(),
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

            $order = Order::with('items.product')
                ->where('order_number', $validated['orderNumber'])
                ->where('email', $validated['email'])
                ->where('store_id', current_store_id())
                ->firstOrFail();

            // Extract only product names from the order's items
            $productNames = $order->items->map(function ($item) {
                return optional($item->product)->name;
            })->filter()->values();

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
                'status' => false,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage(),
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
            // 'is_admin' => $user->userRole != 1, // If not customer, assume admin
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

    public function storeFromDashboard(Request $request)
    {
        $storeId = current_store_id();

        $validated = $request->validate([
            // Existing customer
            'customerId' => 'nullable|exists:customers,id',

            // New customer (if no customerId)
            'email'       => 'required_without:customerId|email|unique:customers,email',
            'password'    => 'nullable|string|min:6|confirmed',
            'firstName'   => 'required_without:customerId|string|max:255',
            'lastName'    => 'required_without:customerId|string|max:255',
            'phone'       => 'nullable|string|max:20',
            'companyName' => 'nullable|string|max:255',
            'customerGroup' => 'nullable|string|max:255',
            'receiveOffers' => 'nullable|boolean',

            // Billing Address
            'address'                       => 'required|array',
            'address.addressLine1'         => 'required|string|max:255',
            'address.addressLine2'         => 'nullable|string|max:255',
            'address.city'                 => 'nullable|string|max:100',
            'address.state'                => 'nullable|string|max:100',
            'address.zip'                  => 'nullable|string|max:20',
            'address.country'              => 'nullable|string|max:100',

            // Order Info
            'shippingMethod' => 'required|string|max:100',
            'paymentMethod'  => 'required|string|max:100',
            'comments'       => 'nullable|string|max:1000',
            'staffNotes'     => 'nullable|string|max:1000',

            // Product List
            'products' => 'required|array|min:1',
            'products.*.productId' => 'required|exists:products,id',
            'products.*.quantity' => 'required|integer|min:1',
        ]);

        try {
            DB::beginTransaction();

            // ✅ Step 1: Handle customer
            if (!empty($validated['customerId'])) {
                // Use existing customer
                $customer = Customer::where('store_id', $storeId)
                                    ->findOrFail($validated['customerId']);
            } else {
                // Create new customer (like store() logic)
                $rawPassword = $validated['password'] ?? null;

                $customerData = [
                    'store_id'               => $storeId,
                    'email'                  => $validated['email'],
                    'first_name'             => $validated['firstName'],
                    'last_name'              => $validated['lastName'],
                    'company_name'           => $validated['companyName'] ?? null,
                    'phone'                  => $validated['phone'] ?? null,
                    'customer_group'         => $validated['customerGroup'] ?? null,
                    'receive_review_emails'  => $validated['receiveOffers'] ?? false,
                    'force_password_reset'   => true,
                    'tax_exempt_code'        => null,
                    'country'                => $validated['address']['country'] ?? null,
                    'state'                  => $validated['address']['state'] ?? null,
                ];

                $customer = Customer::create($customerData);

                // 🔑 Create corresponding User (if email & password provided)
                if (!empty($validated['email']) && !empty($rawPassword)) {
                    $fullName = trim($validated['firstName'] . ' ' . $validated['lastName']);

                    User::create([
                        'name'        => $fullName,
                        'email'       => $validated['email'],
                        'password'    => Hash::make($rawPassword),
                        'customer_id' => $customer->id,
                        'store_id'    => $storeId,
                    ]);
                }
            }

            // ✅ Step 2: Create Order
            $order = Order::create([
                'store_id'        => $storeId,
                'customer_id'     => $customer->id,
                'order_number'    => 'ORD-' . strtoupper(Str::random(8)),
                'name'            => $customer->first_name . ' ' . $customer->last_name,
                'email'           => $customer->email,
                'phone'           => $customer->phone,
                'address'         => [
                    'addressLine1' => $validated['address']['addressLine1'],
                    'addressLine2' => $validated['address']['addressLine2'] ?? null,
                    'city'         => $validated['address']['city'] ?? null,
                    'state'        => $validated['address']['state'] ?? null,
                    'zip'          => $validated['address']['zip'] ?? null,
                    'country'      => $validated['address']['country'] ?? null,
                ],
                'status'          => 'pending',
                'is_draft'        => false,
                'channel'         => 'admin-panel',
                'payment_method'  => $validated['paymentMethod'],
                'shipping_method' => $validated['shippingMethod'],
                'shipping_cost'   => 0,
                'total_amount'    => 0, // to be calculated
                'comments'        => $validated['comments'] ?? null,
                'staff_notes'     => $validated['staffNotes'] ?? null,
            ]);

            // ✅ Step 3: Add Order Items
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

            // ✅ Step 4: Finalize order total
            $order->update(['total_amount' => $total]);

            DB::commit();

            return response()->json([
                'status'   => true,
                'message'  => 'Order placed successfully.',
                'customer' => new CustomerResource($customer),
                'order'    => new OrderResource($order),
                'products' => ProductResource::collection(
                    $order->items()->with('product')->get()
                )
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Order failed.',
                'error' => 'One or more selected products could not be found. Please check your product list.'
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Order failed.',
                'error' => 'An unexpected error occurred. Please try again or contact support.'
            ], 500);
        }
    }

    public function advancedOrderSearch(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected or token invalid.',
                ], 400);
            }

            $query = Order::with(['items.product'])->where('store_id', $storeId);

            $query->where(function ($q) use ($request) {

                if ($request->filled('keywords')) {
                    $keywords = $request->input('keywords');

                    if (is_numeric($keywords)) {
                        $q->orWhere('id', $keywords);
                    }
                      $q->orWhere('status', 'like', "%{$keywords}%")
                      ->orWhere('email', 'like', "%{$keywords}%")
                      ->orWhere('name', 'like', "%{$keywords}%")
                      ->orWhere('phone', 'like', "%{$keywords}%");
                }

                if ($request->filled('status')) {
                    $q->orWhere('status', $request->input('status'));
                }

                if ($request->filled('paymentMethod')) {
                    $q->orWhere('payment_method', $request->input('paymentMethod'));
                }

                if ($request->filled('shippingProvider') && $request->input('shippingProvider') !== 'any') {
                    $q->orWhere('tracking_carrier_name', $request->input('shippingProvider'));
                }

                if ($request->filled('shippingMethod')) {
                    $q->orWhere('shipping_method', $request->input('shippingMethod'));
                }

                if ($request->filled('coupon') && $request->input('coupon') !== 'any') {
                    $q->orWhere('coupon_code', $request->input('coupon'));
                }

                if ($request->filled('fulfillmentSource') && $request->input('fulfillmentSource') !== 'any') {
                    $q->orWhere('fulfillment_source', $request->input('fulfillmentSource'));
                }

                if ($request->filled('guest')) {
                    $q->orWhereNull('user_id');
                }

                if ($request->filled('preorderYes')) {
                    $q->orWhere('channel', 'preorder');
                }

                if ($request->filled('orderIdFrom')) {
                    $q->orWhere('id', '>=', $request->input('orderIdFrom'));
                }

                if ($request->filled('orderIdTo')) {
                    $q->orWhere('id', '<=', $request->input('orderIdTo'));
                }

                if ($request->filled('orderTotalFrom')) {
                    $q->orWhere('total_amount', '>=', $request->input('orderTotalFrom'));
                }

                if ($request->filled('orderTotalTo')) {
                    $q->orWhere('total_amount', '<=', $request->input('orderTotalTo'));
                }

                if ($request->filled('dateRange') && $request->filled('dateType')) {
                    $column = match ($request->input('dateType')) {
                        'order-date' => 'created_at',
                        'shipped-date' => 'shipped_at',
                        default => 'created_at'
                    };

                    switch ($request->input('dateRange')) {
                        case 'Yesterday':
                            $q->orWhereDate($column, today()->subDay());
                            break;
                        case 'Today':
                            $q->orWhereDate($column, today());
                            break;
                        case 'Last7Days':
                            $q->orWhereDate($column, '>=', now()->subDays(7));
                            break;
                        case 'Last30Days':
                            $q->orWhereDate($column, '>=', now()->subDays(30));
                            break;
                    }
                }
            });

            // Sorting & Pagination
            $query->orderBy(
                $request->input('sortBy', 'id'),
                $request->input('sortDirection', 'asc')
            );

            $perPage = $request->input('perPage', 20);
            $orders = $query->paginate($perPage);

            return response()->json([
                'status' => true,
                'message' => 'Filtered orders fetched.',
                'data' => OrderResource::collection($orders),
                'pagination' => [
                    'total' => $orders->total(),
                    'count' => $orders->count(),
                    'perPage' => $orders->perPage(),
                    'currentPage' => $orders->currentPage(),
                    'totalPages' => $orders->lastPage(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch orders.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
 
    public function downloadInvoice($id)
    {
       $storeId = current_store_id();

        $order = Order::with(['items.product'])
                      ->where('store_id', $storeId)
                      ->findOrFail($id);

        $logoPath = public_path('logo/nts-logo.png');
        $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));

        $pdf = Pdf::loadView('pdf.invoice', [
            'order' => $order,
            'logoBase64' => $logoBase64,
        ]);

        return $pdf->download('invoice-' . $order->order_number . '.pdf');
    }

    public function sendPaymentInvoice($orderId)
    {
        $order = Order::with('items.product')->findOrFail($orderId);
    
        $logoPath = public_path('logo/nts-logo.png');
        $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
    
        $pdf = Pdf::loadView('pdf.invoice', [
            'order' => $order,
            'logoBase64' => $logoBase64,
        ]);
    
        Mail::to($order->email)->send(new PaymentInvoiceMail($order, $pdf));
    
        return response()->json(['message' => 'Invoice email sent successfully']);
    }

    public function resendInvoice($id)
    {
        try {
            $order = Order::with('items.product')->where('store_id', current_store_id())->findOrFail($id);
        
            // Assuming $logoBase64 is available or generated somewhere above
            $pdf = Pdf::loadView('pdf.invoice', [
                'order' => $order,
                'logoBase64' => $logoBase64 ?? null, // ensure it doesn't break if undefined
            ]);
        
            // Send using the Mailable class
            Mail::to($order->email)->send(new PaymentInvoiceMail($order, $pdf));
        
            return response()->json([
                'status' => true,
                'message' => 'Invoice resent to ' . $order->email,
            ]);
        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error('Failed to resend invoice: ' . $e->getMessage(), [
                'order_id' => $id,
                'exception' => $e,
            ]);
        
            return response()->json([
                'status' => false,
                'message' => 'Failed to resend invoice. Please try again later.',
                'error' => $e->getMessage(), // optional, remove in production
            ], 500);
        }
    }

    public function getOrderTimeline($id)
    {
        try {
            $order = Order::with([
                'notes.user',
                'items.product',
                'shipments'
            ])
            ->where('store_id', current_store_id())
            ->findOrFail($id);
            
            return response()->json([
                'status' => true,
                'data' => [
                    'order' => new OrderResource($order),
                    'notes' => OrderNoteResource::collection($order->notes),
                    'shipments' => ShipmentResource::collection($order->shipments),
                    'status' => $order->status,
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
            // Optional: log unexpected errors
            Log::error('Error fetching order timeline', [
                'order_id' => $id,
                'error' => $e->getMessage(),
            ]);
        
            return response()->json([
                'status' => false,
                'message' => 'An unexpected error occurred while retrieving the order timeline.',
                'error' => $e->getMessage(), // optional in production
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
                    'status' => false,
                    'message' => 'Order already refunded or cancelled.',
                ], 400);
            }
        
            $order->status = 'Refunded';
            $order->save();
        
            return response()->json([
                'status' => true,
                'message' => 'Order refunded successfully.',
                'data' => new OrderResource($order),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Refund failed.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

                