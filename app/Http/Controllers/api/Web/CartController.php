<?php

namespace App\Http\Controllers\API\Web;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Product;
use App\Http\Resources\CartResource;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Exception;

class CartController extends Controller
{
     public function index(Request $request)
    {
        try {
            $user = auth('sanctum')->user();
            $sessionId = $request->header('X-Session-ID') ?? session()->getId();
            $perPage = $request->query('perPge', 20);

            $cartItems = Cart::with('product')
                ->when($user, fn($q) => $q->where('user_id', $user->id))
                ->when(!$user, fn($q) => $q->where('session_id', $sessionId))
                ->paginate($perPage);

            return response()->json([
                'status' => true,
                'message' => 'Cart items retrieved successfully',
                'data' => CartResource::collection($cartItems),
                'pagination' => [
                    'total' => $cartItems->total(),
                    'count' => $cartItems->count(),
                    'perPage' => $cartItems->perPage(),
                    'currentPage' => $cartItems->currentPage(),
                    'totalPages' => $cartItems->lastPage(),
            ]
            ],200);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong while retrieving the cart',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function storeCart(Request $request)
    {
        try {
            $validated = $request->validate([
                'productId' => 'required|exists:products,id',
                'quantity'  => 'required|integer|min:1',
            ]);

            // ✅ Use auth helper directly
            $user = auth('sanctum')->user();
            $sessionId = $request->header('X-Session-ID') ?? session()->getId();

            $existingCartItem = Cart::where([
            'user_id'    => $user?->id,
            'session_id' => $user ? null : $sessionId,
            'product_id' => $validated['productId']
                ])->whereNull('deleted_at')->first();

        if ($existingCartItem) {
            // ✅ Update quantity if item is active
            $existingCartItem->update([
                'quantity' => $validated['quantity']
            ]);
            $cartItem = $existingCartItem;
        } else {
            // ✅ Create new cart item, ignore soft-deleted ones
            $cartItem = Cart::create([
                'user_id'    => $user?->id,
                'session_id' => $user ? null : $sessionId,
                'product_id' => $validated['productId'],
                'quantity'   => $validated['quantity'],
            ]);
        }


            return response()->json([
                'status' => true,
                'message' => 'Item added/updated in cart successfully',
                'data' => new CartResource($cartItem->load('product'))
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to add item to cart',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroyCart(Request $request, $id)
    {
        try {
            // ✅ Use auth helper directly
            $user = auth('sanctum')->user();
            $sessionId = $request->header('X-Session-ID') ?? session()->getId();

            $cartItem = Cart::where('id', $id)
                ->when($user, fn($q) => $q->where('user_id', $user->id))
                ->when(!$user, fn($q) => $q->where('session_id', $sessionId))
                ->firstOrFail();

            $cartItem->delete();

            return response()->json([
                'status' => true,
                'message' => 'Cart item removed successfully'
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Cart item not found'
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to remove cart item',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    // After login move to user_id

    public function transferGuestCartToUser(Request $request)
    {
        $user = auth('sanctum')->user();
        $sessionId = $request->header('X-Session-ID');
    
        if (!$sessionId) return;
    
        $guestCartItems = Cart::where('session_id', $sessionId)->get();
    
        foreach ($guestCartItems as $item) {
            Cart::updateOrCreate(
                [
                    'user_id'    => $user->id,
                    'product_id' => $item->product_id,
                ],
                [
                    'quantity' => $item->quantity
                ]
            );
        
            $item->delete(); // remove from session cart
        }
    }

}
