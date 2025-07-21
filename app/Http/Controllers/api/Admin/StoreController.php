<?php
namespace App\Http\Controllers\API\Admin;
use App\Models\Store;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\StoreResource;

class StoreController extends Controller
{
    public function index(Request $request)
    {
        $stores = $request->user()->stores()
            ->select('stores.id', 'stores.name', 'stores.base_url')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Stores retrieved successfully',
            'stores' => $stores
        ], 200);
    }

    public function selectStore(Request $request)
    {
        $request->validate([
            'storeId' => 'required|exists:stores,id',
        ]);

        // 🔐 Ensure the user has access to this store
        if (!$request->user()->stores->contains($request->storeId)) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized: Store not assigned to user.'
            ], 403);
        }

        // ✅ Save store in session
        session(['current_store_id' => $request->storeId]);

        // ✅ Get store details (like base_url)
        $store = Store::select('id', 'name', 'base_url')->find($request->storeId);

        return response()->json([
            'status' => true,
            'message' => 'Store selected successfully',
            'store' => new StoreResource($store)
        ],200);
    }
}
