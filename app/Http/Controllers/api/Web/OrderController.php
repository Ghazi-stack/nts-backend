<?php

namespace App\Http\Controllers\api\Web;

use Exception;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderNote;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Exports\OrdersExport;
use App\Models\ReturnRequest;
use App\Http\Controllers\Controller;
use Maatwebsite\Excel\Facades\Excel;
use App\Http\Resources\OrderResource;
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

            $orders = Order::with('items.product')
                ->where('store_id', current_store_id()) // ✅ Multi-store filtering
                ->when($user, fn($q) => $q->where('user_id', $user->id))
                ->when(!$user, fn($q) => $q->where('session_id', $sessionId))
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
    try {
        // 🔒 Validation
        $validated = $request->validate([
            'firstName'                => 'required|string|max:255',
            'lastName'                 => 'required|string|max:255',
            'phoneNumber'              => 'required|string|max:20',
            'email'                    => 'required|email',
            'address1'                 => 'required|string',
            'address2'                 => 'nullable|string',
            'city'                     => 'nullable|string',
            'state'                    => 'nullable|string',
            'zip'                      => 'nullable|string',
            'country'                  => 'nullable|string',
            'companyName'              => 'nullable|string',

            'paymentMethod'            => 'required|string',
            'shippingCost'             => 'required|numeric',
            'customerComments'         => 'nullable|string',
            'staffNotes'               => 'nullable|string',

            'shippingMethod.provider'  => 'required|string',

            'selectedCustomer.id'      => 'required|integer',
            'selectedCustomer.name'    => 'required|string',
            'selectedCustomer.email'   => 'required|email',
            'selectedCustomer.phone'   => 'nullable|string',
            'selectedCustomer.company' => 'nullable|string',

            'selectedProducts'             => 'required|array|min:1',
            'selectedProducts.*.id'        => 'required|integer',
            'selectedProducts.*.name'      => 'required|string',
            'selectedProducts.*.slug'      => 'nullable|string',
            'selectedProducts.*.sku'       => 'nullable|string',
            'selectedProducts.*.price'     => 'required|numeric',
            'selectedProducts.*.quantity'  => 'nullable|integer|min:1',

            'search'             => 'nullable|string',
            'destinationType'    => 'nullable|string',
            'orderType'          => 'nullable|string',
        ]);

        $storeId = current_store_id();
        $fullName = $request->firstName . ' ' . $request->lastName;

        // 💰 Calculate total
        $total = 0;
        foreach ($request->selectedProducts as $item) {
            $qty = $item['quantity'] ?? 1;
            $total += $item['price'] * $qty;
        }

        $shippingCost = $request->shippingCost ?? 0;
        $totalAmount = $total + $shippingCost;

        // 📦 Create Order (only storing necessary fields)
        $order = Order::create([
            'order_number'           => 'ORD-' . strtoupper(Str::random(8)),
            'store_id'               => $storeId,
            'customer_id'            => $request->selectedCustomer['id'],
            'session_id'             => null,
            'name'                   => $fullName,
            'email'                  => $request->email,
            'phone'                  => $request->phoneNumber,
            'address'                => $request->address1 . ' ' . ($request->address2 ?? '') . ', ' .
                                        ($request->city ?? '') . ', ' . ($request->state ?? '') . ', ' .
                                        ($request->zip ?? '') . ', ' . ($request->country ?? ''),
            'payment_method'         => $request->paymentMethod,
            'shipping_method'        => $request->shippingMethod['provider'],
            'shipping_cost'          => $shippingCost,
            'total_amount'           => $totalAmount,
            'comments'               => $request->customerComments ?? $request->staffNotes ?? null,
            'status'                 => 'Pending',
            'is_draft'               => false,
            'channel'                => 'dashboard',
            'tracking_number'        => null,
            'tracking_carrier_code'  => null,
            'tracking_carrier_name'  => null,
            'shipped_at'             => null,
        ]);

        // 🛒 Store Order Items (only using product ID, quantity, and price)
        foreach ($request->selectedProducts as $item) {
            $order->items()->create([
                'store_id'   => $storeId,
                'product_id' => $item['id'],
                'quantity'   => $item['quantity'] ?? 1,
                'price'      => $item['price'],
            ]);
        }

        // 🔁 Return same payload + order info
        $responsePayload = $request->all(); // Same structure as frontend
        $responsePayload['orderId']     = $order->id;
        $responsePayload['orderNumber'] = $order->order_number;
        $responsePayload['createdAt']   = $order->created_at?->toDateTimeString();

        return response()->json([
            'status'  => true,
            'message' => 'Order successfully created by Admin.',
            'data'    => $responsePayload
        ], 201);

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'status'  => false,
            'message' => 'Validation failed.',
            'errors'  => $e->errors(),
        ], 422);
    } catch (\Exception $e) {
        return response()->json([
            'status'  => false,
            'message' => 'Something went wrong.',
            'error'   => $e->getMessage(),
        ], 500);
    }
}

 
}
