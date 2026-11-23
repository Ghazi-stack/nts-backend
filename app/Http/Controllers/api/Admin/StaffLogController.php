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

        // Central database se owner check
        $isOwner = \App\Models\Store::on('central')
            ->where('id', $storeId)
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

        // Severity filter
        if ($request->filled('severity')) {
            $query->where('severity', $request->severity);
        }

        // Type filter
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // Order ID filter
        if ($request->filled('order_id')) {
            $query->where('order_id', $request->order_id);
        }

        // Email filter
        if ($request->filled('email')) {
            $query->where('email', 'like', '%' . $request->email . '%');
        }

        // Search (summary pe)
        if ($request->filled('search')) {
            $query->where('summary', 'like', '%' . $request->search . '%');
        }

        $perPage = $request->input('per_page', 20);

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
                $filteredRates = collect($allRates)->where('is_fedex', true)->values()->all();
            } else {
                $filteredRates = collect($allRates)->where('display_name', $moduleName)->values()->all();
            }

            if (empty($filteredRates) && !empty($allRates)) {
                $filteredRates = [$allRates[0]];
            }

            $data['carrier_quotes'] = collect($filteredRates)->map(function ($rate) {
                return [
                    'currency'      => $rate['currency'] ?? null,
                    'is_fedex'      => $rate['is_fedex'] ?? false,
                    'method_id'     => $rate['method_id'] ?? null,
                    'rate_type'     => $rate['rate_type'] ?? null,
                    'method_type'   => $rate['method_type'] ?? null,
                    'delivery_day'  => $rate['delivery_day'] ?? null,
                    'display_name'  => $rate['display_name'] ?? null,
                    'service_name'  => $rate['service_name'] ?? null,
                    'service_type'  => $rate['service_type'] ?? null,
                    'total_charge'  => $rate['total_charge'] ?? 0,
                    'delivery_date' => $rate['delivery_date'] ?? null,
                ];
            })->values()->all();

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

        // ===================== EMAIL INTEGRATION =====================
        if ($log->type === 'Email integration') {
            $logData = $log->log_data ?? [];

            if (!empty($logData['has_detail'])) {
                $data['detail'] = $logData['message'] ?? null;
            }

            $data['email'] = $logData['email'] ?? $log->email;
        }

        return response()->json([
            'status' => true,
            'data'   => $data,
        ]);
    }
}
