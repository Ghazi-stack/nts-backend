<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Review;
use App\Http\Resources\ReviewResource;

class ReviewController extends Controller
{
    public function index($productId)
    {
        $reviews = Review::where('product_id', $productId)->with('user')->latest()->get();
        return response()->json([
            'status' => true,
            'message' => 'Reviews retrieved successfully',
            'data' => ReviewResource::collection($reviews)
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
}
