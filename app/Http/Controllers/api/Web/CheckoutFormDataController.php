<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\CheckoutFormData;
use App\Services\CartContextService;
use Illuminate\Http\Request;

class CheckoutFormDataController extends Controller
{
    protected CartContextService $cartContext;

    public function __construct(CartContextService $cartContext)
    {
        $this->cartContext = $cartContext;
    }

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

        $user = auth('sanctum')->user();

        /*
        |--------------------------------------------------------------------------
        | Session ID from header
        |--------------------------------------------------------------------------
        */
        $sessionId = $request->header('X-Session-ID');

        /*
        |--------------------------------------------------------------------------
        | Guest user must send session ID
        |--------------------------------------------------------------------------
        */
        if (!$user && !$sessionId) {
            return response()->json([
                'status' => false,
                'message' => 'Session ID is required for guest user.'
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Get current draft/cart context
        |--------------------------------------------------------------------------
        |
        | If a draft is currently open, this returns its quote token.
        |
        */
        $quoteToken = $this->cartContext->getCurrentQuoteToken(
            $storeId,
            $user,
            $user ? null : $sessionId
        );

        /*
        |--------------------------------------------------------------------------
        | Build lookup conditions
        |--------------------------------------------------------------------------
        */
        $conditions = [
            'store_id' => $storeId,
        ];

        if ($user) {

            $conditions['user_id'] = $user->id;

            /*
            |--------------------------------------------------------------------------
            | Draft checkout
            |--------------------------------------------------------------------------
            */
            if ($quoteToken) {
                $conditions['quote_token'] = $quoteToken;
            } else {

                /*
                |--------------------------------------------------------------------------
                | Normal cart checkout
                |--------------------------------------------------------------------------
                |
                | quote_token must be NULL for normal checkout.
                |
                */
                $conditions['quote_token'] = null;
            }

        } else {

            /*
            |--------------------------------------------------------------------------
            | Guest checkout
            |--------------------------------------------------------------------------
            */
            $conditions['session_id'] = $sessionId;

            if ($quoteToken) {
                $conditions['quote_token'] = $quoteToken;
            } else {
                $conditions['quote_token'] = null;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Save checkout form
        |--------------------------------------------------------------------------
        */
        $checkout = CheckoutFormData::updateOrCreate(
            $conditions,
            [
                'user_id' => $user?->id,
                'session_id' => $user ? null : $sessionId,
                'quote_token' => $quoteToken,

                'billing_form_data' =>
                    $validated['billingFormData'] ?? null,

                'shipping_form_data' =>
                    $validated['shippingFormData'] ?? null,
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

        $sessionId = $request->header('X-Session-ID');
        $user = auth('sanctum')->user();

        /*
        |--------------------------------------------------------------------------
        | Validate identity
        |--------------------------------------------------------------------------
        */
        if (!$user && !$sessionId) {
            return response()->json([
                'status' => false,
                'message' => 'Authorization token or sessionId is required.'
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Get current quote context
        |--------------------------------------------------------------------------
        |
        | If the customer opened a draft, this gives us that draft's
        | quote token.
        |
        */
        $quoteToken = $this->cartContext->getCurrentQuoteToken(
            $storeId,
            $user,
            $user ? null : $sessionId
        );

        /*
        |--------------------------------------------------------------------------
        | Get checkout form for CURRENT context
        |--------------------------------------------------------------------------
        */
        $checkoutQuery = CheckoutFormData::query()
            ->where('store_id', $storeId);

        if ($user) {

            $checkoutQuery->where(
                'user_id',
                $user->id
            );

        } else {

            $checkoutQuery
                ->whereNull('user_id')
                ->where(
                    'session_id',
                    $sessionId
                );
        }

        /*
        |--------------------------------------------------------------------------
        | Draft checkout
        |--------------------------------------------------------------------------
        |
        | The currently opened draft has its own checkout form data.
        |
        */
        if ($quoteToken) {

            $checkoutQuery->where(
                'quote_token',
                $quoteToken
            );

        } else {

            /*
            |--------------------------------------------------------------------------
            | Normal checkout
            |--------------------------------------------------------------------------
            |
            | Do NOT return a previous draft's billing/shipping information.
            |
            */
            $checkoutQuery->where(function ($query) {
                $query
                    ->whereNull('quote_token')
                    ->orWhere('quote_token', '');
            });
        }

        $checkout = $checkoutQuery
            ->latest('id')
            ->first();

        /*
        |--------------------------------------------------------------------------
        | Check if expired (24 hours)
        |--------------------------------------------------------------------------
        */
        if (
            $checkout &&
            $checkout->updated_at &&
            $checkout->updated_at->diffInHours(now()) >= 24
        ) {
            $checkout->delete();

            $checkout = null;
        }

        return response()->json([
            'status' => true,
            'data' => $checkout
        ]);
    }
}