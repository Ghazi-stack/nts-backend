<?php

namespace App\Http\Controllers\API\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Review;
use App\Http\Resources\ReviewResource;
use Illuminate\Support\Facades\Auth;

class ReviewController extends Controller
{
    // 🛍️ Get reviews for a product (Web)
    public function index(Request $request, $productId)
    {
        $perPage = $request->query('perPage', 20);

        $reviews = Review::where('product_id', $productId)
            ->whereHas('product', function ($q) {
                $q->where('store_id', current_store_id());
            })
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

    // ✍️ Submit review (Web)
    public function store(Request $request)
    {
        $request->validate([
            'productId' => 'required|exists:products,id',
            'rating'    => 'required|integer|min:1|max:5',
            'comment'   => 'nullable|string'
        ]);

        $review = Review::create([
            'user_id'    => $request->user()->id,
            'product_id' => $request->productId,
            'rating'     => $request->rating,
            'comment'    => $request->comment,
            'store_id'   => current_store_id(), // ✅ Important for filtering by store
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Review created successfully',
            'data' => new ReviewResource($review->load('user'))
        ], 201);
    }

    // 🧑‍💼 Admin: list reviews
    public function adminIndex(Request $request)
    {
        $perPage = (int) $request->input('perPage', 50);
        $keyword = $request->query('keyword');

        $query = Review::with(['user', 'product'])
            ->where('store_id', current_store_id())
            ->latest();

        if ($keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->where('comment', 'like', "%{$keyword}%")
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
}
