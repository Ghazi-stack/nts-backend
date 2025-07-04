<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Review;
use App\Http\Resources\ReviewResource;

class ReviewController extends Controller
{
    public function index($product_id)
    {
        $reviews = Review::where('product_id', $product_id)->with('user')->latest()->get();
        return ReviewResource::collection($reviews);
    }

    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'rating'     => 'required|integer|min:1|max:5',
            'comment'    => 'nullable|string'
        ]);

        $review = Review::create([
            'user_id'    => $request->user()->id,
            'product_id' => $request->product_id,
            'rating'     => $request->rating,
            'comment'    => $request->comment,
        ]);

        return new ReviewResource($review->load('user'));
    }
}
