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

        $daysBack = $request->query('days', 7);
        $start = Carbon::now()->subDays($daysBack - 1);
        $end = Carbon::now();
        $days = collect();

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $label = $date->format('D');
            $dateStr = $date->toDateString();

            $visits = VisitorSession::where('store_id', $storeId)
                ->where('created_at', '>=', now()->subHours(24))
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

            $days->push([
                'label' => $label,
                'date' => $dateStr,
                'visits' => $visits,
                'orders' => $orders,
                'revenue' => $revenue,
                'conversion' => $conversion,
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Store performance data retrieved successfully',
            'range_start' => $start->toDateString(),
            'range_end' => $end->toDateString(),
            'data' => $days
        ]);
    }

    public function storeCount(Request $request)
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store not selected.'
            ], 400);
        }

        // Last 24 hours
        $cutoff = now()->subHours(24);

        $visits = VisitorSession::where('store_id', $storeId)
            ->where('created_at', '>=', $cutoff)
            ->count();

        $orders = DB::table('orders')
            ->where('store_id', $storeId)
            ->count();

        $revenue = DB::table('orders')
            ->where('store_id', $storeId)
            ->sum('total_amount');

        $conversion = $visits > 0
            ? round(($orders / $visits) * 100, 2)
            : 0;

        return response()->json([
            'status' => true,
            'message' => 'Store count data retrieved successfully',
            'data' => [
                'visits' => $visits,
                'orders' => $orders,
                'revenue' => (float) $revenue,
                'conversion' => $conversion,
            ]
        ]);
    }

    public function storeCountFiltered(Request $request)
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store not selected.'
            ], 400);
        }

        $filter = $request->query('filter', 'today');

        switch ($filter) {
            case 'today':
                $startDate = now()->startOfDay();
                $endDate = now()->endOfDay();
                $previousStart = now()->subDay()->startOfDay();
                $previousEnd = now()->subDay()->endOfDay();
                break;

            case 'week_to_date':
                $startDate = now()->startOfWeek();
                $endDate = now()->endOfDay();
                $previousStart = now()->subWeek()->startOfWeek();
                $previousEnd = now()->subWeek()->endOfWeek();
                break;

            case 'month_to_date':
                $startDate = now()->startOfMonth();
                $endDate = now()->endOfDay();
                $previousStart = now()->subMonth()->startOfMonth();
                $previousEnd = now()->subMonth()->endOfMonth();
                break;

            case 'year_to_date':
                $startDate = now()->startOfYear();
                $endDate = now()->endOfDay();
                $previousStart = now()->subYear()->startOfYear();
                $previousEnd = now()->subYear()->endOfYear();
                break;

            case 'last_7_days':
                $startDate = now()->subDays(7)->startOfDay();
                $endDate = now()->endOfDay();
                $previousStart = now()->subDays(14)->startOfDay();
                $previousEnd = now()->subDays(7)->endOfDay();
                break;

            case 'last_30_days':
                $startDate = now()->subDays(30)->startOfDay();
                $endDate = now()->endOfDay();
                $previousStart = now()->subDays(60)->startOfDay();
                $previousEnd = now()->subDays(30)->endOfDay();
                break;

            case 'last_365_days':
                $startDate = now()->subDays(365)->startOfDay();
                $endDate = now()->endOfDay();
                $previousStart = now()->subDays(730)->startOfDay();
                $previousEnd = now()->subDays(365)->endOfDay();
                break;

            default:
                $startDate = now()->startOfDay();
                $endDate = now()->endOfDay();
                $previousStart = now()->subDay()->startOfDay();
                $previousEnd = now()->subDay()->endOfDay();
                break;
        }

        // Current period
        $visits = VisitorSession::where('store_id', $storeId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        $orders = DB::table('orders')
            ->where('store_id', $storeId)
            ->where('is_draft', 0)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        $revenue = DB::table('orders')
            ->where('store_id', $storeId)
            ->where('is_draft', 0)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('total_amount');

        $conversion = $visits > 0
            ? round(($orders / $visits) * 100, 2)
            : 0;

        // Previous period (comparison)
        $previousVisits = VisitorSession::where('store_id', $storeId)
            ->whereBetween('created_at', [$previousStart, $previousEnd])
            ->count();

        $previousOrders = DB::table('orders')
            ->where('store_id', $storeId)
            ->where('is_draft', 0)
            ->whereBetween('created_at', [$previousStart, $previousEnd])
            ->count();

        $previousRevenue = DB::table('orders')
            ->where('store_id', $storeId)
            ->where('is_draft', 0)
            ->whereBetween('created_at', [$previousStart, $previousEnd])
            ->sum('total_amount');

        $previousConversion = $previousVisits > 0
            ? round(($previousOrders / $previousVisits) * 100, 2)
            : 0;

        return response()->json([
            'status' => true,
            'message' => 'Store count data retrieved successfully.',
            'filter' => $filter,
            'dateRange' => [
                'from' => $startDate->format('M d, Y'),
                'to'   => $endDate->format('M d, Y'),
            ],
            'data' => [
                'visits'     => $visits,
                'orders'     => $orders,
                'revenue'    => (float) $revenue,
                'conversion' => $conversion,
            ],
            'previous' => [
                'visits'     => $previousVisits,
                'orders'     => $previousOrders,
                'revenue'    => (float) $previousRevenue,
                'conversion' => $previousConversion,
            ],
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
