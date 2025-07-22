<?php

namespace App\Http\Controllers\API\Admin;

use App\Models\Store;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\StoreResource;

class StoreController extends Controller
{
    /**
     * List all stores assigned to the logged-in user
     */
    public function index(Request $request)
    {
        $stores = $request->user()->stores()
            ->select('stores.id', 'stores.name', 'stores.base_url')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Stores retrieved successfully',
            'stores' => StoreResource::collection($stores),
        ]);
    }

    /**
     * Select a store and return a token scoped to it
     */
    public function selectStore(Request $request)
    {
        $request->validate([
            'storeId' => 'required|exists:stores,id',
        ]);

        $user = $request->user();
        $storeId = (int) $request->storeId;

        // ✅ Check if this store is assigned to the user
        if (!$user->stores->contains($storeId)) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized: Store not assigned to user.'
            ], 403);
        }

        // ✅ Create new token with a proper name (used later to extract store_id)
        $token = $user->createToken('StoreToken-' . $storeId)->plainTextToken;

        $store = Store::select('id', 'name', 'base_url')->find($storeId);

        return response()->json([
            'status' => true,
            'message' => 'Store selected successfully.',
            'token' => $token,
            'store' => new StoreResource($store),
        ]);
    }
}
