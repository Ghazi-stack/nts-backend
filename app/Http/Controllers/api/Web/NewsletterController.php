<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\Newsletter;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Mail\NewsletterSubscribedMail;

class NewsletterController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store not selected'
            ], 400);
        }

        $newsletters = Newsletter::where('store_id', $storeId)
            ->latest()
            ->paginate(15);

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
            'email' => [
                'required',
                'email',
                Rule::unique('newsletters')
                    ->where(function ($query) use ($storeId) {
                        return $query->where('store_id', $storeId);
                    }),
            ],
        ]);

        $newsletter = Newsletter::create([
            'store_id' => $storeId,
            'email'    => $request->email,
        ]);

        try {

            // Customer Email
            Mail::to($newsletter->email)
                ->send(new NewsletterSubscribedMail($newsletter));

            // Admin Email
            $adminEmail = env('MAIL_FROM_SB_ADMIN', 'hkinza575@gmail.com');
            Mail::to($adminEmail)
                ->send(new NewsletterSubscribedMail($newsletter, true));
                
        } catch (\Exception $e) {

            \Log::error('NEWSLETTER EMAIL FAILED', [
                'newsletter_id' => $newsletter->id,
                'customer_email' => $newsletter->email,
                'admin_email' => $adminEmail ?? null,
                'error' => $e->getMessage(),
            ]);
        }
        return response()->json([
            'status'  => true,
            'message' => 'Email successfully subscribed!',
            'data'    => $newsletter
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Newsletter $newsletter): JsonResponse
    {
        $storeId = current_store_id();

        if ($newsletter->store_id != $storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Newsletter not found'
            ], 404);
        }

        return response()->json($newsletter);
    }

    /**
     * Update the specified resource.
     */
    public function update(Request $request, Newsletter $newsletter): JsonResponse
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store not selected'
            ], 400);
        }

        if ($newsletter->store_id != $storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Newsletter not found'
            ], 404);
        }

        $request->validate([
            'email' => [
                'required',
                'email',
                Rule::unique('newsletters')
                    ->where(function ($query) use ($storeId) {
                        return $query->where('store_id', $storeId);
                    })
                    ->ignore($newsletter->id),
            ],
        ]);

        $newsletter->update([
            'email' => $request->email
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Email updated successfully!',
            'data' => $newsletter
        ]);
    }

    /**
     * Remove the specified resource.
     */
    public function destroy(Newsletter $newsletter): JsonResponse
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store not selected'
            ], 400);
        }

        if ($newsletter->store_id != $storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Newsletter not found'
            ], 404);
        }

        $newsletter->delete();

        return response()->json([
            'status' => true,
            'message' => 'Email deleted successfully!'
        ]);
    }
}
