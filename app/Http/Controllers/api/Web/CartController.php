<?php

namespace App\Http\Controllers\api\Web;

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
    // ─────────────────────────────────────────────────────────────
    // Helper: user_id ya session_id resolve karna
    // Auth token hai → user_id use karo, session_id null
    // Guest hai → session_id use karo, user_id null
    // ─────────────────────────────────────────────────────────────
    private function resolveIdentity(Request $request): array
    {
        $user      = auth('sanctum')->user();
        $sessionId = $user ? null : $request->header('X-Session-ID');

        return [$user, $sessionId];
    }

    // ─────────────────────────────────────────────────────────────
    // Helper: Cart query - user ya session ke hisaab se filter
    // ─────────────────────────────────────────────────────────────
    private function cartQuery($user, ?string $sessionId)
    {
        return Cart::query()
            ->when($user,  fn($q) => $q->where('user_id', $user->id))
            ->when(!$user, fn($q) => $q->where('session_id', $sessionId)->whereNull('user_id'));
    }

    // ─────────────────────────────────────────────────────────────
    // GET /web/cart/list
    // Guest: X-Session-ID header bhejo
    // Auth:  Bearer token bhejo
    // ─────────────────────────────────────────────────────────────
    public function index(Request $request)
    {
        try {
            [$user, $sessionId] = $this->resolveIdentity($request);

            if (!$user && !$sessionId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'X-Session-ID header required for guest users.',
                ], 422);
            }

            $perPage   = $request->query('perPage', 20);
            $cartItems = $this->cartQuery($user, $sessionId)
                ->with('product')
                ->paginate($perPage);

            return response()->json([
                'status'     => true,
                'message'    => 'Cart items retrieved successfully',
                'data'       => CartResource::collection($cartItems),
                'pagination' => [
                    'total'       => $cartItems->total(),
                    'count'       => $cartItems->count(),
                    'perPage'     => $cartItems->perPage(),
                    'currentPage' => $cartItems->currentPage(),
                    'totalPages'  => $cartItems->lastPage(),
                ],
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong while retrieving the cart',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // POST /web/cart/add-cart
    // Guest: X-Session-ID header bhejo
    // Auth:  Bearer token bhejo
    // Same product add karo → quantity increment hogi (overwrite nahi)
    // ─────────────────────────────────────────────────────────────
    public function storeCart(Request $request)
    {
        try {
            $validated = $request->validate([
                'productId' => 'required|exists:products,id',
                'quantity'  => 'required|integer|min:1',
            ]);

            [$user, $sessionId] = $this->resolveIdentity($request);

            if (!$user && !$sessionId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'X-Session-ID header required for guest users.',
                ], 422);
            }

            $existingCartItem = $this->cartQuery($user, $sessionId)
                ->where('product_id', $validated['productId'])
                ->first();

            if ($existingCartItem) {
                // Same product → quantity add karo
                $existingCartItem->increment('quantity', $validated['quantity']);
                $cartItem = $existingCartItem->fresh();
            } else {
                // Naya item
                $cartItem = Cart::create([
                    'user_id'    => $user?->id,
                    'session_id' => $sessionId,
                    'product_id' => $validated['productId'],
                    'quantity'   => $validated['quantity'],
                ]);
            }

            return response()->json([
                'status'  => true,
                'message' => 'Item added to cart successfully',
                'data'    => new CartResource($cartItem->load('product')),
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to add item to cart',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // PUT /web/cart/update/{id}
    // Quantity directly set karo (replace) - cart page par use karo
    // Guest: X-Session-ID header bhejo
    // Auth:  Bearer token bhejo
    // ─────────────────────────────────────────────────────────────
    public function updateCart(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'quantity' => 'required|integer|min:1',
            ]);

            [$user, $sessionId] = $this->resolveIdentity($request);

            if (!$user && !$sessionId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'X-Session-ID header required for guest users.',
                ], 422);
            }

            $cartItem = $this->cartQuery($user, $sessionId)
                ->where('id', $id)
                ->firstOrFail();

            $cartItem->update(['quantity' => $validated['quantity']]);

            return response()->json([
                'status'  => true,
                'message' => 'Cart item updated successfully',
                'data'    => new CartResource($cartItem->load('product')),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Cart item not found',
            ], 404);
        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to update cart item',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // DELETE /web/cart/delete/{id}
    // Guest: X-Session-ID header bhejo
    // Auth:  Bearer token bhejo
    // ─────────────────────────────────────────────────────────────
    public function destroyCart(Request $request, $id)
    {
        try {
            [$user, $sessionId] = $this->resolveIdentity($request);

            if (!$user && !$sessionId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'X-Session-ID header required for guest users.',
                ], 422);
            }

            $cartItem = $this->cartQuery($user, $sessionId)
                ->where('id', $id)
                ->firstOrFail();

            $cartItem->delete();

            return response()->json([
                'status'  => true,
                'message' => 'Cart item removed successfully',
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Cart item not found',
            ], 404);
        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to remove cart item',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // POST /web/cart/transfer
    // Auth required - login ke baad call karo
    // Guest cart → User cart mein move ho jata hai
    // Same product hai → quantity add hoti hai
    // ─────────────────────────────────────────────────────────────
    public function transferGuestCartToUser(Request $request)
    {
        try {
            $user      = auth('sanctum')->user();
            $sessionId = $request->header('X-Session-ID');

            if (!$sessionId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'X-Session-ID header required',
                ], 422);
            }

            $guestCartItems = Cart::where('session_id', $sessionId)
                ->whereNull('user_id')
                ->get();

            if ($guestCartItems->isEmpty()) {
                return response()->json([
                    'status'  => true,
                    'message' => 'No guest cart items to transfer',
                ], 200);
            }

            foreach ($guestCartItems as $guestItem) {
                $userItem = Cart::where('user_id', $user->id)
                    ->where('product_id', $guestItem->product_id)
                    ->first();

                if ($userItem) {
                    // Same product → quantity add karo
                    $userItem->increment('quantity', $guestItem->quantity);
                } else {
                    // Naya item user cart mein copy karo
                    Cart::create([
                        'user_id'    => $user->id,
                        'session_id' => null,
                        'product_id' => $guestItem->product_id,
                        'quantity'   => $guestItem->quantity,
                    ]);
                }

                // Guest item delete karo
                $guestItem->delete();
            }

            $updatedCart = Cart::with('product')
                ->where('user_id', $user->id)
                ->get();

            return response()->json([
                'status'  => true,
                'message' => 'Guest cart transferred successfully',
                'data'    => CartResource::collection($updatedCart),
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to transfer guest cart',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
