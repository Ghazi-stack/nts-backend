<?php

namespace App\Http\Controllers\API\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Review;
use App\Http\Resources\ReviewResource;

class ReviewController extends Controller
{
    public function index($productId)
    {
         $perPage = $request->query('perPage', 20);
        $reviews = Review::where('product_id', $productId)->with('user')->latest()->paginate($perPage);;
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
        ], 200); // HTTP 200 OK
    }

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
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Review created successfully',
            'data' => new ReviewResource($review->load('user'))
        ], 201); // HTTP 201 Created
    }
    

    // Admin side
    public function adminIndex(Request $request)
    {
        $page     = (int) $request->input('page', 1);
        $pageSize = (int) $request->input('pageSize', 50);
        $keyword = $request->query('keyword');

        $query = Review::with(['user', 'product'])->latest();

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
                'pageSize'      => $reviews->perPage(),
                'currentpage'  => $reviews->currentPage(),
                'lastpage'     => $reviews->lastPage(),
            ]
        ]);
    }

    // ✅ Approve selected reviews
    public function approveSelected(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'exists:reviews,id'
        ]);

        Review::whereIn('id', $request->ids)->update(['approved' => true]);

        return response()->json(['status' => true, 'message' => 'Reviews approved.']);
    }

    // ✅ Disapprove selected reviews
    public function disapproveSelected(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'exists:reviews,id'
        ]);

        Review::whereIn('id', $request->ids)->update(['approved' => false]);

        return response()->json(['status' => true, 'message' => 'Reviews disapproved.']);
    }

    // ✅ Delete selected reviews
    public function deleteSelected(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'exists:reviews,id'
        ]);

        Review::whereIn('id', $request->ids)->delete();

        return response()->json(['status' => true, 'message' => 'Reviews deleted.']);
    }

}
