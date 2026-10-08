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
use App\Services\CartContextService;
use Illuminate\Support\Facades\DB;
use App\Models\Order;
use App\Models\OrderCartItemProduct;

class CartController extends Controller
{
    protected CartContextService $cartContext;

    public function __construct(
        CartContextService $cartContext
    ) {
        $this->cartContext = $cartContext;
    }

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

    /**
     * Get the current draft quote token for authenticated users.
     *
     * Guests always use the normal session cart.
     */
    private function getCurrentQuoteToken(
        Request $request,
        $user,
        int $storeId
    ): ?string {
        $sessionId = $user
            ? null
            : $request->header('X-Session-ID');

        return $this->cartContext->getCurrentQuoteToken(
            $storeId,
            $user,
            $sessionId
        );
    }

    /**
     * Apply the current cart context to a Cart query.
     *
     * Draft context:
     *   quote_token = current token
     *   isdraftcart = 1
     *
     * Normal context:
     *   quote_token = NULL/empty
     *   isdraftcart = 0/NULL
     */
    private function applyCurrentCartContext(
        $query,
        ?string $quoteToken
    ) {
        if ($quoteToken) {
            return $query
                ->where('quote_token', $quoteToken)
                ->where('isdraftcart', 1);
        }

        return $query
            ->where(function ($q) {
                $q->whereNull('quote_token')
                    ->orWhere('quote_token', '');
            })
            ->where(function ($q) {
                $q->where('isdraftcart', 0)
                    ->orWhereNull('isdraftcart');
            });
    }

