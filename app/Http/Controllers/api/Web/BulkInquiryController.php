<?php

namespace App\Http\Controllers\api\Web;

use App\Models\BulkInquiry;
use Illuminate\Http\Request;
use App\Mail\BulkInquiryMail;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Mail;
use App\Http\Resources\BulkInquiryResource;

class BulkInquiryController extends Controller
{
    public function store(Request $request)
    {
        try {
            $storeId = current_store_id(); // existing store logic
            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected or token invalid.'
                ], 400);
            }

            $validated = $request->validate([
                'email' => 'nullable|email|max:255',
                'fullName' => 'nullable|string|max:255',
                'phone' => 'nullable|string|max:20',
                'comments' => 'nullable|string',
                'quantity' => 'nullable|integer|min:1',
                'sku' => 'nullable|string|max:100',
            ]);

            $bulkInquiry = BulkInquiry::create([
                'store_id' => $storeId,
                'email' => $validated['email'],
                'full_name' => $validated['fullName'],
                'phone' => $validated['phone'],
                'comments' => $validated['comments'] ?? null,
                'quantity' => $validated['quantity'],
                'sku' => $validated['sku'],
            ]);

             $adminEmail = env('MAIL_FROM_SB_ADMIN', 'info@serverblink.uk'); 
            Mail::to($adminEmail)->send(new BulkInquiryMail($bulkInquiry));

            return response()->json([
                'status' => true,
                'message' => 'Bulk inquiry submitted successfully!',
                'data' => new BulkInquiryResource($bulkInquiry)
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
