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

        $exists = VisitorSession::where('store_id', $storeId)
            ->where('session_id', $request->sessionId)
            ->exists();

        if ($exists) {
            return response()->json([
                'status' => true,
                'message' => 'Session already exists.'
            ]);
        }

        VisitorSession::create([
            'store_id' => $storeId,
            'session_id' => $request->sessionId,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Session stored successfully.'
        ]);
    }
}
