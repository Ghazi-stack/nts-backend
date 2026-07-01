<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\CheckoutFormData;
use Illuminate\Http\Request;

class CheckoutFormDataController extends Controller
{
    /**
     * Save Checkout Form
     */
    public function store(Request $request)
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store not selected or token invalid.'
            ], 400);
        }

        $validated = $request->validate([
            'billingFormData' => 'nullable|array',
            'shippingFormData' => 'nullable|array',
        ]);

        $user = auth()->user();

        // Session ID from Header
        $sessionId = $request->header('x-session-id');

        // Guest user must send sessionId
        if (!$user && !$sessionId) {
            return response()->json([
                'status' => false,
                'message' => 'Session ID is required for guest user.'
            ], 422);
        }

        $conditions = [
            'store_id' => $storeId,
        ];

        if ($user) {
            $conditions['user_id'] = $user->id;
        } else {
            $conditions['session_id'] = $sessionId;
        }

        $checkout = CheckoutFormData::updateOrCreate(
            $conditions,
            [
                'user_id' => $user?->id,
                'session_id' => $user ? null : $sessionId,
                'billing_form_data' => $validated['billingFormData'] ?? null,
                'shipping_form_data' => $validated['shippingFormData'] ?? null,
            ]
        );

        return response()->json([
            'status' => true,
            'message' => 'Checkout form saved successfully.',
            'data' => $checkout
        ]);
    }

    /**
     * Get Checkout Form
     */
    public function index(Request $request)
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store not selected or token invalid.'
            ], 400);
        }

        $sessionId = $request->header('x-session-id');
        $user = auth('sanctum')->user();

        $checkout = null;

        // 1. Token exists → first try user data
        if ($user) {

            $checkout = CheckoutFormData::where('store_id', $storeId)
                ->where('user_id', $user->id)
                ->get();

            // 2. If no user data and sessionId exists → fallback to session
            if (!$checkout && $sessionId) {
                $checkout = CheckoutFormData::where('store_id', $storeId)
                    ->where('session_id', $sessionId)
                    ->get();
            }
        }
        // 3. Guest → session only
        elseif ($sessionId) {

            $checkout = CheckoutFormData::where('store_id', $storeId)
                ->where('session_id', $sessionId)
                ->get();
        }
        // 4. Neither token nor sessionId
        else {

            return response()->json([
                'status' => false,
                'message' => 'Authorization token or sessionId is required.'
            ], 422);
        }

        return response()->json([
            'status' => true,
            'data' => $checkout
        ]);
    }
}
