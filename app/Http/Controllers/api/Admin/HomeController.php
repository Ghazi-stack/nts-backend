<?php

namespace App\Http\Controllers\api\Admin;

use Exception;
use Carbon\Carbon;
use App\Models\Order;
use App\Models\Product;
use App\Models\Category;
use App\Models\Customer;
use App\Models\VisitSite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Models\VisitorSession;

class HomeController extends Controller
{

    public function storePerformance(Request $request)
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store not selected.'
            ], 400);
        }

        // VisitorSession ki sab dates nikal lo
        $dates = VisitorSession::where('store_id', $storeId)
            ->selectRaw('DATE(created_at) as date')
            ->distinct()
            ->orderBy('date')
            ->pluck('date');

        $data = collect();

        foreach ($dates as $dateStr) {

            $visits = VisitorSession::where('store_id', $storeId)
                ->whereDate('created_at', $dateStr)
                ->count();

            $orders = DB::table('orders')
                ->where('store_id', $storeId)
                ->whereDate('created_at', $dateStr)
                ->count();

            $revenue = DB::table('orders')
                ->where('store_id', $storeId)
                ->whereDate('created_at', $dateStr)
                ->sum('total_amount');

            $conversion = $visits > 0
                ? round(($orders / $visits) * 100, 2)
                : 0;

            $data->push([
                'label'      => Carbon::parse($dateStr)->format('D'),
                'date'       => $dateStr,
                'visits'     => $visits,
                'orders'     => $orders,
                'revenue'    => (float) $revenue,
                'conversion' => $conversion,
            ]);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Store performance data retrieved successfully',
            'total_days' => $data->count(),
            'data' => $data,
        ]);
    }

    public function track(Request $request)
    {
        $today = now()->toDateString();
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store not selected.'
            ], 400);
        }

        $visit = VisitSite::where('store_id', $storeId)
            ->where('visit_date', $today)
            ->first();

        if ($visit) {
            $visit->increment('count');
        } else {
            VisitSite::create([
                'visit_date' => $today,
                'count' => 1,
                'store_id' => $storeId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Visit recorded'
        ]);
    }

    public function getStoreStats()
    {
        try {
            $storeId = current_store_id(); // 🔐 Your existing helper to fetch store ID

            $totalOrders = Order::where('store_id', $storeId)
                ->where('is_draft', '!=', 1)
                ->count();
            $totalProducts = Product::where('store_id', $storeId)->count();
            $totalCategories = Category::where('store_id', $storeId)->count();
            $totalCustomers = Customer::where('store_id', $storeId)->count();

            return response()->json([
                'status' => true,
                'message' => 'Store statistics fetched successfully.',
                'data' => [
                    'totalOrders' => $totalOrders,
                    'totalProducts' => $totalProducts,
                    'totalCategories' => $totalCategories,
                    'totalCustomers' => $totalCustomers,
                ]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch stats.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
