<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Exception;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        try {
            $user = auth('sanctum')->user(); 
            $sessionId = $request->header('X-Session-ID') ?? session()->getId();
              $perPage = $request->query('perPage', 20);

            $orders = Order::with('items.product')
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
                'status'  => false,
                'message' => 'Cart is empty.',
            ], 400);
        }

        // Calculate total
        $cartTotal = $cartItems->sum(fn($item) => $item->product->price * $item->quantity);
        $shippingCost = $validated['shippingCost'];
        $totalAmount = $cartTotal + $shippingCost;

        // Create order
        $order = Order::create([
            'user_id'         => $user?->id,
            'session_id'      => $user ? null : $sessionId,
            'name'            => $validated['name'],
            'email'           => $validated['email'],
            'phone'           => $validated['phone'],
            'address'         => $validated['address'],
            'payment_method'  => $validated['paymentMethod'],
            'shipping_method' => $validated['shippingMethod'],
            'shipping_cost'   => $shippingCost,
            'comments'        => $validated['comments'] ?? null,
            'total_amount'    => $totalAmount,
        ]);

        // Save order items
        foreach ($cartItems as $item) {
            $order->items()->create([
                'product_id' => $item->product_id,
                'quantity'   => $item->quantity,
                'price'      => $item->product->price,
            ]);
        }

        // Clear cart
        Cart::where('user_id', $user?->id)
            ->orWhere('session_id', $sessionId)
            ->delete();

        return response()->json([
            'status'  => true,
            'message' => 'Order placed successfully.',
            'data'    => new OrderResource($order->load('items.product')),
        ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Order creation failed.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}

