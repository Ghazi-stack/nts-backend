<?php

namespace App\Http\Controllers\api\Admin;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

class HomeController extends Controller
{
    public function storePerformance(Request $request)
    {
        $daysBack = $request->query('days', 7); // default to 7 days

        $start = Carbon::now()->subDays($daysBack - 1);
        $end = Carbon::now();

        $days = collect();

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $label = $date->format('D'); // e.g. Mon, Tue
            $dateStr = $date->toDateString();

            $visits = DB::table('visits-site')
                ->whereDate('visit_date', $dateStr)
                ->sum('count');

            $orders = DB::table('orders')
                ->whereDate('created_at', $dateStr)
                ->count();

            $revenue = DB::table('orders')
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
            'range_start' => $start->toDateString(),
            'range_end' => $end->toDateString(),
            'data' => $days
        ]);
    }

     public function track(Request $request)
    {
        $today = now()->toDateString();

        $visit = DB::table('visits-site')->where('visit_date', $today)->first();

        if ($visit) {
            DB::table('visits-site')
                ->where('visit_date', $today)
                ->increment('count');
        } else {
            DB::table('visits-site')->insert([
                'visit_date' => $today,
                'count' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Visit recorded'
        ],200);
    }
}
