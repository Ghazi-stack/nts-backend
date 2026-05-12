<?php

namespace App\Http\Controllers\api\Web;

use App\Models\ContactRequest;
use App\Mail\ContactRequestMail;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Mail;
use App\Http\Resources\ContactRequestResource;

class ContactRequestController extends Controller
{
    /**
     * Display a listing of contact requests (GET)
     */
    public function index(): JsonResponse
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store not selected or token invalid.'
            ], 400);
        }

        $requests = ContactRequest::where('store_id', $storeId)
            ->latest()
            ->paginate(15);

        return response()->json([
            'status' => true,
            'data' => ContactRequestResource::collection($requests),
            'pagination' => [
                'total' => $requests->total(),
                'per_page' => $requests->perPage(),
                'current_page' => $requests->currentPage(),
            ]
        ]);
    }

    /**
     * Store a newly created contact request (POST)
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected or token invalid.'
                ], 400);
            }

            // ✅ Updated Validation (Form ke hisaab se)
            $validated = $request->validate([
                'full_name'     => 'required|string|max:255',
                'phone_number'  => 'required|string|max:20',
                'email'         => 'required|email|max:255',
                'order_number'  => 'nullable|string|max:100',
                'company_name'  => 'nullable|string|max:255',
                'rma_number'    => 'nullable|string|max:100',
                'comments'      => 'nullable|string',
            ]);

            $contactRequest = ContactRequest::create([
                'store_id'      => $storeId,
                'full_name'     => $validated['full_name'],
                'phone_number'  => $validated['phone_number'],
                'email'         => $validated['email'],
                'order_number'  => $validated['order_number'] ?? null,
                'company_name'  => $validated['company_name'] ?? null,
                'rma_number'    => $validated['rma_number'] ?? null,
                'comments'      => $validated['comments'] ?? null,
            ]);

            // Send Email
            $adminEmail = config('mail.from.address');
            Mail::to($adminEmail)->send(new ContactRequestMail($contactRequest));

            return response()->json([
                'status'  => true,
                'message' => 'Contact request submitted successfully!',
                'data'    => new ContactRequestResource($contactRequest)
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show single contact request
     */
    public function show(ContactRequest $contactRequest): JsonResponse
    {
        return response()->json([
            'status' => true,
            'data'   => new ContactRequestResource($contactRequest)
        ]);
    }
}
