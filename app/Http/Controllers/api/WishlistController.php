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

        $perPage = $request->query('perPage',20);
        $items = Wishlist::with('product')
                ->where('user_id', $request->user()->id)
                ->latest()
                ->paginate($perPage); 
        return response()->json([
            'status' => true,
            'message' => 'Wishlist retrieved successfully',
            'data' => WishlistResource::collection($items),
            'pagination' => [
                'total' => $items->total(),
                'count' => $items->count(),
                'perPage' => $items->perPage(),
                'currentPage' => $items->currentPage(),
                'totalPages' => $items->lastPage(),
        ]
        ], 200); // HTTP 200 OK
    }

    public function store(Request $request)
    {
        $request->validate([
            'productId' => 'required|exists:products,id'
        ]);
    
        $wishlistItem = Wishlist::firstOrCreate(
            [
                'user_id'    => $request->user()->id,
                'product_id' => $request->productId
            ]
        );
    
        return response()->json([
            'status' => true,
            'message' => 'Added to wishlist',
            'data' => new WishlistResource($wishlistItem)
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
