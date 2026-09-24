<?php

namespace App\Http\Controllers\api\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Review;
use App\Http\Resources\ReviewResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class ReviewController extends Controller
{
    // 🛍️ Get reviews for a product (Web)
    public function index(Request $request, $productId)
    {
        $perPage = $request->query('perPage', 20);

        $reviews = Review::where('product_id', $productId)
            ->where('store_id', current_store_id())
            ->with('user')
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Reviews retrieved successfully',
            'data' => ReviewResource::collection($reviews),
            'pagination' => [
                'total' => $reviews->total(),
                'count' => $reviews->count(),
                'per_page' => $reviews->perPage(),
                'current_page' => $reviews->currentPage(),
                'total_pages' => $reviews->lastPage(),
            ]
        ], 200);
    }

    public function show($id)
    {
        $review = Review::with(['user', 'product'])
            ->where('store_id', current_store_id())
            ->find($id);

        if (!$review) {
            return response()->json([
                'status' => false,
                'message' => 'Review not found.'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Review retrieved successfully.',
            'data' => new ReviewResource($review)
        ], 200);
    }
    // ✍️ Submit review (Web)
    public function store(Request $request)
    {
        $request->validate([
            'productId' => 'required|exists:products,id',
            'rating'    => 'required|integer|min:1|max:5',
            'name'      => 'nullable|string|max:255',
            'email'     => 'required|email|max:255',
            'subject'   => 'required|string|max:255',
            'comment'   => 'nullable|string',
        ]);

        $user = Auth::guard('sanctum')->user();

        if (!$user && $request->bearerToken()) {
            $token = \Laravel\Sanctum\PersonalAccessToken::findToken($request->bearerToken());
            $user  = $token?->tokenable;
        }

        if (!$user) {
            $user = User::where('email', $request->email)->first();
        }

        if (!$user) {
            return response()->json([
                'status'  => false,
                'message' => 'Please register or log in to submit a review.',
            ], 401);
        }

        $loginEmail = strtolower(trim((string) $user->email));
        $formEmail  = strtolower(trim((string) $request->email));

        if ($loginEmail !== '' && $formEmail !== $loginEmail) {
            return response()->json([
                'status'  => false,
                'message' => 'This email does not match your logged-in account. Please use your account email to submit a review.',
            ], 403);
        }

        $customerId = $user->customer_id ?? null;

        if (empty($customerId)) {
            return response()->json([
                'status'  => false,
                'message' => 'Only customers who have purchased this item can review it.',
            ], 403);
        }

        $storeId = current_store_id();

        $orderIds = OrderItem::where('product_id', $request->productId)
            ->where('store_id', $storeId)
            ->pluck('order_id');

        if ($orderIds->isEmpty()) {
            return response()->json([
                'status'  => false,
                'message' => 'Only customers who have purchased this item can review it.',
            ], 403);
        }

        $hasOrder = Order::whereIn('id', $orderIds)
            ->where('customer_id', $customerId)
            ->where('store_id', $storeId)
            ->exists();

        if (!$hasOrder) {
            return response()->json([
                'status'  => false,
                'message' => 'Only customers who have purchased this item can review it.',
            ], 403);
        }

        $review = Review::create([
            'user_id'    => $user->id,
            'product_id' => $request->productId,
            'rating'     => $request->rating,
            'name'       => $request->name ?? $user->name,
            'email'      => $request->email,
            'subject'    => $request->subject,
            'comment'    => $request->comment,
            'store_id'   => $storeId,
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Review created successfully',
            'data'    => new ReviewResource($review->load('user')),
        ], 201);
    }

    // 🧑‍💼 Admin: list reviews
    public function adminIndex(Request $request)
    {
        $perPage = (int) $request->input('perPage', 50);
        $keyword = $request->query('keyword');

        $query = Review::with(['user', 'product.seo'])
            ->where('store_id', current_store_id())
            ->latest();

        if ($keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->where('comment', 'like', "%{$keyword}%")
                    ->orWhere('name', 'like', "%{$keyword}%")
                    ->orWhere('email', 'like', "%{$keyword}%")
                    ->orWhere('subject', 'like', "%{$keyword}%")
                    ->orWhereHas('user', fn($u) => $u->where('name', 'like', "%{$keyword}%"))
                    ->orWhereHas('product', fn($p) => $p->where('name', 'like', "%{$keyword}%"));
            });
        }

        $reviews = $query->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Reviews retrieved successfully.',
            'data' => ReviewResource::collection($reviews),
            'pagination' => [
                'total' => $reviews->total(),
                'count' => $reviews->count(),
                'pageSize' => $reviews->perPage(),
                'currentPage' => $reviews->currentPage(),
                'lastPage' => $reviews->lastPage(),
            ]
        ]);
    }

    public function getReviews(Request $request)
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status'  => false,
                'message' => 'Store not selected or token invalid.',
            ], 400);
        }

        $request->validate([
            'productId' => 'required|integer|exists:products,id',
        ]);

        $reviews = Review::where('store_id', $storeId)
            ->where('product_id', $request->productId)
            ->where('approved', 1)
            ->latest()
            ->get();

        return response()->json([
            'status'  => true,
            'message' => 'Reviews retrieved successfully',
            'data'    => $reviews,
        ]);
    }

    // ✅ Admin: Approve reviews
    public function approveSelected(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'exists:reviews,id'
        ]);

        Review::whereIn('id', $request->ids)
            ->where('store_id', current_store_id())
            ->update(['approved' => true]);

        return response()->json(['status' => true, 'message' => 'Reviews approved.']);
    }

    // ✅ Admin: Disapprove reviews
    public function disapproveSelected(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'exists:reviews,id'
        ]);

        Review::whereIn('id', $request->ids)
            ->where('store_id', current_store_id())
            ->update(['approved' => false]);

        return response()->json(['status' => true, 'message' => 'Reviews disapproved.']);
    }

    //bulkStatusUpdate
    public function bulkStatusUpdate(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'exists:reviews,id',
            'status' => 'required|in:approved,disapproved',
        ]);

        $approved = $request->status === 'approved';

        $updated = Review::whereIn('id', $request->ids)
            ->where('store_id', current_store_id())
            ->update([
                'approved' => $approved
            ]);

        return response()->json([
            'status' => true,
            'message' => "Reviews {$request->status} successfully.",
            'updated_count' => $updated
        ]);
    }

    public function update(Request $request, $id)
    {
        $review = Review::where('store_id', current_store_id())
            ->find($id);

        if (!$review) {
            return response()->json([
                'status' => false,
                'message' => 'Review not found.'
            ], 404);
        }

        $request->validate([
            'rating'   => 'sometimes|integer|min:1|max:5',
            'name'     => 'sometimes|nullable|string|max:255',
            'subject'  => 'sometimes|string|max:255',
            'comment'  => 'sometimes|nullable|string',
            'approved' => 'sometimes|boolean',
        ]);

        $review->update($request->only([
            'rating',
            'name',
            'subject',
            'comment',
            'approved'
        ]));

        return response()->json([
            'status' => true,
            'message' => 'Review updated successfully.',
            'data' => new ReviewResource($review->fresh()->load(['user', 'product']))
        ]);
    }
    // ✅ Admin: Delete reviews
    public function deleteSelected(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'exists:reviews,id'
        ]);

        Review::whereIn('id', $request->ids)
            ->where('store_id', current_store_id())
            ->delete();

        return response()->json(['status' => true, 'message' => 'Reviews deleted.']);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'exists:reviews,id',
        ]);

        $deleted = Review::whereIn('id', $request->ids)
            ->where('store_id', current_store_id())
            ->delete();

        return response()->json([
            'status' => true,
            'message' => 'Reviews deleted successfully.',
            'deleted_count' => $deleted
        ]);
    }
}
