<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\CustomerAddress;
use App\Models\SavedAddress;
use App\Models\Customer;
use App\Http\Resources\CustomerResource;
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
                'status'  => false,
                'message' => 'Store not selected or token invalid.'
            ], 400);
        }

        $user = auth('sanctum')->user() ?? auth()->user();

        $query = CustomerAddress::where('store_id', $storeId);

        // Customer (userRole = 2) → sirf apne addresses
        // Admin / Sub-user (userRole = 1) → customer_id optional (query param se)
        if ($user && (int) $user->userRole === 2) {
            $query->where('customer_id', $user->id);
        } else {
            // Admin side: specific customer ke addresses chahiye to ?customer_id=123
            if ($request->filled('customer_id')) {
                $query->where('customer_id', $request->customer_id);
            }
        }

        $customerAddresses = $query->latest()->get();

        $result = [];

        foreach ($customerAddresses as $address) {
            $result[] = [
                'type'             => 'customer_address',
                'id'               => $address->id,
                'store_id'         => $address->store_id,
                'customer_id'      => $address->customer_id,
                'first_name'       => $address->first_name,
                'last_name'        => $address->last_name,
                'company_name'     => $address->company_name,
                'phone_number'     => $address->phone_number,
                'address_line_1'   => $address->address_line_1,
                'address_line_2'   => $address->address_line_2,
                'city'             => $address->city,
                'state'            => $address->state,
                'zip'              => $address->zip,
                'country'          => $address->country,
                'is_default'       => (bool) $address->is_default,
                'created_at'       => optional($address->created_at)->toDateTimeString(),
                'updated_at'       => optional($address->updated_at)->toDateTimeString(),
            ];
        }

        return response()->json([
            'status' => true,
            'data'   => [
                'customer_addresses' => $result
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
                'status'  => false,
                'message' => 'Store not selected or token invalid.'
            ], 400);
        }

        $user = auth('sanctum')->user() ?? auth()->user();

        $query = CustomerAddress::where('id', $id)
            ->where('store_id', $storeId);

        // Sirf customer login ho to apna address hi dekh sakta hai
        // Admin/Sub-user (userRole = 1) ko customer_id check nahi lagega
        if ($user && (int) $user->userRole === 2) {
            $query->where('customer_id', $user->id);
        }

        $address = $query->first();

        if (!$address) {
            return response()->json([
                'status'  => false,
                'message' => 'Address not found.'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data'   => $address
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
            'is_default' => 'nullable|boolean',
        ]);

        if (!empty($validated['is_default'])) {
            CustomerAddress::where('customer_id', auth()->id())
                ->where('store_id', $storeId)
                ->update([
                    'is_default' => false
                ]);
        }

        $data = [
            'store_id'       => $storeId,
            'customer_id'    => auth()->id(),
            'first_name'     => $validated['firstName'],
            'last_name'      => $validated['lastName'],
            'company_name'   => $validated['companyName'] ?? null,
            'phone_number'   => $validated['phoneNumber'],
            'address_line_1' => $validated['addressLine1'],
            'address_line_2' => $validated['addressLine2'] ?? null,
            'city'           => $validated['city'],
            'state'          => $validated['state'],
            'zip'            => $validated['zip'],
            'country'        => $validated['country'],
            'is_default'     => $validated['is_default'] ?? false,
        ];

        $address = CustomerAddress::create($data);

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
