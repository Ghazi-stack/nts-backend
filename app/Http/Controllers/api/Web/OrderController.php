<?php

namespace App\Http\Controllers\api\Web;

use Exception;
use App\Models\Cart;
use App\Models\User;
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
use App\Models\BillingInformation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Maatwebsite\Excel\Facades\Excel;
use App\Http\Resources\OrderResource;
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

            $storeId = current_store_id();

            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not found or unauthorized.',
                ], 403);
            }

            $orders = Order::with(['items.product.images', 'customer', 'billingInformation','notes'])
                ->where('store_id', $storeId)
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

            $order = Order::with(['items.product.images', 'customer', 'billingInformation'])
                ->where('id', $id)
                ->where('store_id', current_store_id());

            $isAdmin = $user && (int)($user->userRole ?? 0) === 1;

            if (!$isAdmin) {
                $order->when($user, fn ($q) => $q->where('user_id', $user->id))
                      ->when(!$user, fn ($q) => $q->where('session_id', $sessionId));
            }

            $order = $order->firstOrFail();

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
                'firstName'      => 'required|string|max:255',
                'lastName'       => 'required|string|max:255',
                'companyName'    => 'nullable|string|max:255',
                'email'          => 'required|email',
                'phone'          => 'nullable|string|max:20',
                'addressLine1'   => 'required|string|max:255',
                'addressLine2'   => 'nullable|string|max:255',
                'city'           => 'nullable|string|max:100',
                'state'          => 'nullable|string|max:100',
                'zip'            => 'nullable|string|max:20',
                'country'        => 'nullable|string|max:100',
                'paymentMethod'  => 'required|string|max:100',
                'shippingMethod' => 'required|string|max:100',
                'shippingCost'   => 'required|numeric',
                'comments'       => 'nullable|string|max:1000',
            ]);

            $user = auth('sanctum')->user();
            $sessionId = $request->header('X-Session-ID') ?? session()->getId();

            $cartItems = Cart::with('product')
                ->when($user, fn ($q) => $q->where('user_id', $user->id))
                ->when(!$user, fn ($q) => $q->where('session_id', $sessionId))
                ->get();

            if ($cartItems->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cart is empty.',
                ], 400);
            }

            $cartTotal = $cartItems->sum(fn ($item) => optional($item->product)->price * $item->quantity);
            $shippingCost = $validated['shippingCost'];
            $totalAmount  = $cartTotal + $shippingCost;

            // Save billing info
            $billing = $this->saveBillingInformation([
                'first_name'      => $validated['firstName'],
                'last_name'       => $validated['lastName'],
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
            ], $user);

            // Create order
            $order = Order::create([
                'store_id'               => current_store_id(),
                'user_id'                => $user?->id,
                'session_id'             => $user ? null : $sessionId,
                'order_number'           => 'ORD-' . strtoupper(Str::random(8)),
                'billing_information_id' => $billing->id,
                'status'                 => 'Pending',
                'shipping_cost'          => $shippingCost,
                'comments'               => $validated['comments'] ?? null,
                'total_amount'           => $totalAmount,
            ]);

            // Items
            foreach ($cartItems as $item) {
                if (!$item->product) continue;

                $order->items()->create([
                    'store_id'   => current_store_id(),
                    'product_id' => $item->product_id,
                    'quantity'   => $item->quantity,
                    'price'      => $item->product->price,
                ]);
            }

            // Clear cart
            Cart::when($user, fn ($q) => $q->where('user_id', $user->id))
                ->when(!$user, fn ($q) => $q->where('session_id', $sessionId))
                ->delete();

            return response()->json([
                'status'  => true,
                'message' => 'Order placed successfully.',
                'data'    => new OrderResource($order->load('items.product.images', 'billingInformation','customer')),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Order creation failed.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $order = Order::with('billingInformation')
                ->where('id', $id)
                ->where('store_id', current_store_id())
                ->firstOrFail();

            // Updatable fields on orders table only
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

            // Updatable fields that are now in billing_information
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
                'data'    => new OrderResource($order->fresh()->load('items.product.images', 'billingInformation','customer')),
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
        return Excel::download(new OrdersExport, 'orders.xlsx');
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
            'orderId'     => 'required|exists:orders,id',
            'orderItemId' => 'nullable|exists:order_items,id',
            'reason'      => 'nullable|string|max:500',
        ]);

        $order = Order::where('id', $request->orderId)
            ->where('user_id', $user->id)
            ->where('store_id', current_store_id())
            ->firstOrFail();

        $returnRequest = ReturnRequest::create([
            'store_id'      => current_store_id(),
            'order_id'      => $request->orderId,
            'order_item_id' => $request->orderItemId,
            'user_id'       => $user->id,
            'reason'        => $request->reason,
            'status'        => 'Pending',
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Return request submitted successfully.',
            'data'    => new ReturnRequestResource($returnRequest->load('orderItem.product'))
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

            $productNames = $order->items->map(fn ($item) => optional($item->product)->name)->filter()->values();

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

    public function storeFromDashboard(Request $request)
    {
        $storeId = current_store_id();

        $validated = $request->validate([
            // Existing customer
            'customerId' => 'nullable|exists:customers,id',

            // New customer (if no customerId)
            'email'         => 'required_without:customerId|email|unique:customers,email',
            'password'      => 'nullable|string|min:6|confirmed',
            'firstName'     => 'required_without:customerId|string|max:255',
            'lastName'      => 'required_without:customerId|string|max:255',
            'phone'         => 'nullable|string|max:20',
            'companyName'   => 'nullable|string|max:255',
            'customerGroup' => 'nullable|string|max:255',
            'receiveOffers' => 'nullable|boolean',

            // Nested Billing Information
            'billingInformation' => 'array',
            'billingInformation.firstName'      => 'nullable|string|max:255',
            'billingInformation.lastName'       => 'nullable|string|max:255',
            // 'billingInformation.email'          => 'required|email',
            'billingInformation.phone'          => 'nullable|string|max:20',
            'billingInformation.companyName'    => 'nullable|string|max:255',
            'billingInformation.addressLine1'   => 'nullable|string|max:255',
            'billingInformation.addressLine2'   => 'nullable|string|max:255',
            'billingInformation.city'           => 'nullable|string|max:100',
            'billingInformation.state'          => 'nullable|string|max:100',
            'billingInformation.zip'            => 'nullable|string|max:20',
            'billingInformation.country'        => 'nullable|string|max:100',
            'billingInformation.paymentMethod'  => 'nullable|string|max:100',
            'billingInformation.shippingMethod' => 'nullable|string|max:100',

            // Order Info
            'comments'   => 'nullable|string|max:1000',
            'staffNotes' => 'nullable|string|max:1000',

            // Product List
            'products'               => 'required|array|min:1',
            'products.*.productId'   => 'required|exists:products,id',
            'products.*.quantity'    => 'required|integer|min:1',
        ]);

        try {
            DB::beginTransaction();
            $user = auth('sanctum')->user();

            // Step 1: Find or create customer
            if (!empty($validated['customerId'])) {
                $customer = Customer::where('store_id', $storeId)->findOrFail($validated['customerId']);
            } else {
                $customer = Customer::create([
                    'store_id'              => $storeId,
                    'email'                 => $validated['email'],
                    'first_name'            => $validated['firstName'],
                    'last_name'             => $validated['lastName'],
                    'company_name'          => $validated['companyName'] ?? null,
                    'phone'                 => $validated['phone'] ?? null,
                    'customer_group'        => $validated['customerGroup'] ?? null,
                    'receive_review_emails' => $validated['receiveOffers'] ?? false,
                    'force_password_reset'  => true,
                    'country'               => $validated['billingInformation']['country'] ?? null,
                    'state'                 => $validated['billingInformation']['state'] ?? null,
                ]);

                if (!empty($validated['password'])) {
                    User::create([
                        'name'        => $validated['firstName'] . ' ' . $validated['lastName'],
                        'email'       => $validated['email'],
                        'password'    => Hash::make($validated['password']),
                        'customer_id' => $customer->id,
                        'store_id'    => $storeId,
                    ]);
                }
            }

            // Step 2: Save billing information
            $b = $validated['billingInformation'] ?? [];
            $billing = $this->saveBillingInformation([
                'store_id'         => $storeId,
                'customer_id'      => $customer?->id,
                'user_id'          => $user?->id,
                'first_name'       => $b['firstName'] ?? $validated['firstName'],
                'last_name'        => $b['lastName'] ?? $validated['lastName'],
                'company_name'     => $b['companyName'] ?? null,
                // 'email'            => $b['email'],
                'phone'            => $b['phone'] ?? null,
                'address_line_1'   => $b['addressLine1'] ?? null,
                'address_line_2'   => $b['addressLine2'] ?? null,
                'city'             => $b['city'] ?? null,
                'state'            => $b['state'] ?? null,
                'zip'              => $b['zip'] ?? null,
                'country'          => $b['country'] ?? null,
                'payment_method'   => $b['paymentMethod'],
                'shipping_method'  => $b['shippingMethod'],
            ], $user, $customer);

            // Step 3: Create the order
            $order = Order::create([
                'store_id'               => $storeId,
                'customer_id'            => $customer->id,
                'billing_information_id' => $billing->id,
                'order_number'           => 'ORD-' . strtoupper(Str::random(8)),
                'status'                 => 'pending',
                'is_draft'               => false,
                'channel'                => 'admin-panel',
                'shipping_cost'          => 0,
                'total_amount'           => 0,
                'comments'               => $validated['comments'] ?? null,
                'staff_notes'            => $validated['staffNotes'] ?? null,
            ]);

            // Step 4: Add order items & total
            $total = 0;
            foreach ($validated['products'] as $item) {
                $product   = Product::where('store_id', $storeId)->findOrFail($item['productId']);
                $qty       = $item['quantity'];
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

            // Step 5: Finalize order total
            $order->update(['total_amount' => $total]);

            DB::commit();

            return response()->json([
                'status'   => true,
                'message'  => 'Order placed successfully.',
                'customer' => new CustomerResource($customer),
                'order'    => new OrderResource($order->load('items.product.images', 'billingInformation')),
            ]);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Order failed.',
                'error'   => 'One or more selected products could not be found.',
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Order failed.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function updateFromDashboard(Request $request, $id)
    {
        $storeId = current_store_id();

        $validated = $request->validate([
            // Existing customer
            'customerId' => 'nullable|exists:customers,id',

            // New customer (if no customerId)
            'email'         => 'required_without:customerId|email',
            'password'      => 'nullable|string|min:6|confirmed',
            'firstName'     => 'required_without:customerId|string|max:255',
            'lastName'      => 'required_without:customerId|string|max:255',
            'phone'         => 'nullable|string|max:20',
            'companyName'   => 'nullable|string|max:255',
            'customerGroup' => 'nullable|string|max:255',
            'receiveOffers' => 'nullable|boolean',

            // Billing Information
            'billingInformation' => 'array',
            'billingInformation.firstName'      => 'nullable|string|max:255',
            'billingInformation.lastName'       => 'nullable|string|max:255',
            'billingInformation.phone'          => 'nullable|string|max:20',
            'billingInformation.companyName'    => 'nullable|string|max:255',
            'billingInformation.addressLine1'   => 'nullable|string|max:255',
            'billingInformation.addressLine2'   => 'nullable|string|max:255',
            'billingInformation.city'           => 'nullable|string|max:100',
            'billingInformation.state'          => 'nullable|string|max:100',
            'billingInformation.zip'            => 'nullable|string|max:20',
            'billingInformation.country'        => 'nullable|string|max:100',
            'billingInformation.paymentMethod'  => 'nullable|string|max:100',
            'billingInformation.shippingMethod' => 'nullable|string|max:100',

            // Order Info
            'comments'   => 'nullable|string|max:1000',
            'staffNotes' => 'nullable|string|max:1000',

            // Product List
            'products'               => 'required|array|min:1',
            'products.*.productId'   => 'required|exists:products,id',
            'products.*.quantity'    => 'required|integer|min:1',
        ]);

        try {
            DB::beginTransaction();
            $user = auth('sanctum')->user();

            // Load the order to be updated
            $order = Order::where('store_id', $storeId)
                          ->with(['billingInformation'])
                          ->findOrFail($id);

            // Step 1: Find or create customer
            if (!empty($validated['customerId'])) {
                $customer = Customer::where('store_id', $storeId)->findOrFail($validated['customerId']);
            } else {
                $customer = Customer::updateOrCreate(
                    ['email' => $validated['email'], 'store_id' => $storeId],
                    [
                        'first_name'            => $validated['firstName'],
                        'last_name'             => $validated['lastName'],
                        'company_name'          => $validated['companyName'] ?? null,
                        'phone'                 => $validated['phone'] ?? null,
                        'customer_group'        => $validated['customerGroup'] ?? null,
                        'receive_review_emails' => $validated['receiveOffers'] ?? false,
                        'country'               => $validated['billingInformation']['country'] ?? null,
                        'state'                 => $validated['billingInformation']['state'] ?? null,
                    ]
                );

                if (!empty($validated['password'])) {
                    User::updateOrCreate(
                        ['email' => $validated['email']],
                        [
                            'name'        => $validated['firstName'] . ' ' . $validated['lastName'],
                            'password'    => Hash::make($validated['password']),
                            'customer_id' => $customer->id,
                            'store_id'    => $storeId,
                        ]
                    );
                }
            }

            // Step 2: Update billing info
            $b = $validated['billingInformation'] ?? [];

            $billing = $this->saveBillingInformation([
                'store_id'         => $storeId,
                'customer_id'      => $customer?->id,
                'user_id'          => $user?->id,
                'first_name'       => $b['firstName'] ?? $validated['firstName'],
                'last_name'        => $b['lastName'] ?? $validated['lastName'],
                'company_name'     => $b['companyName'] ?? null,
                'phone'            => $b['phone'] ?? null,
                'address_line_1'   => $b['addressLine1'] ?? null,
                'address_line_2'   => $b['addressLine2'] ?? null,
                'city'             => $b['city'] ?? null,
                'state'            => $b['state'] ?? null,
                'zip'              => $b['zip'] ?? null,
                'country'          => $b['country'] ?? null,
                'payment_method'   => $b['paymentMethod'],
                'shipping_method'  => $b['shippingMethod'],
            ], $user, $customer);

            // Step 3: Update order
            $order->update([
                'customer_id'            => $customer->id,
                'billing_information_id' => $billing->id,
                'comments'               => $validated['comments'] ?? null,
                'staff_notes'            => $validated['staffNotes'] ?? null,
            ]);

            // Step 4: Remove old items & add new ones
            $order->items()->delete();

            $total = 0;
            foreach ($validated['products'] as $item) {
                $product   = Product::where('store_id', $storeId)->findOrFail($item['productId']);
                $qty       = $item['quantity'];
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

            // Step 5: Update total
            $order->update(['total_amount' => $total]);

            DB::commit();

            return response()->json([
                'status'   => true,
                'message'  => 'Order updated successfully.',
                'customer' => new CustomerResource($customer),
                'order'    => new OrderResource($order->load('items.product.images', 'billingInformation')),
            ]);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Update failed.',
                'error'   => 'One or more selected records not found.',
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Update failed.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

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

            // ✅ If 'keywords' is filled, ONLY apply keyword-based filters
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
                // ✅ Only apply filters if 'keywords' is NOT provided
                $query->where(function ($q) use ($request) {
                    if ($request->filled('status')) {
                        $q->orWhere('status', $request->input('status'));
                    }

                    if ($request->filled('paymentMethod')) {
                        $q->orWhereHas('billingInformation', function ($b) use ($request) {
                            $b->where('payment_method', $request->input('paymentMethod'));
                        });
                    }

                    if ($request->filled('shippingMethod')) {
                        $q->orWhereHas('billingInformation', function ($b) use ($request) {
                            $b->where('shipping_method', $request->input('shippingMethod'));
                        });
                    }

                    if ($request->filled('shippingProvider') && $request->input('shippingProvider') !== 'any') {
                        $q->orWhere('tracking_carrier_name', $request->input('shippingProvider'));
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
                            'order-date'   => 'created_at',
                            'shipped-date' => 'shipped_at',
                            default        => 'created_at'
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
            }

            $query->orderBy(
                $request->input('sortBy', 'id'),
                $request->input('sortDirection', 'asc')
            );

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
            'logoBase64'=> $logoBase64,
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
            'logoBase64'=> $logoBase64,
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
                'logoBase64'=> $logoBase64,
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
            // 'email'           => $data['email'],
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
