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
            'sessionId' => 'nullable|string',
            'billingFormData' => 'nullable|array',
            'shippingFormData' => 'nullable|array',
        ]);

        $user = auth()->user();

        if (!$user && empty($validated['sessionId'])) {
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
            $conditions['session_id'] = $validated['sessionId'];
        }

        $checkout = CheckoutFormData::updateOrCreate(
            $conditions,
            [
                'user_id' => $user?->id,
                'session_id' => $user ? null : $validated['sessionId'],
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

        $user = auth()->user();

        if ($user) {

            $checkout = CheckoutFormData::where('store_id', $storeId)
                ->where('user_id', $user->id)
                ->first();
        } else {

            $request->validate([
                'sessionId' => 'required|string'
            ]);

            $checkout = CheckoutFormData::where('store_id', $storeId)
                ->where('session_id', $request->sessionId)
                ->first();
        }

        return response()->json([
            'status' => true,
            'data' => $checkout
        ]);
    }
}
