<?php

namespace App\Http\Controllers\api\Web;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Product;
use App\Http\Resources\CartResource;
use App\Models\CartShippingRate;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Exception;
use Illuminate\Support\Facades\Validator;

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
    // Helper: Product ke sab relations jo ProductResource use karta hai
    // ─────────────────────────────────────────────────────────────
    private function productRelations(): array
    {
        return [
            'product',
            'product.brand',
            'product.categories.parent',
            'product.images',
            'product.content',
            'product.seo',
            'product.identifiers',
            'product.reviews.user',
        ];
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
                ->with($this->productRelations())
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

    public function storeCartBySku(Request $request)
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
                'sku'      => 'required|string|max:255',
                'quantity' => 'required|integer|min:1',
            ]);

            [$user, $sessionId] = $this->resolveIdentity($request);

            if (!$user && !$sessionId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'X-Session-ID header required for guest users.',
                ], 422);
            }

            // SKU + storeId se product dhundho
            $product = Product::where('sku', $validated['sku'])
                ->where('store_id', $storeId)
                ->first();

            // Product nahi mila → message aayega, 200 status
            if (!$product) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Product not found with this SKU.',
                ], 404);
            }

            // Cart mein already hai?
            $existingCartItem = $this->cartQuery($user, $sessionId, $storeId)
                ->where('product_id', $product->id)
                ->first();

            if ($existingCartItem) {
                // Same product → quantity add karo
                $existingCartItem->increment('quantity', $validated['quantity']);
                $cartItem = $existingCartItem->fresh();
            } else {
                // Naya item
                $cartItem = Cart::create([
                    'store_id'   => $storeId,
                    'user_id'    => $user?->id,
                    'session_id' => $sessionId,
                    'product_id' => $product->id,
                    'quantity'   => $validated['quantity'],
                ]);
            }

            // Shipping rate clear karo
            CartShippingRate::where('store_id', $storeId)
                ->whereJsonContains('cart_ids', $cartItem->id)
                ->delete();

            return response()->json([
                'status'  => true,
                'message' => 'Item added to cart successfully',
                'data'    => new CartResource($cartItem->load($this->productRelations())),
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

    public function clearCart(Request $request)
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

            // Pehle cart IDs collect karo
            $cartIds = $this->cartQuery($user, $sessionId, $storeId)
                ->pluck('id')
                ->toArray();

            if (empty($cartIds)) {
                return response()->json([
                    'status'  => true,
                    'message' => 'Cart is already empty.',
                ], 200);
            }

            // Sab cart items delete karo
            $deletedCount = $this->cartQuery($user, $sessionId, $storeId)->delete();

            // Shipping rates bhi clear karo
            foreach ($cartIds as $cartId) {
                CartShippingRate::where('store_id', $storeId)
                    ->whereJsonContains('cart_ids', $cartId)
                    ->delete();
            }

            return response()->json([
                'status'  => true,
                'message' => 'Cart cleared successfully.',
                'data'    => [
                    'deleted_items' => $deletedCount,
                ],
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to clear cart.',
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

            $product = Product::find($validated['productId']);
            $quantity = (int) $validated['quantity'];
            $minQty = (int) $product->min_purchase_quantity;
            $maxQty = (int) $product->max_purchase_quantity;

            if ($minQty > 0 && $quantity < $minQty) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Minimum purchase quantity for this product is ' . $minQty . '.',
                ], 422);
            }

            if ($maxQty > 0 && $quantity > $maxQty) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Maximum purchase quantity for this product is ' . $maxQty . '.',
                ], 422);
            }

            $existingCartItem = $this->cartQuery($user, $sessionId, $storeId)
                ->where('product_id', $validated['productId'])
                ->first();

            if ($existingCartItem) {
                $existingCartItem->increment('quantity', $validated['quantity']);
                $cartItem = $existingCartItem->fresh();
            } else {
                $cartItem = Cart::create([
                    'store_id'   => $storeId,
                    'user_id'    => $user?->id,
                    'session_id' => $sessionId,
                    'product_id' => $validated['productId'],
                    'quantity'   => $validated['quantity'],
                ]);
            }

            CartShippingRate::where('store_id', $storeId)
                ->whereJsonContains('cart_ids', $cartItem->id)
                ->delete();

            return response()->json([
                'status'  => true,
                'message' => 'Item added to cart successfully',
                'data'    => new CartResource($cartItem->load($this->productRelations())),
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

            $product = Product::find($cartItem->product_id);
            $quantity = (int) $validated['quantity'];
            $minQty = (int) $product->min_purchase_quantity;
            $maxQty = (int) $product->max_purchase_quantity;

            if ($minQty > 0 && $quantity < $minQty) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Minimum purchase quantity for this product is ' . $minQty . '.',
                ], 422);
            }

            if ($maxQty > 0 && $quantity > $maxQty) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Maximum purchase quantity for this product is ' . $maxQty . '.',
                ], 422);
            }

            $cartItem->update(['quantity' => $validated['quantity']]);

            CartShippingRate::where('store_id', $storeId)
                ->whereJsonContains('cart_ids', $cartItem->id)
                ->delete();

            return response()->json([
                'status'  => true,
                'message' => 'Cart item updated successfully',
                'data'    => new CartResource($cartItem->load($this->productRelations())),
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

            // ─── Shipping Rate Cleanup ────────────────────────────────
            // Cart item delete hua → cart_shipping_rates mein check karo
            // Agar is cart ID ka record hai to delete karo
            // ─────────────────────────────────────────────────────────
            CartShippingRate::where('store_id', $storeId)
                ->whereJsonContains('cart_ids', $cartItem->id)
                ->delete();

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

            $updatedCart = Cart::with($this->productRelations())
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


    public function saveShippingRate(Request $request)
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
                'cartId'             => 'required|array|min:1',
                'cartId.*'           => 'required|integer|exists:carts,id',
                'country'            => 'required|string|max:255',
                'state'              => 'required|string|max:255',
                'city'               => 'required|string|max:255',
                'zip'                => 'required|string|max:50',
                'rate'               => 'required|array',
                'rate.service_type'  => 'required|string|max:255',
                'rate.method_type'   => 'required|string|max:255',
                'rate.total_charge'  => 'required|numeric|min:0',
            ]);

            [$user, $sessionId] = $this->resolveIdentity($request);

            if (!$user && !$sessionId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'X-Session-ID header required for guest users.',
                ], 422);
            }

            // Cart IDs validate karo - sirf us user/session ki carts
            $validCartCount = Cart::whereIn('id', $validated['cartId'])
                ->where('store_id', $storeId)
                ->when($user,  fn($q) => $q->where('user_id', $user->id))
                ->when(!$user, fn($q) => $q->where('session_id', $sessionId)->whereNull('user_id'))
                ->count();

            if ($validCartCount !== count($validated['cartId'])) {
                return response()->json([
                    'status'  => false,
                    'message' => 'One or more cart IDs are invalid.',
                ], 422);
            }

            // Save ya update karo
            $shippingRate = CartShippingRate::updateOrCreate(
                [
                    'store_id'   => $storeId,
                    'user_id'    => $user?->id,
                    'session_id' => $sessionId,
                ],
                [
                    'cart_ids'     => $validated['cartId'],
                    'country'      => $validated['country'],
                    'state'        => $validated['state'],
                    'city'         => $validated['city'],
                    'zip'          => $validated['zip'],
                    'service_type' => $validated['rate']['service_type'],
                    'method_type'  => $validated['rate']['method_type'],
                    'total_charge' => $validated['rate']['total_charge'],
                ]
            );

            return response()->json([
                'status'  => true,
                'message' => 'Shipping rate saved successfully.',
                'data'    => [
                    'id'       => $shippingRate->id,
                    'storeId'  => $shippingRate->store_id,
                    'cartIds'  => $shippingRate->cart_ids,
                    'country'  => $shippingRate->country,
                    'state'    => $shippingRate->state,
                    'city'     => $shippingRate->city,
                    'zip'      => $shippingRate->zip,
                    'rate'     => [
                        'service_type' => $shippingRate->service_type,
                        'method_type'  => $shippingRate->method_type,
                        'total_charge' => $shippingRate->total_charge,
                    ],
                ],
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to save shipping rate',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // ─── getShippingRate ─────────────────────────────────────────────────────────

    public function getShippingRate(Request $request)
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

            // Token priority: token hai → user_id se, nahi → session_id se
            $query = CartShippingRate::where('store_id', $storeId);

            if ($user) {
                // Token hai → user_id se match karo (session ignore)
                $query->where('user_id', $user->id);
            } elseif ($sessionId) {
                // Guest → session_id se match karo
                $query->where('session_id', $sessionId);
            } else {
                return response()->json([
                    'status'  => false,
                    'message' => 'Authentication or Session ID required.',
                ], 422);
            }

            $shippingRate = $query->latest()->first();

            if (!$shippingRate) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Shipping rate not found.',
                ], 404);
            }

            return response()->json([
                'status'  => true,
                'message' => 'Shipping rate fetched successfully.',
                'data'    => [
                    'id'      => $shippingRate->id,
                    'storeId' => $shippingRate->store_id,
                    'cartIds' => $shippingRate->cart_ids,
                    'country' => $shippingRate->country,
                    'state'   => $shippingRate->state,
                    'city'    => $shippingRate->city,
                    'zip'     => $shippingRate->zip,
                    'rate'    => [
                        'service_type' => $shippingRate->service_type,
                        'method_type'  => $shippingRate->method_type,
                        'total_charge' => $shippingRate->total_charge,
                    ],
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to fetch shipping rate.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }


    public function removeProduct(Request $request)
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

            $validator = Validator::make($request->all(), [
                'product_id'   => 'nullable|integer|exists:products,id',
                'product_ids'  => 'nullable|array|min:1',
                'product_ids.*' => 'integer|exists:products,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status'  => false,
                    'message' => $validator->errors()->first(),
                ], 422);
            }

            // Collect product IDs
            $productIds = [];

            if ($request->filled('product_id')) {
                $productIds[] = $request->product_id;
            }

            if ($request->filled('product_ids')) {
                $productIds = array_merge($productIds, $request->product_ids);
            }

            $productIds = array_unique($productIds);

            if (empty($productIds)) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Please provide product_id or product_ids.',
                ], 422);
            }

            $deletedCount = $this->cartQuery($user, $sessionId, $storeId)
                ->whereIn('product_id', $productIds)
                ->delete();

            if ($deletedCount == 0) {
                return response()->json([
                    'status'  => false,
                    'message' => 'No matching cart items found.',
                ], 404);
            }

            return response()->json([
                'status'        => true,
                'message'       => 'Cart items deleted successfully.',
                'deleted_count' => $deletedCount,
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong while deleting cart items.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function removeShippingRate(Request $request)
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

            $query = CartShippingRate::where('store_id', $storeId);

            if ($user) {
                $query->where('user_id', $user->id);
            } else {
                $query->where('session_id', $sessionId);
            }

            $deletedCount = $query->delete();

            if ($deletedCount == 0) {
                return response()->json([
                    'status'  => false,
                    'message' => 'No shipping rate record found.',
                ], 404);
            }

            return response()->json([
                'status'        => true,
                'message'       => 'Shipping rate record deleted successfully.',
                'deleted_count' => $deletedCount,
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong while deleting shipping rate.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
