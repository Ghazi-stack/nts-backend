<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\CustomerAddress;
use App\Models\SavedAddress;
use Illuminate\Http\Request;

class CustomerAddressController extends Controller
{
    /**
     * Address List
     */
    public function index(Request $request)
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store not selected or token invalid.'
            ], 400);
        }

        $customerAddresses = CustomerAddress::where('store_id', $storeId)
            ->where('customer_id', auth()->id())
            ->latest()
            ->get();

        $savedAddresses = SavedAddress::where('store_id', $storeId)
            ->where('user_id', auth()->id())
            ->latest()
            ->get();

        return response()->json([
            'status' => true,
            'data' => [
                'customer_addresses' => $customerAddresses,
                'saved_addresses' => $savedAddresses,
            ]
        ]);
    }

    /**
     * Single Address
     */
    public function show($id)
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store not selected or token invalid.'
            ], 400);
        }

        $address = CustomerAddress::where('id', $id)
            ->where('store_id', $storeId)
            ->where('customer_id', auth()->id())
            ->first();

        if (!$address) {
            return response()->json([
                'status' => false,
                'message' => 'Address not found.'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $address
        ]);
    }

    /**
     * Store Address
     */
    public function store(Request $request)
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store not selected or token invalid.'
            ], 400);
        }

        $validated = $request->validate([
            'firstName' => 'required|string|max:100',
            'lastName' => 'required|string|max:100',
            'companyName' => 'nullable|string|max:255',
            'phoneNumber' => 'required|string|max:30',
            'addressLine1' => 'required|string|max:255',
            'addressLine2' => 'nullable|string|max:255',
            'city' => 'required|string|max:100',
            'state' => 'required|string|max:100',
            'zip' => 'required|string|max:30',
            'country' => 'required|string|max:100',
            'is_default' => 'nullable|boolean'
        ]);

        if (!empty($validated['is_default'])) {
            CustomerAddress::where('customer_id', auth()->id())
                ->where('store_id', $storeId)
                ->update([
                    'is_default' => false
                ]);
        }

        $validated['store_id'] = $storeId;
        $validated['customer_id'] = auth()->id();

        $address = CustomerAddress::create($validated);

        return response()->json([
            'status' => true,
            'message' => 'Address created successfully.',
            'data' => $address
        ]);
    }

    /**
     * Update Address
     */
    public function update(Request $request, $id)
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store not selected or token invalid.'
            ], 400);
        }

        $address = CustomerAddress::where('id', $id)
            ->where('customer_id', auth()->id())
            ->where('store_id', $storeId)
            ->first();

        if (!$address) {
            return response()->json([
                'status' => false,
                'message' => 'Address not found.'
            ], 404);
        }

        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'company_name' => 'nullable|string|max:255',
            'phone_number' => 'required|string|max:30',
            'address_line_1' => 'required|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'city' => 'required|string|max:100',
            'state' => 'required|string|max:100',
            'zip' => 'required|string|max:30',
            'country' => 'required|string|max:100',
            'is_default' => 'nullable|boolean'
        ]);

        if (!empty($validated['is_default'])) {
            CustomerAddress::where('customer_id', auth()->id())
                ->where('store_id', $storeId)
                ->update([
                    'is_default' => false
                ]);
        }

        $address->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'Address updated successfully.',
            'data' => $address
        ]);
    }

    /**
     * Delete Address
     */
    public function destroy($id)
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store not selected or token invalid.'
            ], 400);
        }

        $address = CustomerAddress::where('id', $id)
            ->where('customer_id', auth()->id())
            ->where('store_id', $storeId)
            ->first();

        if (!$address) {
            return response()->json([
                'status' => false,
                'message' => 'Address not found.'
            ], 404);
        }

        $address->delete();

        return response()->json([
            'status' => true,
            'message' => 'Address deleted successfully.'
        ]);
    }
}
