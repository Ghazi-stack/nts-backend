<?php

namespace App\Http\Controllers\api\Admin;

use App\Http\Controllers\Controller;
use App\Models\StaffLog;
use App\Models\Store;
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
}
