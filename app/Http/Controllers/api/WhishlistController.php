<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Wishlist;
use App\Http\Resources\WishlistResource;

class WishlistController extends Controller
{
    public function index(Request $request)
    {
        $items = Wishlist::with('product')->where('user_id', $request->user()->id)->get();
        return WishlistResource::collection($items);
    }

    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id'
        ]);

        $exists = Wishlist::where('user_id', $request->user()->id)
                          ->where('product_id', $request->product_id)
                          ->first();

        if (!$exists) {
            Wishlist::create([
                'user_id' => $request->user()->id,
                'product_id' => $request->product_id,
            ]);
        }

        return response()->json(['message' => 'Added to wishlist']);
    }

    public function destroy($id)
    {
        $item = Wishlist::findOrFail($id);
        $item->delete();

        return response()->json(['message' => 'Removed from wishlist']);
    }
}
