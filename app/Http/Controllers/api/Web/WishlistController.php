<?php

namespace App\Http\Controllers\API\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Wishlist;
use App\Http\Resources\WishlistResource;

class WishlistController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->query('perPage', 20);

        $items = Wishlist::with('product')
            ->where('user_id', $request->user()->id)
            ->where('store_id', current_store_id()) // ✅ filter by store
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
        ], 200);
    }

    public function store(Request $request)
    {
        $request->validate([
            'productId' => 'required|exists:products,id',
        ]);

        $wishlistItem = Wishlist::firstOrCreate([
            'user_id'    => $request->user()->id,
            'product_id' => $request->productId,
            'store_id'   => current_store_id(), // ✅ tag store
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Added to wishlist',
            'data' => new WishlistResource($wishlistItem)
        ], 201);
    }

    public function destroy($id)
    {
        $item = Wishlist::where('id', $id)
            ->where('user_id', auth()->id())
            ->where('store_id', current_store_id()) // ✅ secure delete
            ->firstOrFail();

        $item->delete();

        return response()->json([
            'status' => true,
            'message' => 'Removed from wishlist',
            'data' => new WishlistResource($item)
        ], 200);
    }
}
