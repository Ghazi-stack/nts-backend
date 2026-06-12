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

        // Purane records delete kar do
        VisitorSession::where(
            'created_at',
            '<',
            now()->subHours(24)
        )->delete();

        $session = VisitorSession::firstOrCreate([
            'store_id'  => $storeId,
            'session_id' => $request->sessionId,
        ]);

        return response()->json([
            'status' => true,
            'message' => $session->wasRecentlyCreated
                ? 'Session stored successfully.'
                : 'Session already exists.'
        ]);
    }
}
