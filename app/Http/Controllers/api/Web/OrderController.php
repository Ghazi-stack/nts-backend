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
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Maatwebsite\Excel\Facades\Excel;
use App\Http\Resources\OrderResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\CustomerResource;
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
            $keyword = $request->query('keyword'); // ✅ New filter for search

            // $orders = Order::with('items.product')
            //     ->where('store_id', current_store_id()) // ✅ Multi-store filtering
            //     ->when($user, fn($q) => $q->where('user_id', $user->id))
            //     ->when(!$user, fn($q) => $q->where('session_id', $sessionId))
            //     ->latest()
            //     ->paginate($perPage);
            $orders = Order::with('items.product', 'customer') // include customer relation
            ->where('store_id', current_store_id()) // ✅ Multi-store filtering
            ->when($user, fn($q) => $q->where('user_id', $user->id))
            ->when(!$user, fn($q) => $q->where('session_id', $sessionId))
            ->when($keyword, function ($q) use ($keyword) {
                $q->where(function ($query) use ($keyword) {
                    $query->where('id', 'like', "%{$keyword}%")                          // Order ID
                          ->orWhere('order_number', 'like', "%{$keyword}%")              // Order Number
                          ->orWhere('status', 'like', "%{$keyword}%")                    // Status
                          ->orWhere('total_amount', 'like', "%{$keyword}%")              // Total
                          ->orWhereHas('customer', function ($q2) use ($keyword) {
                              $q2->where('first_name', 'like', "%{$keyword}%")
                                 ->orWhere('last_name', 'like', "%{$keyword}%")
                                 ->orWhere('name', 'like', "%{$keyword}%");              // Full name
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

        // ✅ Updated validation without customerType
        $validated = $request->validate([
            // Existing customer
            'customerId' => 'nullable|exists:customers,id',

            // New customer (if no customerId)
            'email'      => 'required_without:customerId|email|unique:customers,email',
            'password'   => 'nullable|string|min:6',
            'firstName'  => 'required_without:customerId|string|max:255',
            'lastName'   => 'required_without:customerId|string|max:255',
            'phone'      => 'nullable|string|max:20',
            'companyName' => 'nullable|string|max:255',
            'customerGroup' => 'nullable|string|max:255',
            'receiveOffers' => 'nullable|boolean',

            // Billing Address
            'address'                      => 'required|array',
            'address.addressLine1'         => 'required|string|max:255',
            'address.addressLine2'         => 'nullable|string|max:255',
            'address.city'                 => 'nullable|string|max:100',
            'address.state'                => 'nullable|string|max:100',
            'address.zip'                  => 'nullable|string|max:20',
            'address.country'              => 'nullable|string|max:100',

            // Order Info
            'shippingMethod' => 'required|string|max:100',
            'paymentMethod' => 'required|string|max:100',
            'comments' => 'nullable|string|max:1000',
            'staffNotes' => 'nullable|string|max:1000',

            // Product List
            'products' => 'required|array|min:1',
            'products.*.productId' => 'required|exists:products,id',
            'products.*.quantity' => 'required|integer|min:1',
        ]);

        try {
            DB::beginTransaction();

            // ✅ Step 1: Handle customer
            if (!empty($validated['customerId'])) {
                // Existing customer
                $customer = Customer::where('store_id', $storeId)
                                    ->findOrFail($validated['customerId']);
            } else {
                // New customer
                $customer = Customer::create([
                    'store_id'               => $storeId,
                    'email'                  => $validated['email'],
                    'password'               => isset($validated['password']) ? bcrypt($validated['password']) : null,
                    'first_name'             => $validated['firstName'],
                    'last_name'              => $validated['lastName'],
                    'company_name'           => $validated['companyName'] ?? null,
                    'phone'                  => $validated['phone'] ?? null,
                    'customer_group'         => $validated['customerGroup'] ?? null,
                    'receive_review_emails'  => $validated['receiveOffers'] ?? false,
                    'force_password_reset'   => true,
                    'tax_exempt_code'        => null,
                ]);
            }

            // ✅ Step 2: Create the order
            $order = Order::create([
                'store_id'        => $storeId,
                'customer_id'     => $customer->id,
                'order_number'    => 'ORD-' . strtoupper(Str::random(8)),
                'name'            => $customer->first_name . ' ' . $customer->last_name,
                'email'           => $customer->email,
                'phone'           => $customer->phone,

                'address' => [
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
                'total_amount'    => 0, // Will be calculated
                'comments'        => $validated['comments'] ?? null,
                'staff_notes'     => $validated['staffNotes'] ?? null,
            ]);

            // ✅ Step 3: Attach products and calculate total
            $total = 0;

            foreach ($validated['products'] as $item) {
                $product = Product::where('store_id', $storeId)
                                  ->findOrFail($item['productId']);

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

            // ✅ Step 4: Update order total
            $order->update(['total_amount' => $total]);

            DB::commit();

            // ✅ Success response
            return response()->json([
                'status' => true,
                'message' => 'Order placed successfully.',
                'data' => [
                    'customer' => new CustomerResource($customer),
                    'products' => ProductResource::collection(
                        $order->items()->with('product')->get()
                    ),
                    'order' => new OrderResource($order),
                ]
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
        
            $query = Order::with(['items.product', 'customer'])->where('store_id', $storeId);
        
            // 🔍 Keyword-based search
            if ($request->filled('keywords')) {
                $keywords = $request->keywords;
                $query->where(function ($q) use ($keywords) {
                    $q->where('order_number', 'like', "%{$keywords}%")
                        ->orWhere('status', 'like', "%{$keywords}%")
                        ->orWhere('email', 'like', "%{$keywords}%")
                        ->orWhere('name', 'like', "%{$keywords}%")
                        ->orWhere('phone', 'like', "%{$keywords}%");
                });
            }
        
            // 🔄 Filtering
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
        
            if ($request->filled('paymentMethod')) {
                $query->where('payment_method', $request->paymentMethod);
            }
        
            if ($request->filled('shippingProvider')) {
                $query->where('tracking_carrier_name', $request->shippingProvider);
            }
        
            if ($request->filled('shippingMethod')) {
                $query->where('shipping_method', $request->shippingMethod);
            }
        
            if ($request->filled('orderIdFrom')) {
                $query->where('id', '>=', $request->orderIdFrom);
            }
        
            if ($request->filled('orderIdTo')) {
                $query->where('id', '<=', $request->orderIdTo);
            }
        
            if ($request->filled('orderTotalFrom')) {
                $query->where('total_amount', '>=', $request->orderTotalFrom);
            }
        
            if ($request->filled('orderTotalTo')) {
                $query->where('total_amount', '<=', $request->orderTotalTo);
            }
        
            if ($request->filled('guest')) {
                $query->whereNull('user_id');
            }
        
            if ($request->filled('preorderYes')) {
                $query->where('channel', 'preorder');
            }
        
            if ($request->filled('deletedOrder') && $request->deletedOrder === 'only') {
                $query->onlyTrashed();
            } elseif ($request->filled('deletedOrder') && $request->deletedOrder === 'ignore') {
                // do nothing
            } else {
                $query->withTrashed();
            }
        
            if ($request->filled('fulfillmentSource')) {
                $query->where('fulfillment_source', $request->fulfillmentSource);
            }
        
            if ($request->filled('coupon')) {
                $query->where('coupon_code', $request->coupon);
            }
        
            // 📅 Date filter
            if ($request->filled('dateRange') && $request->filled('dateType')) {
                $column = match ($request->dateType) {
                    'order-date' => 'created_at',
                    'shipped-date' => 'shipped_at',
                    default => 'created_at'
                };
            
                switch ($request->dateRange) {
                    case 'Yesterday':
                        $query->whereDate($column, today()->subDay());
                        break;
                    case 'Today':
                        $query->whereDate($column, today());
                        break;
                    case 'Last7Days':
                        $query->whereDate($column, '>=', now()->subDays(7));
                        break;
                    case 'Last30Days':
                        $query->whereDate($column, '>=', now()->subDays(30));
                        break;
                }
            }
        
            // ⬆️ Sorting
            $query->orderBy($request->input('sortBy', 'id'), $request->input('sortDirection', 'asc'));
        
            // 📄 Pagination
            $orders = $query->paginate($request->input('perPage', 20));
        
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

}

                