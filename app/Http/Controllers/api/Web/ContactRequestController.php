<?php

namespace App\Http\Controllers\api\Web;

use Illuminate\Http\Request;
use App\Models\ContactRequest;
use App\Mail\ContactRequestMail;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Mail;
use App\Http\Resources\ContactRequestResource;

class ContactRequestController extends Controller
{
     public function store(Request $request)
    {
        try {
            $storeId = current_store_id(); // function to get current store
            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected or token invalid.'
                ], 400);
            }

            $validated = $request->validate([
                'company' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'fullName' => 'required|string|max:255',
                'partNumber' => 'required|string|max:100',
                'phoneNumber' => 'required|string|max:20',
                'requiredQty' => 'required|integer|min:1',
            ]);

            $contactRequest = ContactRequest::create([
                'store_id' => $storeId,
                'company' => $validated['company'],
                'email' => $validated['email'],
                'full_name' => $validated['fullName'],
                'part_number' => $validated['partNumber'],
                'phone_number' => $validated['phoneNumber'],
                'required_qty' => $validated['requiredQty'],
            ]);
             
            $adminEmail = config('mail.from.address'); // Using .env MAIL_FROM_ADDRESS
            Mail::to($adminEmail)->send(new ContactRequestMail($contactRequest));
            return response()->json([
                'status' => true,
                'message' => 'Contact request submitted successfully!',
                'data' => new ContactRequestResource($contactRequest)
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
