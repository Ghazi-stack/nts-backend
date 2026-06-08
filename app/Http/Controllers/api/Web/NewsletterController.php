<?php

namespace App\Http\Controllers\api\Web;

use App\Http\Controllers\Controller;
use App\Models\Newsletter;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class NewsletterController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $newsletters = Newsletter::latest()->paginate(15);
        return response()->json($newsletters);
    }

    /**
     * Store a newly created resource.
     */
    public function store(Request $request): JsonResponse
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store not selected'
            ], 400);
        }

        $request->validate([
            'email' => 'required|email|unique:newsletters,email',
        ]);

        $newsletter = Newsletter::create([
            'store_id' => $storeId,
            'email' => $request->email,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Email successfully subscribed!',
            'data' => $newsletter
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Newsletter $newsletter): JsonResponse
    {
        return response()->json($newsletter);
    }

    /**
     * Update the specified resource.
     */
    public function update(Request $request, Newsletter $newsletter): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|unique:newsletters,email,' . $newsletter->id,
        ]);

        $newsletter->update($request->only('email'));

        return response()->json([
            'message' => 'Email updated successfully!',
            'data'    => $newsletter
        ]);
    }

    /**
     * Remove the specified resource.
     */
    public function destroy(Newsletter $newsletter): JsonResponse
    {
        $newsletter->delete();

        return response()->json([
            'message' => 'Email deleted successfully!'
        ]);
    }
}
