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
use App\Exports\SubscriberExport;
use Maatwebsite\Excel\Facades\Excel;

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
                'status'  => false,
                'message' => 'Store not selected'
            ], 400);
        }

        $request->validate([
            'email' => [
                'required',
                'email',
                Rule::unique('newsletters')
                    ->where(function ($query) use ($storeId) {
                        return $query->where('store_id', $storeId)
                            ->whereNull('deleted_at');
                    }),
            ],
        ]);

        $user = auth('sanctum')->user();
        $name = $user ? $user->name : null;

        $existing = Newsletter::withTrashed()
            ->where('store_id', $storeId)
            ->where('email', $request->email)
            ->first();

        if ($existing && $existing->trashed()) {
            $existing->restore();
            $existing->update([
                'name' => $name,
            ]);
            $newsletter = $existing;
        } else {
            $newsletter = Newsletter::create([
                'store_id' => $storeId,
                'email'    => $request->email,
                'name'     => $name,
            ]);
        }

        try {
            Mail::to($newsletter->email)
                ->send(new NewsletterSubscribedMail($newsletter));

            $adminEmail = env('MAIL_FROM_SB_ADMIN', 'hkinza575@gmail.com');
            Mail::to($adminEmail)
                ->send(new NewsletterSubscribedMail($newsletter, true));
        } catch (\Exception $e) {
            \Log::error('NEWSLETTER EMAIL FAILED', [
                'newsletter_id'  => $newsletter->id,
                'customer_email' => $newsletter->email,
                'admin_email'    => $adminEmail ?? null,
                'error'          => $e->getMessage(),
            ]);
        }

        // ===================== NEWSLETTER SYSTEM LOGS =====================
        try {
            $email = strtolower(trim($request->email));

            $common = [
                'store_id'      => $storeId,
                'order_id'      => null,
                'customer_id'   => null,
                'user_id'       => $user?->id,
                'type'          => 'Email integration',
                'module'        => 'Subscribers',
                'customer_type' => null,
                'email'         => $email,
                'logged_at'     => now(),
            ];

            // 1. Pehla log — detail mein duplicate message aayega
            \App\Models\SystemLog::create(array_merge($common, [
                'severity' => 'success',
                'summary'  => 'Subscriber ' . $email . ' stored for export.',
                'log_data' => [
                    'email'      => $email,
                    'message'    => 'A duplicate subscriber with the email: ' . $email . ' was found',
                    'has_detail' => true,
                ],
            ]));

            // 2. Dusra log — detail nahi aayega
            \App\Models\SystemLog::create(array_merge($common, [
                'severity' => 'success',
                'summary'  => 'Subscriber ' . $email . ' stored for export.',
                'log_data' => [
                    'email'      => $email,
                    'message'    => null,
                    'has_detail' => false,
                ],
            ]));
        } catch (\Throwable $logEx) {
            \Log::warning('Newsletter system log failed', ['error' => $logEx->getMessage()]);
        }
        // ===================== END =====================

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
                'status'  => false,
                'message' => 'Store not selected'
            ], 400);
        }

        if ($newsletter->store_id != $storeId) {
            return response()->json([
                'status'  => false,
                'message' => 'Newsletter not found'
            ], 404);
        }

        $request->validate([
            'email' => [
                'required',
                'email',
                Rule::unique('newsletters')
                    ->where(function ($query) use ($storeId) {
                        return $query->where('store_id', $storeId)
                            ->whereNull('deleted_at');
                    })
                    ->ignore($newsletter->id),
            ],
        ]);

        $user = auth('sanctum')->user();
        $name = $user ? $user->name : $newsletter->name;

        // Agar naya email soft-deleted record mein already maujood hai
        $trashed = Newsletter::onlyTrashed()
            ->where('store_id', $storeId)
            ->where('email', $request->email)
            ->where('id', '!=', $newsletter->id)
            ->first();

        if ($trashed) {
            // Soft deleted record ko permanently delete kar do taake unique conflict na aaye
            $trashed->forceDelete();
        }

        $newsletter->update([
            'email' => $request->email,
            'name'  => $name,
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Email updated successfully!',
            'data'    => $newsletter
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

    public function exportSubscribers()
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store not selected or token invalid.'
            ], 400);
        }

        $count = \App\Models\Newsletter::where('store_id', $storeId)->count();

        if ($count === 0) {
            return response()->json([
                'status' => false,
                'message' => 'No subscribers found.'
            ], 404);
        }

        $fileName = 'subscribers-' . now()->format('Y-m-d') . '.xlsx';
        return Excel::download(new SubscriberExport($storeId), $fileName);
    }

    public function deleteAllSubscribers()
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store not selected or token invalid.'
            ], 400);
        }

        $count = Newsletter::where('store_id', $storeId)->count();

        if ($count === 0) {
            return response()->json([
                'status' => false,
                'message' => 'No subscribers found.'
            ], 404);
        }

        Newsletter::where('store_id', $storeId)->delete();

        return response()->json([
            'status' => true,
            'message' => $count . ' subscribers deleted successfully.',
        ]);
    }
}
