<?php

namespace App\Http\Controllers\api\Admin;

use App\Http\Controllers\Controller;
use App\Models\StaffLog;
use App\Models\Store;
use App\Models\SystemLog;
use Illuminate\Http\Request;

class StaffLogController extends Controller
{
    public function index(Request $request)
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status'  => false,
                'message' => 'Store not selected or token invalid.'
            ], 400);
        }

        $currentUser = auth('sanctum')->user();

        // Sirf Owner dekh sakta hai
        $isOwner = Store::where('id', $storeId)
            ->where('user_id', $currentUser->id)
            ->exists();

        if (!$isOwner) {
            return response()->json([
                'status'  => false,
                'message' => 'Only store owner can view staff logs.'
            ], 403);
        }

        $query = StaffLog::where('store_id', $storeId)
            ->orderBy('id', 'desc');

        // Search by username or action
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('username', 'like', "%{$search}%")
                    ->orWhere('action', 'like', "%{$search}%");
            });
        }

        // Optional filter by action_type
        if ($type = $request->input('action_type')) {
            $query->where('action_type', $type);
        }

        $logs = $query->paginate($request->input('per_page', 20));

        $data = $logs->getCollection()->map(function ($log) {
            return [
                'id'          => $log->id,
                'username'    => $log->username,
                'action'      => $log->action,
                'date'        => $log->created_at?->format('jS M Y @ g:i A'),
                'ip_address'  => $log->ip_address,
                'action_type' => $log->action_type,
            ];
        });

        return response()->json([
            'status'  => true,
            'message' => 'Staff logs fetched successfully.',
            'data'    => $data,
            'meta'    => [
                'current_page' => $logs->currentPage(),
                'last_page'    => $logs->lastPage(),
                'per_page'     => $logs->perPage(),
                'total'        => $logs->total(),
            ]
        ]);
    }

    public function systemLogs(Request $request)
    {
        $storeId = current_store_id();
        if (!$storeId) {
            return response()->json([
                'status'  => false,
                'message' => 'Store not selected',
            ], 400);
        }

        $query = SystemLog::where('store_id', $storeId)
            ->orderBy('logged_at', 'desc')
            ->orderBy('id', 'desc');

        // Optional filters
        if ($request->filled('order_id')) {
            $query->where('order_id', $request->order_id);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type); // Shipping quote / Payment gateway
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->severity); // success / notice
        }

        if ($request->filled('email')) {
            $query->where('email', 'like', '%' . $request->email . '%');
        }

        $perPage = $request->input('per_page', 20);

        // Index pe sirf table columns
        $logs = $query->select([
            'id',
            'severity',
            'type',
            'module',
            'summary',
            'logged_at',
        ])->paginate($perPage);

        return response()->json([
            'status'  => true,
            'message' => 'Logs retrieved successfully',
            'data'    => $logs,
        ]);
    }

    public function systemLogShow($id)
    {
        $storeId = current_store_id();
        if (!$storeId) {
            return response()->json(['status' => false, 'message' => 'Store not selected'], 400);
        }

        $log = SystemLog::where('store_id', $storeId)->find($id);

        if (!$log) {
            return response()->json([
                'status'  => false,
                'message' => 'Log not found',
            ], 404);
        }

        $data = [
            'id'       => $log->id,
            'severity' => $log->severity,
            'type'     => $log->type,
            'module'   => $log->module,
            'summary'  => $log->summary,
            'date'     => $log->logged_at?->format('jS M Y @ h:i A'),
        ];

        // ===================== SHIPPING QUOTE =====================
        if ($log->type === 'Shipping quote') {
            $logData = $log->log_data ?? [];
            $allRates = $logData['carrier_quotes'] ?? [];

            $data['request'] = $logData['request'] ?? null;

            $moduleName = $log->module;
            $filteredRates = [];

            if ($moduleName === 'FedEx (fedex)') {
                // Sirf FedEx rates
                $filteredRates = collect($allRates)->where('is_fedex', true)->values()->all();
            } else {
                // Sirf matching module ka rate
                $filteredRates = collect($allRates)->where('display_name', $moduleName)->values()->all();
            }

            if (empty($filteredRates) && !empty($allRates)) {
                $filteredRates = [$allRates[0]];
            }

            $data['carrier_quotes'] = $filteredRates;

            // Settings
            $data['settings'] = [
                'deliveryServices' => $moduleName === 'FedEx (fedex)'
                    ? collect($filteredRates)->pluck('service_type')->filter()->unique()->values()->all()
                    : [],
            ];
        }
        
        // ===================== PAYMENT =====================
        if ($log->type === 'Payment gateway') {
            $payment = $log->log_data ?? [];

            if ($log->severity === 'success') {
                $data['detail'] = 'Transaction ID: ' . ($payment['transaction_id'] ?? '') . ', Status: ' . ($payment['status'] ?? '');
            }

            if ($log->severity === 'notice') {
                $data['detail'] = $payment['message'] ?? 'Additional payment step required';
            }
        }

        return response()->json([
            'status' => true,
            'data'   => $data,
        ]);
    }
}
