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
    
        $query = $request->input('query');
    
        // 🔍 Search Products
        $products = Product::where('store_id', $storeId)
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('sku', 'like', "%{$query}%")
                  ->orWhere('slug', 'like', "%{$query}%");
            })
            ->with(['brand', 'categories']) // Optional: only if relations exist
            ->get();
        
        // 🔍 Search Customers
        $customers = Customer::where('store_id', $storeId)
            ->where(function ($q) use ($query) {
                $q->where('first_name', 'like', "%{$query}%")
                  ->orWhere('last_name', 'like', "%{$query}%")
                  ->orWhere('email', 'like', "%{$query}%")
                  ->orWhere('phone', 'like', "%{$query}%");
            })
            ->get();
        
        // 🔍 Search Orders — join with customers to search by customer info too
        $orders = Order::select('orders.*')
            ->join('customers', 'orders.customer_id', '=', 'customers.id')
            ->where('orders.store_id', $storeId)
            ->where(function ($q) use ($query) {
                $q->where('customers.first_name', 'like', "%{$query}%")
                  ->orWhere('customers.last_name', 'like', "%{$query}%")
                  ->orWhere('customers.phone', 'like', "%{$query}%")
                  ->orWhere('orders.order_number', 'like', "%{$query}%")
                  ->orWhere('orders.status', 'like', "%{$query}%");
            })
            ->get();
        
        return response()->json([
            'status' => true,
            'message' => 'Global search results.',
            'data' => [
                'products'  => ProductResource::collection($products),
                'orders'    => OrderResource::collection($orders),
                'customers' => CustomerResource::collection($customers),
            ]
        ]);
    }

}
