<?php

namespace App\Http\Controllers\api\Admin;

use App\Models\Order;
use App\Models\Product;
use App\Models\Customer;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\CustomerResource;

class GlobalSearchController extends Controller
{
     public function search(Request $request)
    {
        $storeId = current_store_id();
        if (!$storeId) {
            return response()->json(['error' => 'Store not selected or token invalid.'], 400);
        }

        $request->validate([
            'query' => 'required|string|min:2',
        ]);

        $query = $request->input('query'); // ✅ correct

        // Limit per section
        $limit = 5;

        // 🔍 Search Products
        $products = Product::where('store_id', $storeId)
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('sku', 'like', "%{$query}%")
                  ->orWhere('slug', 'like', "%{$query}%");
            })
            ->with(['brand', 'categories'])
            ->take($limit)
            ->get();

        // 🔍 Search Orders
        $orders = Order::where('store_id', $storeId)
            ->where(function ($q) use ($query) {
                $q->where('email', 'like', "%{$query}%")
                  ->orWhere('phone', 'like', "%{$query}%")
                  ->orWhere('name', 'like', "%{$query}%")
                  ->orWhere('status', 'like', "%{$query}%")
                  ->orWhere('address', 'like', "%{$query}%");
            })
            ->take($limit)
            ->get();

        // 🔍 Search Customers
        $customers = Customer::where('store_id', $storeId)
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('email', 'like', "%{$query}%")
                  ->orWhere('phone', 'like', "%{$query}%");
            })
            ->take($limit)
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Global search results.',
            'data' => [
                'products' => ProductResource::collection($products),
                'orders'   => OrderResource::collection($orders),
                'customers'=> CustomerResource::collection($customers),
            ]
        ]);
    }
}
