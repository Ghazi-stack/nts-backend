<?php

namespace App\Http\Controllers\api\Admin;

use Carbon\Carbon;
use App\Models\VisitSite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

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

            $visits = VisitSite::where('store_id', $storeId)
                ->whereDate('visit_date', $dateStr)
                ->sum('count');

            $orders = DB::table('orders')
                ->where('store_id', $storeId)
                ->whereDate('created_at', $dateStr)
                ->count();

            $revenue = DB::table('orders')
                ->where('store_id', $storeId)
                ->whereDate('created_at', $dateStr)
                ->sum('total_amount');

            $conversion = $visits > 0 ? round(($orders / $visits) * 100, 2) : 0;

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
}