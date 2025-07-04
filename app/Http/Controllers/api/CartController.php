<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Product;
use App\Http\Resources\CartResource;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function index(Request $request)
    {
        $cartItems = Cart::with('product')->where('user_id', $request->user()->id)->get();
        return CartResource::collection($cartItems);
    }

    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity'   => 'required|integer|min:1',
        ]);

        $cartItem = Cart::updateOrCreate(
            ['user_id' => $request->user()->id, 'product_id' => $request->product_id],
            ['quantity' => $request->quantity]
        );

        return new CartResource($cartItem->load('product'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $cartItem = Cart::where('id', $id)->where('user_id', $request->user()->id)->firstOrFail();
        $cartItem->update(['quantity' => $request->quantity]);

        return new CartResource($cartItem->load('product'));
    }

    public function destroy($id)
    {
        $cartItem = Cart::findOrFail($id);
        $cartItem->delete();

        return response()->json(['message' => 'Item removed from cart']);
    }
}