    /**
     * Synchronize the active draft order from its current cart.
     *
     * Cart is the source of truth while a draft is active.
     *
     * Synchronizes:
     * - order_items
     * - order_cart_item_products quantity
     * - order subtotal
     * - shipping cost
     * - discount
     * - manual discount
     * - total_amount
     *
     * If the draft cart becomes empty:
     * - soft-delete the draft order
     * - clear the current cart context
     *
     * Existing response structures are not changed.
     */
    private function syncDraftOrderFromCart(
        int $storeId,
        $user,
        ?string $quoteToken
    ): void {
        if (!$user || !$quoteToken) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Decode quote token and get order ID
        |--------------------------------------------------------------------------
        */
        try {
            $payload = app(\App\Services\DraftQuoteService::class)
                ->decode($quoteToken);
        } catch (\Throwable $e) {
            return;
        }

        $orderId = (int) ($payload['order_id'] ?? 0);

        if (!$orderId) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Find the draft order
        |--------------------------------------------------------------------------
        */
        $order = Order::query()
            ->where('id', $orderId)
            ->where('store_id', $storeId)
            ->where('user_id', $user->id)
            ->where('is_draft', 1)
            ->first();

        if (!$order) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Get current active cart items for THIS draft only
        |--------------------------------------------------------------------------
        */
        $cartItems = Cart::query()
            ->with('product')
            ->where('store_id', $storeId)
            ->where('user_id', $user->id)
            ->where('quote_token', $quoteToken)
            ->where('isdraftcart', 1)
            ->get();

        /*
        |--------------------------------------------------------------------------
        | If cart is empty, this draft is no longer active/reusable.
        |--------------------------------------------------------------------------
        */
        if ($cartItems->isEmpty()) {

            /*
            * Remove bridge records first.
            */
            OrderCartItemProduct::query()
                ->where('order_id', $order->id)
                ->delete();

            /*
            * Remove order items.
            */
            $order->items()->delete();

            /*
            * Soft delete the draft order.
            */
            $order->delete();

            /*
            * Switch current context back to normal cart.
            */
            $this->cartContext->setNormalCart(
                $storeId,
                $user,
                null
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Build current cart product map
        |--------------------------------------------------------------------------
        */
        $cartProductIds = $cartItems
            ->pluck('product_id')
            ->map(fn($id) => (int) $id)
            ->values();

        /*
        |--------------------------------------------------------------------------
        | Remove order items that no longer exist in cart
        |--------------------------------------------------------------------------
        */
        $order->items()
            ->whereNotIn('product_id', $cartProductIds)
            ->delete();

        /*
        |--------------------------------------------------------------------------
        | Remove bridge records that no longer exist in cart
        |--------------------------------------------------------------------------
        */
        OrderCartItemProduct::query()
            ->where('order_id', $order->id)
            ->whereNotIn('cart_id', $cartItems->pluck('id'))
            ->delete();

        /*
        |--------------------------------------------------------------------------
        | Calculate current cart subtotal
        |--------------------------------------------------------------------------
        */
        $cartSubtotal = 0;

        foreach ($cartItems as $cartItem) {

            $product = $cartItem->product;

            if (!$product) {
                continue;
            }

            /*
            * Draft price override has priority over product price.
            */
            $override = OrderCartItemProduct::query()
                ->where('order_id', $order->id)
                ->where('cart_id', $cartItem->id)
                ->where('product_id', $cartItem->product_id)
                ->latest('id')
                ->first();

            $price = $override
                ? (float) $override->price
                : (float) $product->price;

            $quantity = (int) $cartItem->quantity;

            /*
            |--------------------------------------------------------------------------
            | Keep OrderCartItemProduct quantity synchronized
            |--------------------------------------------------------------------------
            */
            if ($override) {
                $override->update([
                    'qty' => $quantity,
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Keep OrderItem synchronized
            |--------------------------------------------------------------------------
            */
            $orderItem = $order->items()
                ->where('product_id', $cartItem->product_id)
                ->first();

            if ($orderItem) {

                $orderItem->update([
                    'quantity' => $quantity,
                    'price'    => $price,
                ]);
            } else {

                $order->items()->create([
                    'store_id'   => $storeId,
                    'product_id' => $cartItem->product_id,
                    'quantity'   => $quantity,
                    'price'      => $price,
                ]);
            }

            $cartSubtotal += $price * $quantity;
        }

        /*
        |--------------------------------------------------------------------------
        | Shipping
        |
        | Cart changes invalidate the previous shipping rate.
        | Therefore shipping_cost should be reset until a new rate is selected.
        |--------------------------------------------------------------------------
        */
        $shippingCost = 0;

        /*
        |--------------------------------------------------------------------------
        | Preserve existing discounts
        |--------------------------------------------------------------------------
        */
        $discount = (float) ($order->discountAmount ?? 0);

        $manualDiscount = (float) ($order->manual_discount ?? 0);

        /*
        |--------------------------------------------------------------------------
        | Calculate new draft total
        |--------------------------------------------------------------------------
        */
        $totalAmount =
            $cartSubtotal
            + $shippingCost
            - $discount
            - $manualDiscount;

        if ($totalAmount < 0) {
            $totalAmount = 0;
        }

        /*
        |--------------------------------------------------------------------------
        | Update draft order
        |--------------------------------------------------------------------------
        */
        $order->update([
            'shipping_cost' => $shippingCost,
            'total_amount'  => $totalAmount,
        ]);
    }

    private function syncDraftShippingToOrder(
        int $storeId,
        $user,
        ?string $quoteToken,
        float $shippingCost
    ): void {
        if (!$user || !$quoteToken) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Decode quote token and get order ID
        |--------------------------------------------------------------------------
        */
        try {
            $payload = app(\App\Services\DraftQuoteService::class)
                ->decode($quoteToken);
        } catch (\Throwable $e) {
            return;
        }

        $orderId = (int) ($payload['order_id'] ?? 0);

        if (!$orderId) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Find the current draft order
        |--------------------------------------------------------------------------
        */
        $order = Order::query()
            ->where('id', $orderId)
            ->where('store_id', $storeId)
            ->where('user_id', $user->id)
            ->where('is_draft', 1)
            ->first();

        if (!$order) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Get current draft cart items
        |--------------------------------------------------------------------------
        */
        $cartItems = Cart::query()
            ->with('product')
            ->where('store_id', $storeId)
            ->where('user_id', $user->id)
            ->where('quote_token', $quoteToken)
            ->where('isdraftcart', 1)
            ->get();

        if ($cartItems->isEmpty()) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Calculate current cart subtotal
        |--------------------------------------------------------------------------
        */
        $cartSubtotal = 0;

        foreach ($cartItems as $cartItem) {

            $product = $cartItem->product;

            if (!$product) {
                continue;
            }

            /*
            | Draft price override has priority over product price.
            */
            $override = OrderCartItemProduct::query()
                ->where('order_id', $order->id)
                ->where('cart_id', $cartItem->id)
                ->where('product_id', $cartItem->product_id)
                ->latest('id')
                ->first();

            $price = $override
                ? (float) $override->price
                : (float) $product->price;

            $quantity = (int) $cartItem->quantity;

            $cartSubtotal += $price * $quantity;
        }

        /*
        |--------------------------------------------------------------------------
        | Preserve existing coupon discount
        |--------------------------------------------------------------------------
        */
        $discount = (float) ($order->discountAmount ?? 0);

        /*
        |--------------------------------------------------------------------------
        | Preserve existing manual discount
        |--------------------------------------------------------------------------
        */
        $manualDiscount = (float) ($order->manual_discount ?? 0);

        /*
        |--------------------------------------------------------------------------
        | Calculate new draft total
        |--------------------------------------------------------------------------
        */
        $shippingCost = max(0, $shippingCost);

        $totalAmount =
            $cartSubtotal
            + $shippingCost
            - $discount
            - $manualDiscount;

        if ($totalAmount < 0) {
            $totalAmount = 0;
        }

        /*
        |--------------------------------------------------------------------------
        | Update draft order
        |--------------------------------------------------------------------------
        */
        $order->update([
            'shipping_cost' => $shippingCost,
            'total_amount'  => $totalAmount,
        ]);
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
    // public function index(Request $request)
    // {
    //     try {
    //         $storeId = current_store_id();
    //         if (!$storeId) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Store not selected or token invalid.',
    //             ], 400);
    //         }

    //         [$user, $sessionId] = $this->resolveIdentity($request);

    //         if (!$user && !$sessionId) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'X-Session-ID header required for guest users.',
    //             ], 422);
    //         }

    //         $quoteToken = $request->input('quoteToken')
    //             ?? $request->query('quoteToken')
    //             ?? $request->header('X-Quote-Token');

    //         $query = $this->cartQuery($user, $sessionId, $storeId)
    //             ->with($this->productRelations());

    //         if ($quoteToken) {
    //             $query->where(function ($q) use ($quoteToken) {
    //                 $q->where('quote_token', $quoteToken)
    //                     ->orWhereNull('quote_token')
    //                     ->orWhere('quote_token', '');
    //             });
    //         } else {
    //             $query->where(function ($q) {
    //                 $q->whereNull('quote_token')->orWhere('quote_token', '');
    //             });
    //         }

    //         $perPage   = $request->query('perPage', 20);
    //         $cartItems = $query->paginate($perPage);

    //         $cartItems->getCollection()->transform(function ($item) {
    //             if ((int) ($item->isdraftcart ?? 0) !== 1) {
    //                 return $item;
    //             }

    //             $override = \App\Models\OrderCartItemProduct::query()
    //                 ->where('cart_id', $item->id)
    //                 ->where('product_id', $item->product_id)
    //                 ->latest('id')
    //                 ->first();

    //             if (!$override) {
    //                 return $item;
    //             }

    //             $item->quantity = $override->qty;

    //             if ($item->relationLoaded('product') && $item->product) {
    //                 $cloned = clone $item->product;
    //                 $cloned->setAttribute('price', $override->price);
    //                 $item->setRelation('product', $cloned);
    //             }

    //             return $item;
    //         });

    //         return response()->json([
    //             'status'     => true,
    //             'message'    => 'Cart items retrieved successfully',
    //             'data'       => CartResource::collection($cartItems),
    //             'pagination' => [
    //                 'total'       => $cartItems->total(),
    //                 'count'       => $cartItems->count(),
    //                 'perPage'     => $cartItems->perPage(),
    //                 'currentPage' => $cartItems->currentPage(),
    //                 'totalPages'  => $cartItems->lastPage(),
    //             ],
    //         ], 200);
    //     } catch (Exception $e) {
    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Something went wrong while retrieving the cart',
    //             'error'   => $e->getMessage(),
    //         ], 500);
    //     }
    // }

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

            /*
            * IMPORTANT:
            * For authenticated users, the backend determines the current
            * cart from user_cart_contexts.
            *
            * We intentionally do not depend on cartId or quoteToken from
            * the frontend here.
            */
            $quoteToken = $this->getCurrentQuoteToken(
                $request,
                $user,
                $storeId
            );

            $query = $this->cartQuery($user, $sessionId, $storeId)
                ->with($this->productRelations());

            /*
            * Apply current cart context.
            *
            * Draft:
            *   Only current draft products are returned.
            *
            * Normal:
            *   Only normal cart products are returned.
            */
            $query = $this->applyCurrentCartContext(
                $query,
                $quoteToken
            );

            $perPage   = $request->query('perPage', 20);
            $cartItems = $query->paginate($perPage);

            /*
            * Preserve existing draft price/quantity override behavior.
            */
            $cartItems->getCollection()->transform(function ($item) {

                if ((int) ($item->isdraftcart ?? 0) !== 1) {
                    return $item;
                }

                $override = \App\Models\OrderCartItemProduct::query()
                    ->where('cart_id', $item->id)
                    ->where('product_id', $item->product_id)
                    ->latest('id')
                    ->first();

                if (!$override) {
                    return $item;
                }

                $item->quantity = $override->qty;

                if (
                    $item->relationLoaded('product')
                    && $item->product
                ) {
                    $cloned = clone $item->product;

                    $cloned->setAttribute(
                        'price',
                        $override->price
                    );

                    $item->setRelation(
                        'product',
                        $cloned
                    );
                }

                return $item;
            });

            /*
            * KEEP EXISTING RESPONSE STRUCTURE EXACTLY THE SAME.
            */
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

    // public function storeCartBySku(Request $request)
    // {
    //     try {
    //         $storeId = current_store_id();
    //         if (!$storeId) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Store not selected or token invalid.',
    //             ], 400);
    //         }

    //         $validated = $request->validate([
    //             'sku'      => 'required|string|max:255',
    //             'quantity' => 'required|integer|min:1',
    //         ]);

    //         [$user, $sessionId] = $this->resolveIdentity($request);

    //         if (!$user && !$sessionId) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'X-Session-ID header required for guest users.',
    //             ], 422);
    //         }

    //         // SKU + storeId se product dhundho
    //         $product = Product::where('sku', $validated['sku'])
    //             ->where('store_id', $storeId)
    //             ->first();

    //         // Product nahi mila → message aayega, 200 status
    //         if (!$product) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Product not found with this SKU.',
    //             ], 404);
    //         }

    //         // Cart mein already hai?
    //         $existingCartItem = $this->cartQuery($user, $sessionId, $storeId)
    //             ->where('product_id', $product->id)
    //             ->first();

    //         if ($existingCartItem) {
    //             // Same product → quantity add karo
    //             $existingCartItem->increment('quantity', $validated['quantity']);
    //             $cartItem = $existingCartItem->fresh();
    //         } else {
    //             // Naya item
    //             $cartItem = Cart::create([
    //                 'store_id'   => $storeId,
    //                 'user_id'    => $user?->id,
    //                 'session_id' => $sessionId,
    //                 'product_id' => $product->id,
    //                 'quantity'   => $validated['quantity'],
    //             ]);
    //         }

    //         // Shipping rate clear karo
    //         CartShippingRate::where('store_id', $storeId)
    //             ->whereJsonContains('cart_ids', $cartItem->id)
    //             ->delete();

    //         return response()->json([
    //             'status'  => true,
    //             'message' => 'Item added to cart successfully',
    //             'data'    => new CartResource($cartItem->load($this->productRelations())),
    //         ], 201);
    //     } catch (ValidationException $e) {
    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Validation failed',
    //             'errors'  => $e->errors(),
    //         ], 422);
    //     } catch (Exception $e) {
    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Failed to add item to cart',
    //             'error'   => $e->getMessage(),
    //         ], 500);
    //     }
    // }

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

            $product = Product::query()
                ->where('store_id', $storeId)
                ->where('sku', $validated['sku'])
                ->first();

            if (!$product) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Product not found with this SKU.',
                ], 404);
            }

            $status = strtolower((string) $product->purchasability_status);
            $price  = (float) $product->price;
            $allowPurchase = filter_var($product->allowPurchase, FILTER_VALIDATE_BOOLEAN);

            if ($status !== 'available' || $price <= 0) {
                return response()->json([
                    'status'  => false,
                    'message' => 'This product is call for price.',
                ], 422);
            }

            if (!$allowPurchase) {
                return response()->json([
                    'status'  => false,
                    'message' => 'This product is not available for purchase.',
                ], 422);
            }

            $quoteToken = $this->getCurrentQuoteToken(
                $request,
                $user,
                $storeId
            );

            $existingCartItem = $this->applyCurrentCartContext(
                $this->cartQuery($user, $sessionId, $storeId),
                $quoteToken
            )
                ->where('product_id', $product->id)
                ->first();

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

            if ($minQty == 0 && $maxQty == 0) {

                $currentQuantity = $existingCartItem
                    ? (int) $existingCartItem->quantity
                    : 0;

                $totalQuantity = $currentQuantity + $quantity;
                $currentStock  = (int) $product->current_stock;

                if ($totalQuantity > $currentStock) {
                    return response()->json([
                        'status'  => false,
                        'message' => "Only {$currentStock} item(s) available in stock.",
                    ], 422);
                }
            }

            if ($existingCartItem) {

                $existingCartItem->increment(
                    'quantity',
                    $quantity
                );

                $cartItem = $existingCartItem->fresh();
            } else {

                $cartItem = Cart::create([
                    'store_id'    => $storeId,
                    'user_id'     => $user?->id,
                    'session_id'  => $sessionId,
                    'product_id'  => $product->id,
                    'quantity'    => $quantity,
                    'quote_token' => $quoteToken,
                    'isdraftcart' => $quoteToken ? 1 : 0,
                ]);
            }

            if ($quoteToken) {

                CartShippingRate::query()
                    ->where('store_id', $storeId)
                    ->where('quote_token', $quoteToken)
                    ->delete();

                if ($user) {
                    $this->syncDraftOrderFromCart(
                        $storeId,
                        $user,
                        $quoteToken
                    );
                }
            } else {

                CartShippingRate::query()
                    ->where('store_id', $storeId)
                    ->where(function ($query) {
                        $query
                            ->whereNull('quote_token')
                            ->orWhere('quote_token', '');
                    })
                    ->whereJsonContains('cart_ids', $cartItem->id)
                    ->delete();
            }

            return response()->json([
                'status'  => true,
                'message' => 'Item added to cart successfully',
                'data'    => new CartResource(
                    $cartItem->load($this->productRelations())
                ),
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

    // public function clearCart(Request $request)
    // {
    //     try {
    //         $storeId = current_store_id();
    //         if (!$storeId) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Store not selected or token invalid.',
    //             ], 400);
    //         }

    //         [$user, $sessionId] = $this->resolveIdentity($request);

    //         if (!$user && !$sessionId) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'X-Session-ID header required for guest users.',
    //             ], 422);
    //         }

    //         // Pehle cart IDs collect karo
    //         $cartIds = $this->cartQuery($user, $sessionId, $storeId)
    //             ->pluck('id')
    //             ->toArray();

    //         if (empty($cartIds)) {
    //             return response()->json([
    //                 'status'  => true,
    //                 'message' => 'Cart is already empty.',
    //             ], 200);
    //         }

    //         // Sab cart items delete karo
    //         $deletedCount = $this->cartQuery($user, $sessionId, $storeId)->delete();

    //         // Shipping rates bhi clear karo
    //         foreach ($cartIds as $cartId) {
    //             CartShippingRate::where('store_id', $storeId)
    //                 ->whereJsonContains('cart_ids', $cartId)
    //                 ->delete();
    //         }

    //         return response()->json([
    //             'status'  => true,
    //             'message' => 'Cart cleared successfully.',
    //             'data'    => [
    //                 'deleted_items' => $deletedCount,
    //             ],
    //         ], 200);
    //     } catch (Exception $e) {
    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Failed to clear cart.',
    //             'error'   => $e->getMessage(),
    //         ], 500);
    //     }
    // }

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

            /*
            |--------------------------------------------------------------------------
            | Get current quote context
            |--------------------------------------------------------------------------
            */
            $quoteToken = $this->getCurrentQuoteToken(
                $request,
                $user,
                $storeId
            );

            /*
            |--------------------------------------------------------------------------
            | Get items from CURRENT cart context only
            |--------------------------------------------------------------------------
            */
            $currentCartQuery = $this->applyCurrentCartContext(
                $this->cartQuery(
                    $user,
                    $sessionId,
                    $storeId
                ),
                $quoteToken
            );

            $cartItems = $currentCartQuery->get();

            if ($cartItems->isEmpty()) {
                return response()->json([
                    'status'  => true,
                    'message' => 'Cart is already empty.',
                ], 200);
            }

            /*
            |--------------------------------------------------------------------------
            | Save IDs before soft deleting
            |--------------------------------------------------------------------------
            */
            $cartIds = $cartItems
                ->pluck('id')
                ->values()
                ->all();

            $deletedCount = 0;

            DB::transaction(function () use (
                $storeId,
                $user,
                $sessionId,
                $quoteToken,
                $cartItems,
                $cartIds,
                &$deletedCount
            ) {

                /*
                |--------------------------------------------------------------------------
                | Soft delete current cart items
                |--------------------------------------------------------------------------
                */
                foreach ($cartItems as $cartItem) {
                    $cartItem->delete();

                    $deletedCount++;
                }

                /*
                |--------------------------------------------------------------------------
                | Remove shipping rate for CURRENT cart context only
                |--------------------------------------------------------------------------
                */
                $shippingQuery = CartShippingRate::query()
                    ->where('store_id', $storeId);

                if ($user) {

                    $shippingQuery->where(
                        'user_id',
                        $user->id
                    );
                } else {

                    $shippingQuery
                        ->whereNull('user_id')
                        ->where(
                            'session_id',
                            $sessionId
                        );
                }

                if ($quoteToken) {

                    /*
                    |--------------------------------------------------------------------------
                    | Draft cart
                    |--------------------------------------------------------------------------
                    */
                    $shippingQuery->where(
                        'quote_token',
                        $quoteToken
                    );
                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | Normal cart
                    |--------------------------------------------------------------------------
                    */
                    $shippingQuery
                        ->where(function ($query) {
                            $query
                                ->whereNull('quote_token')
                                ->orWhere('quote_token', '');
                        })
                        ->where(function ($query) use ($cartIds) {

                            foreach ($cartIds as $index => $cartId) {

                                if ($index === 0) {

                                    $query->whereJsonContains(
                                        'cart_ids',
                                        $cartId
                                    );
                                } else {

                                    $query->orWhereJsonContains(
                                        'cart_ids',
                                        $cartId
                                    );
                                }
                            }
                        });
                }

                $shippingQuery->delete();

                /*
                |--------------------------------------------------------------------------
                | Synchronize draft order
                |--------------------------------------------------------------------------
                |
                | At this point all active cart items belonging to the current
                | draft have been soft deleted.
                |
                | syncDraftOrderFromCart() will therefore detect an empty draft
                | and:
                |
                | 1. Remove OrderCartItemProduct bridge records.
                | 2. Remove draft order items.
                | 3. Soft-delete the draft order.
                | 4. Reset the current cart context to normal cart.
                |
                | Other draft carts remain untouched.
                |
                */
                if ($quoteToken && $user) {

                    $this->syncDraftOrderFromCart(
                        $storeId,
                        $user,
                        $quoteToken
                    );
                }
            });

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
    // public function storeCart(Request $request)
    // {
    //     try {
    //         $storeId = current_store_id();

    //         if (!$storeId) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Store not selected or token invalid.',
    //             ], 400);
    //         }

    //         $validated = $request->validate([
    //             'productId' => 'required|exists:products,id',
    //             'quantity'  => 'required|integer|min:1',
    //         ]);

    //         [$user, $sessionId] = $this->resolveIdentity($request);

    //         if (!$user && !$sessionId) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'X-Session-ID header required for guest users.',
    //             ], 422);
    //         }

    //         $product = Product::find($validated['productId']);

    //         $quantity     = (int) $validated['quantity'];
    //         $minQty       = (int) $product->min_purchase_quantity;
    //         $maxQty       = (int) $product->max_purchase_quantity;
    //         $currentStock = (int) $product->current_stock;

    //         if ($minQty > 0 && $quantity < $minQty) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Minimum purchase quantity for this product is ' . $minQty . '.',
    //             ], 422);
    //         }

    //         if ($maxQty > 0 && $quantity > $maxQty) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Maximum purchase quantity for this product is ' . $maxQty . '.',
    //             ], 422);
    //         }

    //         $existingNormal = $this->cartQuery($user, $sessionId, $storeId)
    //             ->where('product_id', $validated['productId'])
    //             ->where(function ($q) {
    //                 $q->where('isdraftcart', 0)->orWhereNull('isdraftcart');
    //             })
    //             ->first();

    //         $existingDraft = $this->cartQuery($user, $sessionId, $storeId)
    //             ->where('product_id', $validated['productId'])
    //             ->where('isdraftcart', 1)
    //             ->first();

    //         $existingForStock = $existingNormal ?: null;

    //         if ($minQty == 0 && $maxQty == 0) {
    //             $totalQty = $quantity + ($existingForStock?->quantity ?? 0);

    //             if ($totalQty > $currentStock) {
    //                 return response()->json([
    //                     'status'  => false,
    //                     'message' => "Only {$currentStock} item(s) available in stock.",
    //                 ], 422);
    //             }
    //         }

    //         if ($existingNormal) {
    //             $existingNormal->increment('quantity', $quantity);
    //             $cartItem = $existingNormal->fresh();
    //         } else {
    //             $cartItem = Cart::create([
    //                 'store_id'    => $storeId,
    //                 'user_id'     => $user?->id,
    //                 'session_id'  => $sessionId,
    //                 'product_id'  => $validated['productId'],
    //                 'quantity'    => $quantity,
    //                 'isdraftcart' => 0,
    //             ]);
    //         }

    //         CartShippingRate::where('store_id', $storeId)
    //             ->whereJsonContains('cart_ids', $cartItem->id)
    //             ->delete();

    //         return response()->json([
    //             'status'  => true,
    //             'message' => 'Item added to cart successfully.',
    //             'data'    => new CartResource($cartItem->load($this->productRelations())),
    //         ], 201);
    //     } catch (ValidationException $e) {
    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Validation failed.',
    //             'errors'  => $e->errors(),
    //         ], 422);
    //     } catch (Exception $e) {
    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Failed to add item to cart.',
    //             'error'   => $e->getMessage(),
    //         ], 500);
    //     }
    // }

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

            $quoteToken = $this->getCurrentQuoteToken(
                $request,
                $user,
                $storeId
            );

            $product = Product::find($validated['productId']);

            if (!$product) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Product not found.',
                ], 404);
            }

            $quantity     = (int) $validated['quantity'];
            $minQty       = (int) $product->min_purchase_quantity;
            $maxQty       = (int) $product->max_purchase_quantity;
            $currentStock = (int) $product->current_stock;

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

            /*
            |--------------------------------------------------------------------------
            | Find product ONLY in current cart context
            |--------------------------------------------------------------------------
            */
            $existingCartItem = $this->applyCurrentCartContext(
                $this->cartQuery(
                    $user,
                    $sessionId,
                    $storeId
                ),
                $quoteToken
            )
                ->where('product_id', $validated['productId'])
                ->first();

            /*
            |--------------------------------------------------------------------------
            | Stock check
            |--------------------------------------------------------------------------
            */
            if ($minQty == 0 && $maxQty == 0) {
                $totalQty = $quantity + ($existingCartItem?->quantity ?? 0);

                if ($totalQty > $currentStock) {
                    return response()->json([
                        'status'  => false,
                        'message' => "Only {$currentStock} item(s) available in stock.",
                    ], 422);
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Add / update current cart
            |--------------------------------------------------------------------------
            */
            if ($existingCartItem) {
                $existingCartItem->increment(
                    'quantity',
                    $quantity
                );

                $cartItem = $existingCartItem->fresh();
            } else {
                $cartItem = Cart::create([
                    'store_id'    => $storeId,
                    'user_id'     => $user?->id,
                    'session_id'  => $sessionId,
                    'product_id'  => $validated['productId'],
                    'quantity'    => $quantity,
                    'quote_token' => $quoteToken,
                    'isdraftcart' => $quoteToken ? 1 : 0,
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Cart changed → invalidate shipping rate for CURRENT context only
            |--------------------------------------------------------------------------
            */
            $shippingQuery = CartShippingRate::query()
                ->where('store_id', $storeId);

            if ($user) {
                $shippingQuery->where('user_id', $user->id);
            } else {
                $shippingQuery
                    ->whereNull('user_id')
                    ->where('session_id', $sessionId);
            }

            if ($quoteToken) {
                $shippingQuery->where('quote_token', $quoteToken);
            } else {
                $shippingQuery->where(function ($q) {
                    $q->whereNull('quote_token')
                        ->orWhere('quote_token', '');
                });
            }

            $shippingQuery->delete();

            return response()->json([
                'status'  => true,
                'message' => 'Item added to cart successfully.',
                'data'    => new CartResource(
                    $cartItem->load($this->productRelations())
                ),
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to add item to cart.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // PUT /web/cart/update/{id}
    // ─────────────────────────────────────────────────────────────

    // public function updateCart(Request $request, $id)
    // {
    //     try {
    //         $storeId = current_store_id();
    //         if (!$storeId) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Store not selected or token invalid.',
    //             ], 400);
    //         }

    //         $validated = $request->validate([
    //             'quantity' => 'required|integer|min:1',
    //         ]);

    //         [$user, $sessionId] = $this->resolveIdentity($request);

    //         if (!$user && !$sessionId) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'X-Session-ID header required for guest users.',
    //             ], 422);
    //         }

    //         $cartItem = $this->cartQuery($user, $sessionId, $storeId)
    //             ->where('id', $id)
    //             ->firstOrFail();

    //         $product = Product::find($cartItem->product_id);
    //         $quantity = (int) $validated['quantity'];
    //         $minQty = (int) $product->min_purchase_quantity;
    //         $maxQty = (int) $product->max_purchase_quantity;

    //         if ($minQty > 0 && $quantity < $minQty) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Minimum purchase quantity for this product is ' . $minQty . '.',
    //             ], 422);
    //         }

    //         if ($maxQty > 0 && $quantity > $maxQty) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Maximum purchase quantity for this product is ' . $maxQty . '.',
    //             ], 422);
    //         }

    //         $cartItem->update(['quantity' => $validated['quantity']]);

    //         CartShippingRate::where('store_id', $storeId)
    //             ->whereJsonContains('cart_ids', $cartItem->id)
    //             ->delete();

    //         return response()->json([
    //             'status'  => true,
    //             'message' => 'Cart item updated successfully',
    //             'data'    => new CartResource($cartItem->load($this->productRelations())),
    //         ], 200);
    //     } catch (ModelNotFoundException $e) {
    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Cart item not found',
    //         ], 404);
    //     } catch (Exception $e) {
    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Failed to update cart item',
    //             'error'   => $e->getMessage(),
    //         ], 500);
    //     }
    // }

    // public function updateCart(Request $request, $id)
    // {
    //     try {
    //         $storeId = current_store_id();

    //         if (!$storeId) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Store not selected or token invalid.',
    //             ], 400);
    //         }

    //         $validated = $request->validate([
    //             'quantity' => 'required|integer|min:1',
    //         ]);

    //         [$user, $sessionId] = $this->resolveIdentity($request);

    //         if (!$user && !$sessionId) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'X-Session-ID header required for guest users.',
    //             ], 422);
    //         }

    //         $quoteToken = $this->getCurrentQuoteToken(
    //             $request,
    //             $user,
    //             $storeId
    //         );

    //         $cartItem = $this->applyCurrentCartContext(
    //             $this->cartQuery($user, $sessionId, $storeId),
    //             $quoteToken
    //         )
    //             ->where('id', $id)
    //             ->firstOrFail();

    //         $product = Product::find($cartItem->product_id);

    //         if (!$product) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Product not found.',
    //             ], 404);
    //         }

    //         $quantity = (int) $validated['quantity'];
    //         $minQty   = (int) $product->min_purchase_quantity;
    //         $maxQty   = (int) $product->max_purchase_quantity;

    //         if ($minQty > 0 && $quantity < $minQty) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Minimum purchase quantity for this product is ' . $minQty . '.',
    //             ], 422);
    //         }

    //         if ($maxQty > 0 && $quantity > $maxQty) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Maximum purchase quantity for this product is ' . $maxQty . '.',
    //             ], 422);
    //         }

    //         $cartItem->update([
    //             'quantity' => $quantity,
    //         ]);

    //         CartShippingRate::where('store_id', $storeId)
    //             ->whereJsonContains('cart_ids', $cartItem->id)
    //             ->delete();

    //         return response()->json([
    //             'status'  => true,
    //             'message' => 'Cart item updated successfully',
    //             'data'    => new CartResource(
    //                 $cartItem->load($this->productRelations())
    //             ),
    //         ], 200);

    //     } catch (ModelNotFoundException $e) {
    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Cart item not found',
    //         ], 404);

    //     } catch (Exception $e) {
    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Failed to update cart item',
    //             'error'   => $e->getMessage(),
    //         ], 500);
    //     }
    // }

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

            $quoteToken = $this->getCurrentQuoteToken(
                $request,
                $user,
                $storeId
            );

            /*
            |--------------------------------------------------------------------------
            | Find item ONLY inside current cart context
            |--------------------------------------------------------------------------
            */
            $cartItem = $this->applyCurrentCartContext(
                $this->cartQuery(
                    $user,
                    $sessionId,
                    $storeId
                ),
                $quoteToken
            )
                ->where('id', $id)
                ->firstOrFail();

            $product = Product::where('store_id', $storeId)
                ->find($cartItem->product_id);

            if (!$product) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Product not found.',
                ], 404);
            }

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

            /*
            |--------------------------------------------------------------------------
            | Update cart + synchronize draft atomically
            |--------------------------------------------------------------------------
            */
            DB::transaction(function () use (
                $cartItem,
                $quantity,
                $storeId,
                $user,
                $quoteToken
            ) {
                $cartItem->update([
                    'quantity' => $quantity,
                ]);

                /*
                |--------------------------------------------------------------------------
                | If this is a draft, synchronize its OrderCartItemProduct quantity.
                |--------------------------------------------------------------------------
                */
                if ($quoteToken && $user) {

                    OrderCartItemProduct::query()
                        ->where('cart_id', $cartItem->id)
                        ->where('product_id', $cartItem->product_id)
                        ->update([
                            'qty' => $quantity,
                        ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Quantity changed → invalidate shipping rate
                |--------------------------------------------------------------------------
                */
                $shippingQuery = CartShippingRate::query()
                    ->where('store_id', $storeId);

                if ($user) {
                    $shippingQuery->where('user_id', $user->id);
                } else {
                    $shippingQuery
                        ->whereNull('user_id')
                        ->where('session_id', request()->header('X-Session-ID'));
                }

                if ($quoteToken) {
                    $shippingQuery->where('quote_token', $quoteToken);
                } else {
                    $shippingQuery->where(function ($q) {
                        $q->whereNull('quote_token')
                            ->orWhere('quote_token', '');
                    });
                }

                $shippingQuery->delete();

                /*
                |--------------------------------------------------------------------------
                | Synchronize draft order
                |--------------------------------------------------------------------------
                */
                if ($quoteToken && $user) {
                    $this->syncDraftOrderFromCart(
                        $storeId,
                        $user,
                        $quoteToken
                    );
                }
            });

            return response()->json([
                'status'  => true,
                'message' => 'Cart item updated successfully',
                'data'    => new CartResource(
                    $cartItem->fresh()->load($this->productRelations())
                ),
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

    // public function destroyCart(Request $request, $id)
    // {
    //     try {
    //         $storeId = current_store_id();
    //         if (!$storeId) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Store not selected or token invalid.',
    //             ], 400);
    //         }

    //         [$user, $sessionId] = $this->resolveIdentity($request);

    //         if (!$user && !$sessionId) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'X-Session-ID header required for guest users.',
    //             ], 422);
    //         }

    //         $cartItem = $this->cartQuery($user, $sessionId, $storeId)
    //             ->where('id', $id)
    //             ->firstOrFail();

    //         $cartItem->delete();

    //         // ─── Shipping Rate Cleanup ────────────────────────────────
    //         // Cart item delete hua → cart_shipping_rates mein check karo
    //         // Agar is cart ID ka record hai to delete karo
    //         // ─────────────────────────────────────────────────────────
    //         CartShippingRate::where('store_id', $storeId)
    //             ->whereJsonContains('cart_ids', $cartItem->id)
    //             ->delete();

    //         return response()->json([
    //             'status'  => true,
    //             'message' => 'Cart item removed successfully',
    //         ], 200);
    //     } catch (ModelNotFoundException $e) {
    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Cart item not found',
    //         ], 404);
    //     } catch (Exception $e) {
    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Failed to remove cart item',
    //             'error'   => $e->getMessage(),
    //         ], 500);
    //     }
    // }

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

            /*
            |--------------------------------------------------------------------------
            | Get current quote context
            |--------------------------------------------------------------------------
            */
            $quoteToken = $this->getCurrentQuoteToken(
                $request,
                $user,
                $storeId
            );

            /*
            |--------------------------------------------------------------------------
            | Find item ONLY inside current cart context
            |--------------------------------------------------------------------------
            */
            $cartItem = $this->applyCurrentCartContext(
                $this->cartQuery($user, $sessionId, $storeId),
                $quoteToken
            )
                ->where('id', $id)
                ->firstOrFail();

            DB::transaction(function () use (
                $storeId,
                $user,
                $sessionId,
                $quoteToken,
                $cartItem
            ) {

                /*
                |--------------------------------------------------------------------------
                | Soft delete cart item
                |--------------------------------------------------------------------------
                */
                $cartItem->delete();

                /*
                |--------------------------------------------------------------------------
                | Remove bridge record for this cart item
                |--------------------------------------------------------------------------
                |
                | This bridge is only relevant for draft carts.
                |
                */
                if ($quoteToken && $user) {
                    OrderCartItemProduct::query()
                        ->where('cart_id', $cartItem->id)
                        ->where('product_id', $cartItem->product_id)
                        ->delete();
                }

                /*
                |--------------------------------------------------------------------------
                | Remove shipping rate belonging to THIS cart context
                |--------------------------------------------------------------------------
                */
                $shippingQuery = CartShippingRate::query()
                    ->where('store_id', $storeId);

                if ($user) {
                    $shippingQuery->where('user_id', $user->id);
                } else {
                    $shippingQuery
                        ->whereNull('user_id')
                        ->where('session_id', $sessionId);
                }

                if ($quoteToken) {

                    $shippingQuery
                        ->where('quote_token', $quoteToken);
                } else {

                    $shippingQuery
                        ->where(function ($query) {
                            $query
                                ->whereNull('quote_token')
                                ->orWhere('quote_token', '');
                        })
                        ->whereJsonContains('cart_ids', $cartItem->id);
                }

                $shippingQuery->delete();

                /*
                |--------------------------------------------------------------------------
                | Synchronize draft order
                |--------------------------------------------------------------------------
                |
                | If this is a draft cart:
                |
                | - Recalculate order items
                | - Recalculate subtotal/total
                | - Update quantities
                | - Preserve custom draft prices
                | - If cart is now empty, soft-delete the draft order
                | - Switch current context back to normal cart
                |
                | Other drafts remain untouched.
                |
                */
                if ($quoteToken && $user) {
                    $this->syncDraftOrderFromCart(
                        $storeId,
                        $user,
                        $quoteToken
                    );
                }
            });

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
    // public function transferGuestCartToUser(Request $request)
    // {
    //     try {
    //         $storeId = current_store_id();
    //         if (!$storeId) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Store not selected or token invalid.',
    //             ], 400);
    //         }

    //         $user      = auth('sanctum')->user();
    //         $sessionId = $request->header('X-Session-ID');

    //         if (!$sessionId) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'X-Session-ID header required',
    //             ], 422);
    //         }

    //         // Guest cart items is store ke liye
    //         $guestCartItems = Cart::where('store_id', $storeId)
    //             ->where('session_id', $sessionId)
    //             ->whereNull('user_id')
    //             ->get();

    //         if ($guestCartItems->isEmpty()) {
    //             return response()->json([
    //                 'status'  => true,
    //                 'message' => 'No guest cart items to transfer',
    //             ], 200);
    //         }

    //         foreach ($guestCartItems as $guestItem) {
    //             $userItem = Cart::where('store_id', $storeId)
    //                 ->where('user_id', $user->id)
    //                 ->where('product_id', $guestItem->product_id)
    //                 ->first();

    //             if ($userItem) {
    //                 // Same product → quantity add karo
    //                 $userItem->increment('quantity', $guestItem->quantity);
    //             } else {
    //                 // User cart mein copy karo
    //                 Cart::create([
    //                     'store_id'   => $storeId,
    //                     'user_id'    => $user->id,
    //                     'session_id' => null,
    //                     'product_id' => $guestItem->product_id,
    //                     'quantity'   => $guestItem->quantity,
    //                 ]);
    //             }

    //             $guestItem->delete();
    //         }

    //         $updatedCart = Cart::with($this->productRelations())
    //             ->where('store_id', $storeId)
    //             ->where('user_id', $user->id)
    //             ->get();

    //         return response()->json([
    //             'status'  => true,
    //             'message' => 'Guest cart transferred successfully',
    //             'data'    => CartResource::collection($updatedCart),
    //         ], 200);
    //     } catch (Exception $e) {
    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Failed to transfer guest cart',
    //             'error'   => $e->getMessage(),
    //         ], 500);
    //     }
    // }

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

            $user = auth('sanctum')->user();

            if (!$user) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Authentication required.',
                ], 401);
            }

            $sessionId = $request->header('X-Session-ID');

            if (!$sessionId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'X-Session-ID header required',
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Get guest NORMAL cart only
            |--------------------------------------------------------------------------
            |
            | A guest should never have a draft cart because drafts require login.
            | Still, we explicitly restrict this to normal cart rows for safety.
            |--------------------------------------------------------------------------
            */
            $guestCartItems = Cart::query()
                ->where('store_id', $storeId)
                ->where('session_id', $sessionId)
                ->whereNull('user_id')
                ->where(function ($query) {
                    $query
                        ->whereNull('quote_token')
                        ->orWhere('quote_token', '');
                })
                ->where(function ($query) {
                    $query
                        ->where('isdraftcart', 0)
                        ->orWhereNull('isdraftcart');
                })
                ->get();

            if ($guestCartItems->isEmpty()) {
                return response()->json([
                    'status'  => true,
                    'message' => 'No guest cart items to transfer',
                ], 200);
            }

            /*
            |--------------------------------------------------------------------------
            | Important:
            |
            | We do NOT care which draft is currently selected.
            | Guest cart always transfers into the user's NORMAL cart.
            |--------------------------------------------------------------------------
            */
            DB::transaction(function () use (
                $storeId,
                $user,
                $guestCartItems
            ) {
                foreach ($guestCartItems as $guestItem) {

                    /*
                    |--------------------------------------------------------------------------
                    | Find matching product ONLY in user's NORMAL cart
                    |--------------------------------------------------------------------------
                    */
                    $userItem = Cart::query()
                        ->where('store_id', $storeId)
                        ->where('user_id', $user->id)
                        ->where('product_id', $guestItem->product_id)
                        ->where(function ($query) {
                            $query
                                ->whereNull('quote_token')
                                ->orWhere('quote_token', '');
                        })
                        ->where(function ($query) {
                            $query
                                ->where('isdraftcart', 0)
                                ->orWhereNull('isdraftcart');
                        })
                        ->first();

                    if ($userItem) {

                        /*
                        |--------------------------------------------------------------------------
                        | Same product already exists in normal cart
                        |--------------------------------------------------------------------------
                        */
                        $userItem->increment(
                            'quantity',
                            $guestItem->quantity
                        );
                    } else {

                        /*
                        |--------------------------------------------------------------------------
                        | Create new NORMAL cart item
                        |--------------------------------------------------------------------------
                        */
                        Cart::create([
                            'store_id'    => $storeId,
                            'user_id'     => $user->id,
                            'session_id'  => null,
                            'product_id'  => $guestItem->product_id,
                            'quantity'    => $guestItem->quantity,
                            'quote_token' => null,
                            'isdraftcart' => 0,
                        ]);
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Remove guest item after successful transfer
                    |--------------------------------------------------------------------------
                    */
                    $guestItem->delete();
                }
            });

            /*
            |--------------------------------------------------------------------------
            | Return user's NORMAL cart only
            |--------------------------------------------------------------------------
            */
            $updatedCart = Cart::with($this->productRelations())
                ->where('store_id', $storeId)
                ->where('user_id', $user->id)
                ->where(function ($query) {
                    $query
                        ->whereNull('quote_token')
                        ->orWhere('quote_token', '');
                })
                ->where(function ($query) {
                    $query
                        ->where('isdraftcart', 0)
                        ->orWhereNull('isdraftcart');
                })
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


    // public function saveShippingRate(Request $request)
    // {
    //     try {
    //         $storeId = current_store_id();
    //         if (!$storeId) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Store not selected or token invalid.',
    //             ], 400);
    //         }

    //         $validated = $request->validate([
    //             'cartId'             => 'required|array|min:1',
    //             'cartId.*'           => 'required|integer|exists:carts,id',
    //             'country'            => 'required|string|max:255',
    //             'state'              => 'required|string|max:255',
    //             'city'               => 'nullable|string|max:255',
    //             'zip'                => 'required|string|max:50',
    //             'rate'               => 'required|array',
    //             'rate.service_type'  => 'required|string|max:255',
    //             'rate.method_type'   => 'required|string|max:255',
    //             'rate.total_charge'  => 'required|numeric|min:0',
    //         ]);

    //         [$user, $sessionId] = $this->resolveIdentity($request);

    //         if (!$user && !$sessionId) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'X-Session-ID header required for guest users.',
    //             ], 422);
    //         }

    //         // Cart IDs validate karo - sirf us user/session ki carts
    //         $validCartCount = Cart::whereIn('id', $validated['cartId'])
    //             ->where('store_id', $storeId)
    //             ->when($user,  fn($q) => $q->where('user_id', $user->id))
    //             ->when(!$user, fn($q) => $q->where('session_id', $sessionId)->whereNull('user_id'))
    //             ->count();

    //         if ($validCartCount !== count($validated['cartId'])) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'One or more cart IDs are invalid.',
    //             ], 422);
    //         }

    //         // Save ya update karo
    //         $shippingRate = CartShippingRate::updateOrCreate(
    //             [
    //                 'store_id'   => $storeId,
    //                 'user_id'    => $user?->id,
    //                 'session_id' => $sessionId,
    //             ],
    //             [
    //                 'cart_ids'     => $validated['cartId'],
    //                 'country'      => $validated['country'],
    //                 'state'        => $validated['state'],
    //                 'city'         => $validated['city'] ?? null,
    //                 'zip'          => $validated['zip'],
    //                 'service_type' => $validated['rate']['service_type'],
    //                 'method_type'  => $validated['rate']['method_type'],
    //                 'total_charge' => $validated['rate']['total_charge'],
    //             ]
    //         );

    //         return response()->json([
    //             'status'  => true,
    //             'message' => 'Shipping rate saved successfully.',
    //             'data'    => [
    //                 'id'       => $shippingRate->id,
    //                 'storeId'  => $shippingRate->store_id,
    //                 'cartIds'  => $shippingRate->cart_ids,
    //                 'country'  => $shippingRate->country,
    //                 'state'    => $shippingRate->state,
    //                 'city'     => $shippingRate->city,
    //                 'zip'      => $shippingRate->zip,
    //                 'rate'     => [
    //                     'service_type' => $shippingRate->service_type,
    //                     'method_type'  => $shippingRate->method_type,
    //                     'total_charge' => $shippingRate->total_charge,
    //                 ],
    //             ],
    //         ], 201);
    //     } catch (ValidationException $e) {
    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Validation failed',
    //             'errors'  => $e->errors(),
    //         ], 422);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Failed to save shipping rate',
    //             'error'   => $e->getMessage(),
    //         ], 500);
    //     }
    // }

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

            [$user, $sessionId] = $this->resolveIdentity($request);

            if (!$user && !$sessionId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Authentication or Session ID required.',
                ], 422);
            }

            $validated = $request->validate([
                'cartId' => 'nullable',
                'cartIds' => 'nullable|array',

                'country' => 'nullable|string',
                'state' => 'nullable|string',
                'city' => 'nullable|string',
                'zip' => 'nullable|string',

                'service_type' => 'nullable|string',
                'method_type' => 'nullable|string',
                'total_charge' => 'nullable|numeric',

                'rate' => 'nullable|array',
                'rate.service_type' => 'nullable|string',
                'rate.method_type' => 'nullable|string',
                'rate.total_charge' => 'nullable|numeric',
            ]);

            $serviceType = $validated['service_type']
                ?? data_get($validated, 'rate.service_type');

            $methodType = $validated['method_type']
                ?? data_get($validated, 'rate.method_type');

            $totalCharge = $validated['total_charge']
                ?? data_get($validated, 'rate.total_charge');

            /*
            |--------------------------------------------------------------------------
            | Current cart context
            |--------------------------------------------------------------------------
            */

            $quoteToken = $this->getCurrentQuoteToken(
                $request,
                $user,
                $storeId
            );

            /*
            |--------------------------------------------------------------------------
            | Resolve CURRENT cart IDs from backend context
            |--------------------------------------------------------------------------
            |
            | We do not trust cart IDs coming from frontend to determine which
            | cart/draft this shipping rate belongs to.
            |
            */

            $cartQuery = $this->applyCurrentCartContext(
                $this->cartQuery(
                    $user,
                    $sessionId,
                    $storeId
                ),
                $quoteToken
            );

            $cartIds = $cartQuery
                ->pluck('id')
                ->values()
                ->all();

            /*
            |--------------------------------------------------------------------------
            | If frontend supplied cart IDs, we don't use them to establish
            | the current cart. The current backend context remains authoritative.
            |--------------------------------------------------------------------------
            */

            if (empty($cartIds)) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Cart not found.',
                ], 404);
            }

            /*
            |--------------------------------------------------------------------------
            | Find existing shipping rate for CURRENT context
            |--------------------------------------------------------------------------
            */

            $shippingRateQuery = CartShippingRate::query()
                ->where('store_id', $storeId);

            if ($user) {
                $shippingRateQuery->where('user_id', $user->id);
            } else {
                $shippingRateQuery
                    ->whereNull('user_id')
                    ->where('session_id', $sessionId);
            }

            if ($quoteToken) {
                $shippingRateQuery->where('quote_token', $quoteToken);
            } else {
                $shippingRateQuery->where(function ($q) {
                    $q->whereNull('quote_token')
                        ->orWhere('quote_token', '');
                });
            }

            $shippingRate = $shippingRateQuery
                ->latest('id')
                ->first();

            /*
            |--------------------------------------------------------------------------
            | Save / Update shipping rate
            |--------------------------------------------------------------------------
            */

            $shippingData = [
                'store_id'     => $storeId,
                'user_id'      => $user?->id,
                'session_id'   => $sessionId,
                'quote_token'  => $quoteToken,
                'cart_ids'     => $cartIds,
                'country'      => $validated['country'] ?? null,
                'state'        => $validated['state'] ?? null,
                'city'         => $validated['city'] ?? null,
                'zip'          => $validated['zip'] ?? null,
                'service_type' => $serviceType,
                'method_type'  => $methodType,
                'total_charge' => (float) ($totalCharge ?? 0),
            ];

            if ($shippingRate) {
                $shippingRate->update($shippingData);
            } else {
                $shippingRate = CartShippingRate::create($shippingData);
            }

            if (!empty($quoteToken) && $user) {
                $this->syncDraftShippingToOrder(
                    $storeId,
                    $user,
                    $quoteToken,
                    (float) ($shippingRate->total_charge ?? 0)
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Keep existing response structure
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'status'  => true,
                'message' => 'Shipping rate saved successfully.',
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

    // public function getShippingRate(Request $request)
    // {
    //     try {
    //         $storeId = current_store_id();
    //         if (!$storeId) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Store not selected or token invalid.',
    //             ], 400);
    //         }

    //         [$user, $sessionId] = $this->resolveIdentity($request);

    //         $query = CartShippingRate::where('store_id', $storeId);

    //         if ($user) {
    //             $query->where('user_id', $user->id);
    //         } elseif ($sessionId) {
    //             $query->where('session_id', $sessionId);
    //         } else {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Authentication or Session ID required.',
    //             ], 422);
    //         }

    //         $cartIds = $request->input('cartIds', $request->query('cartIds', $request->input('cartId')));

    //         if (!is_array($cartIds)) {
    //             $cartIds = $cartIds !== null && $cartIds !== ''
    //                 ? array_filter(array_map('trim', explode(',', (string) $cartIds)))
    //                 : [];
    //         }

    //         $cartIds = array_values(array_unique(array_map('intval', $cartIds)));

    //         if (!empty($cartIds)) {
    //             $query->where(function ($q) use ($cartIds) {
    //                 foreach ($cartIds as $id) {
    //                     $q->orWhereJsonContains('cart_ids', $id)
    //                         ->orWhereJsonContains('cart_ids', (string) $id);
    //                 }
    //             });
    //         }

    //         $shippingRate = $query->latest('id')->first();

    //         if (!$shippingRate) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Shipping rate not found.',
    //             ], 404);
    //         }

    //         return response()->json([
    //             'status'  => true,
    //             'message' => 'Shipping rate fetched successfully.',
    //             'data'    => [
    //                 'id'      => $shippingRate->id,
    //                 'storeId' => $shippingRate->store_id,
    //                 'cartIds' => $shippingRate->cart_ids,
    //                 'country' => $shippingRate->country,
    //                 'state'   => $shippingRate->state,
    //                 'city'    => $shippingRate->city,
    //                 'zip'     => $shippingRate->zip,
    //                 'rate'    => [
    //                     'service_type' => $shippingRate->service_type,
    //                     'method_type'  => $shippingRate->method_type,
    //                     'total_charge' => $shippingRate->total_charge,
    //                 ],
    //             ],
    //         ], 200);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Failed to fetch shipping rate.',
    //             'error'   => $e->getMessage(),
    //         ], 500);
    //     }
    // }

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

            if (!$user && !$sessionId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Authentication or Session ID required.',
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Get current draft / normal cart context
            |--------------------------------------------------------------------------
            */

            $quoteToken = $this->getCurrentQuoteToken(
                $request,
                $user,
                $storeId
            );

            /*
            |--------------------------------------------------------------------------
            | Find shipping rate belonging ONLY to current context
            |--------------------------------------------------------------------------
            */

            $query = CartShippingRate::query()
                ->where('store_id', $storeId);

            if ($user) {
                $query->where('user_id', $user->id);
            } else {
                $query
                    ->whereNull('user_id')
                    ->where('session_id', $sessionId);
            }

            if ($quoteToken) {
                $query->where('quote_token', $quoteToken);
            } else {
                $query->where(function ($q) {
                    $q->whereNull('quote_token')
                        ->orWhere('quote_token', '');
                });
            }

            $shippingRate = $query
                ->latest('id')
                ->first();

            if (!$shippingRate) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Shipping rate not found.',
                ], 404);
            }

            /*
            |--------------------------------------------------------------------------
            | Existing response structure preserved
            |--------------------------------------------------------------------------
            */

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


    // public function removeProduct(Request $request)
    // {
    //     try {
    //         $storeId = current_store_id();

    //         if (!$storeId) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Store not selected or token invalid.',
    //             ], 400);
    //         }

    //         [$user, $sessionId] = $this->resolveIdentity($request);

    //         if (!$user && !$sessionId) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'X-Session-ID header required for guest users.',
    //             ], 422);
    //         }

    //         $validator = Validator::make($request->all(), [
    //             'product_id'   => 'nullable|integer|exists:products,id',
    //             'product_ids'  => 'nullable|array|min:1',
    //             'product_ids.*' => 'integer|exists:products,id',
    //         ]);

    //         if ($validator->fails()) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => $validator->errors()->first(),
    //             ], 422);
    //         }

    //         // Collect product IDs
    //         $productIds = [];

    //         if ($request->filled('product_id')) {
    //             $productIds[] = $request->product_id;
    //         }

    //         if ($request->filled('product_ids')) {
    //             $productIds = array_merge($productIds, $request->product_ids);
    //         }

    //         $productIds = array_unique($productIds);

    //         if (empty($productIds)) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Please provide product_id or product_ids.',
    //             ], 422);
    //         }

    //         // $deletedCount = $this->cartQuery($user, $sessionId, $storeId)
    //         //     ->whereIn('product_id', $productIds)
    //         //     ->delete();

    //         $quoteToken = $this->getCurrentQuoteToken(
    //             $request,
    //             $user,
    //             $storeId
    //         );

    //         $deletedCount = $this->applyCurrentCartContext(
    //             $this->cartQuery(
    //                 $user,
    //                 $sessionId,
    //                 $storeId
    //             ),
    //             $quoteToken
    //         )
    //             ->whereIn('product_id', $productIds)
    //             ->delete();

    //         if ($deletedCount == 0) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'No matching cart items found.',
    //             ], 404);
    //         }

    //         return response()->json([
    //             'status'        => true,
    //             'message'       => 'Cart items deleted successfully.',
    //             'deleted_count' => $deletedCount,
    //         ], 200);
    //     } catch (Exception $e) {
    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Something went wrong while deleting cart items.',
    //             'error'   => $e->getMessage(),
    //         ], 500);
    //     }
    // }

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
                'product_id'    => 'nullable|integer|exists:products,id',
                'product_ids'   => 'nullable|array|min:1',
                'product_ids.*' => 'integer|exists:products,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status'  => false,
                    'message' => $validator->errors()->first(),
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Collect product IDs
            |--------------------------------------------------------------------------
            */
            $productIds = [];

            if ($request->filled('product_id')) {
                $productIds[] = (int) $request->product_id;
            }

            if ($request->filled('product_ids')) {
                $productIds = array_merge(
                    $productIds,
                    array_map('intval', $request->product_ids)
                );
            }

            $productIds = array_unique($productIds);

            if (empty($productIds)) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Please provide product_id or product_ids.',
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Resolve current cart context
            |--------------------------------------------------------------------------
            */
            $quoteToken = $this->getCurrentQuoteToken(
                $request,
                $user,
                $storeId
            );

            /*
            |--------------------------------------------------------------------------
            | Delete current-context cart items only
            |--------------------------------------------------------------------------
            |
            | For a draft:
            |   - only the currently opened draft is affected
            |
            | For a normal cart:
            |   - only the normal cart is affected
            |
            | Other drafts remain untouched.
            |
            */
            $deletedCount = DB::transaction(function () use (
                $user,
                $sessionId,
                $storeId,
                $quoteToken,
                $productIds
            ) {
                $deletedCount = $this->applyCurrentCartContext(
                    $this->cartQuery(
                        $user,
                        $sessionId,
                        $storeId
                    ),
                    $quoteToken
                )
                    ->whereIn('product_id', $productIds)
                    ->delete();

                /*
                |--------------------------------------------------------------------------
                | If the current draft has no remaining products,
                | deactivate the draft context.
                |--------------------------------------------------------------------------
                */
                if (
                    $deletedCount > 0 &&
                    $quoteToken &&
                    $user
                ) {
                    $remainingDraftItems = Cart::query()
                        ->where('store_id', $storeId)
                        ->where('user_id', $user->id)
                        ->where('quote_token', $quoteToken)
                        ->where('isdraftcart', 1)
                        ->exists();

                    if (!$remainingDraftItems) {
                        /*
                        | The draft is now empty, so switch the user
                        | back to their normal cart context.
                        */
                        // $this->cartContext->setNormalCart(
                        //     $storeId,
                        //     $user->id
                        // );

                        $sessionId = $user
                            ? null
                            : $request->header('X-Session-ID');

                        $this->cartContext->setNormalCart(
                            $storeId,
                            $user,
                            $sessionId
                        );
                    }
                }

                return $deletedCount;
            });

            /*
            |--------------------------------------------------------------------------
            | No matching products found
            |--------------------------------------------------------------------------
            */
            if ($deletedCount == 0) {
                return response()->json([
                    'status'  => false,
                    'message' => 'No matching cart items found.',
                ], 404);
            }

            /*
            |--------------------------------------------------------------------------
            | Response structure remains unchanged
            |--------------------------------------------------------------------------
            */
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

    // public function removeShippingRate(Request $request)
    // {
    //     try {
    //         $storeId = current_store_id();

    //         if (!$storeId) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Store not selected or token invalid.',
    //             ], 400);
    //         }

    //         [$user, $sessionId] = $this->resolveIdentity($request);

    //         if (!$user && !$sessionId) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'X-Session-ID header required for guest users.',
    //             ], 422);
    //         }

    //         $query = CartShippingRate::where('store_id', $storeId);

    //         if ($user) {
    //             $query->where('user_id', $user->id);
    //         } else {
    //             $query->where('session_id', $sessionId);
    //         }

    //         $deletedCount = $query->delete();

    //         if ($deletedCount == 0) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'No shipping rate record found.',
    //             ], 404);
    //         }

    //         return response()->json([
    //             'status'        => true,
    //             'message'       => 'Shipping rate record deleted successfully.',
    //             'deleted_count' => $deletedCount,
    //         ], 200);
    //     } catch (Exception $e) {
    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Something went wrong while deleting shipping rate.',
    //             'error'   => $e->getMessage(),
    //         ], 500);
    //     }
    // }

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
                    'message' => 'Authentication or Session ID required.',
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Current cart context
            |--------------------------------------------------------------------------
            */

            $quoteToken = $this->getCurrentQuoteToken(
                $request,
                $user,
                $storeId
            );

            /*
            |--------------------------------------------------------------------------
            | Delete ONLY current context shipping rate
            |--------------------------------------------------------------------------
            */

            $query = CartShippingRate::query()
                ->where('store_id', $storeId);

            if ($user) {
                $query->where('user_id', $user->id);
            } else {
                $query
                    ->whereNull('user_id')
                    ->where('session_id', $sessionId);
            }

            if ($quoteToken) {
                $query->where('quote_token', $quoteToken);
            } else {
                $query->where(function ($q) {
                    $q->whereNull('quote_token')
                        ->orWhere('quote_token', '');
                });
            }

            $query->delete();

            return response()->json([
                'status'  => true,
                'message' => 'Shipping rate removed successfully.',
            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'status'  => false,
                'message' => 'Failed to remove shipping rate.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
