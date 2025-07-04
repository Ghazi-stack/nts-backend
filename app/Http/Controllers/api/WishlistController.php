<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Wishlist;
// use App\Models\Wishlist;
use App\Http\Resources\WishlistResource;

class WishlistController extends Controller
{
    public function index(Request $request)
    {
        $items = Wishlist::with('product')->where('user_id', $request->user()->id)->get();
        return response()->json([
            'status' => true,
            'message' => 'Wishlist retrieved successfully',
            'data' => WishlistResource::collection($items)
        ], 200); // HTTP 200 OK
    }

    public function store(Request $request)
    {
        $request->validate([
            'productId' => 'required|exists:products,id'
        ]);

        $exists = Wishlist::where('user_id', $request->user()->id)
                          ->where('product_id', $request->productId)
                          ->first();

        if (!$exists) {
            Wishlist::create([
                'user_id' => $request->user()->id,
                'product_id' => $request->productId,
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Added to wishlist',
            'data' => new WishlistResource($exists)
        ], 201); // HTTP 201 Created
    }

    public function destroy($id)
    {
        $item = Wishlist::findOrFail($id);
        $item->delete();

        return response()->json([
            'status' => true,
            'message' => 'Removed from wishlist',
            'data' => new WishlistResource($item)
        ], 200); // HTTP 200 OK
    }
}
