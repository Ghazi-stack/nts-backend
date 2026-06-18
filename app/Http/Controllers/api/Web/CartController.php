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
    // Priority: Auth token FIRST → session_id second
    // Agar dono aaye → token win karta hai, session_id ignore
    // ─────────────────────────────────────────────────────────────
    private function resolveIdentity(Request $request): array
    {
        $user = auth('sanctum')->user();

        // Token hai → user_id use karo, session_id bilkul ignore
        // Token nahi → session_id use karo
        $sessionId = $user ? null : $request->header('X-Session-ID');

        return [$user, $sessionId];
    }

    // ─────────────────────────────────────────────────────────────
    // Helper: Cart query - user ya session + store ke hisaab se filter
    // ─────────────────────────────────────────────────────────────
    private function cartQuery($user, ?string $sessionId, int $storeId)
    {
        return Cart::query()
            ->where('store_id', $storeId)
            ->when($user,  fn($q) => $q->where('user_id', $user->id))
            ->when(!$user, fn($q) => $q->where('session_id', $sessionId)->whereNull('user_id'));
    }

    // ─────────────────────────────────────────────────────────────
    // GET /web/cart/list
    // ─────────────────────────────────────────────────────────────
    public function index(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.',
                ], 400);
            }

            [$user, $sessionId] = $this->resolveIdentity($request);

            if (!$user && !$sessionId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'X-Session-ID header required for guest users.',
                ], 422);
            }

            $perPage   = $request->query('perPage', 20);
            $cartItems = $this->cartQuery($user, $sessionId, $storeId)
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
    // ─────────────────────────────────────────────────────────────
    public function storeCart(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.',
                ], 400);
            }

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

            $existingCartItem = $this->cartQuery($user, $sessionId, $storeId)
                ->where('product_id', $validated['productId'])
                ->first();

            if ($existingCartItem) {
                // Same product → quantity add karo (overwrite nahi)
                $existingCartItem->increment('quantity', $validated['quantity']);
                $cartItem = $existingCartItem->fresh();
            } else {
                // Naya item
                $cartItem = Cart::create([
                    'store_id'   => $storeId,
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
    // ─────────────────────────────────────────────────────────────
    public function updateCart(Request $request, $id)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.',
                ], 400);
            }

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

            $cartItem = $this->cartQuery($user, $sessionId, $storeId)
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
    // ─────────────────────────────────────────────────────────────
    public function destroyCart(Request $request, $id)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.',
                ], 400);
            }

            [$user, $sessionId] = $this->resolveIdentity($request);

            if (!$user && !$sessionId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'X-Session-ID header required for guest users.',
                ], 422);
            }

            $cartItem = $this->cartQuery($user, $sessionId, $storeId)
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
    // Auth required - login ke baad guest cart user cart mein move
    // ─────────────────────────────────────────────────────────────
    public function transferGuestCartToUser(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.',
                ], 400);
            }

            $user      = auth('sanctum')->user();
            $sessionId = $request->header('X-Session-ID');

            if (!$sessionId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'X-Session-ID header required',
                ], 422);
            }

            // Guest cart items is store ke liye
            $guestCartItems = Cart::where('store_id', $storeId)
                ->where('session_id', $sessionId)
                ->whereNull('user_id')
                ->get();

            if ($guestCartItems->isEmpty()) {
                return response()->json([
                    'status'  => true,
                    'message' => 'No guest cart items to transfer',
                ], 200);
            }

            foreach ($guestCartItems as $guestItem) {
                $userItem = Cart::where('store_id', $storeId)
                    ->where('user_id', $user->id)
                    ->where('product_id', $guestItem->product_id)
                    ->first();

                if ($userItem) {
                    // Same product → quantity add karo
                    $userItem->increment('quantity', $guestItem->quantity);
                } else {
                    // User cart mein copy karo
                    Cart::create([
                        'store_id'   => $storeId,
                        'user_id'    => $user->id,
                        'session_id' => null,
                        'product_id' => $guestItem->product_id,
                        'quantity'   => $guestItem->quantity,
                    ]);
                }

                $guestItem->delete();
            }

            $updatedCart = Cart::with('product')
                ->where('store_id', $storeId)
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
