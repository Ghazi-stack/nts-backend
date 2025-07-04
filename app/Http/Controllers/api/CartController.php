<?php

namespace App\Http\Controllers\API;

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
        $cartItems = Cart::with('product')->where('user_id', $request->user()->id)->get();

        return resource()->json([
            'status' => true,
            'message' => 'Cart items retrieved successfully',
            'data' => CartResource::collection($cartItems)
        ]);
    }

    public function storeCart(Request $request)
    {
        try {
            $validated = $request->validate([
                'product_id' => 'required|exists:products,id',
                'quantity'   => 'required|integer|min:1',
            ]);

            $cartItem = Cart::updateOrCreate(
                [
                    'user_id' => $request->user()->id,
                    'product_id' => $validated['product_id']
                ],
                [
                    'quantity' => $validated['quantity']
                ]
            );

            return response()->json([
                'status' => true,
                'message' => 'Item added to cart successfully',
                'data' => new CartResource($cartItem->load('product'))
            ], 201); // Status code 201: Created

        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (Exception $e) {
            return response()->json([
                'message' => 'An error occurred while adding to cart.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateCart(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'quantity' => 'required|integer|min:1',
            ]);

            $cartItem = Cart::where('id', $id)
                ->where('user_id', $request->user()->id)
                ->firstOrFail();

            $cartItem->update(['quantity' => $validated['quantity']]);

            return response()->json([
                'status' => true,
                'message' => 'Cart item updated successfully',
                'data' => new CartResource($cartItem->load('product'))
            ], 200); // Status code 200: OK

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Cart item not found'
            ], 404);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (Exception $e) {
            return response()->json([
                'message' => 'An error occurred while updating the cart item.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroyCart(Request $request, $id)
    {
        try {
            $cartItem = Cart::where('id', $id)
                ->where('user_id', $request->user()->id)
                ->firstOrFail();

            $cartItem->delete();

            return response()->json([
                'status' => true,
                'message' => 'Item removed from cart successfully'
            ], 200); // Status code 200: OK

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Cart item not found'
            ], 404);

        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while removing the item from cart.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
