<?php

namespace App\Http\Controllers\api\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\VisitorSession;

class VisitorSessionController extends Controller
{
    public function store(Request $request)
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store not selected or token invalid.'
            ], 400);
        }

        $request->validate([
            'sessionId' => 'required|string|max:255'
        ]);

        // Check if this session already exists within the last 24 hours
        $existingSession = VisitorSession::where('store_id', $storeId)
            ->where('session_id', $request->sessionId)
            ->where('created_at', '>=', now()->subHours(24))
            ->first();

        if ($existingSession) {
            return response()->json([
                'status' => true,
                'message' => 'Session already exists.'
            ]);
        }

        // Create new record (old records remain in database)
        VisitorSession::create([
            'store_id'  => $storeId,
            'session_id' => $request->sessionId,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Session stored successfully.'
        ]);
    }
}
