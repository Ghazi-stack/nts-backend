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
                'status' => false,
                'message' => 'Store not selected or token invalid.'
            ], 400);
        }

        $user = auth('sanctum')->user();

        $customer = $user->customer_id
            ? Customer::find($user->customer_id)
            : null;

        $customerAddresses = CustomerAddress::where('store_id', $storeId)
            ->where('customer_id', $user->id)
            ->latest()
            ->get();

        $savedAddresses = SavedAddress::where('store_id', $storeId)
            ->where('user_id', $user->id)
            ->latest()
            ->get();

        $customerData = $customer ? [
            'id'                    => $customer->id,
            'store_id'              => $customer->store_id,
            'first_name'            => $customer->first_name,
            'last_name'             => $customer->last_name,
            'company_name'          => $customer->company_name,
            'email'                 => $customer->email,
            'customer_group'        => $customer->customer_group,
            'phone'                 => $customer->phone,
            'store_credit'          => $customer->store_credit,
            'receive_review_emails' => (bool) $customer->receive_review_emails,
            'force_password_reset'  => (bool) $customer->force_password_reset,
            'tax_exempt_code'       => $customer->tax_exempt_code,
            'country'               => $customer->country,
            'address_line_1'        => $customer->address,
            'address_line_2'        => $customer->address_line_2,
            'state'                 => $customer->state,
            'created_at'            => $customer->created_at?->toDateTimeString(),
            'updated_at'            => $customer->updated_at?->toDateTimeString(),
        ] : [];

        $result = [];

        // Customer addresses
        foreach ($customerAddresses as $address) {
            $data = $customerData;
            $data['type'] = 'customer_address';
            $data['addresses'] = [[
                'id'             => $address->id,
                'store_id'       => $address->store_id,
                'customer_id'    => $address->customer_id,
                'first_name'     => $address->first_name,
                'last_name'      => $address->last_name,
                'company_name'   => $address->company_name,
                'phone_number'   => $address->phone_number,
                'address_line_1' => $address->address_line_1,
                'address_line_2' => $address->address_line_2,
                'city'           => $address->city,
                'state'          => $address->state,
                'zip'            => $address->zip,
                'country'        => $address->country,
                'is_default'     => (bool) $address->is_default,
                'created_at'     => $address->created_at?->toDateTimeString(),
                'updated_at'     => $address->updated_at?->toDateTimeString(),
            ]];
            $result[] = $data;
        }

        // Saved addresses
        foreach ($savedAddresses as $saved) {
            $data = $customerData;
            $data['type'] = 'saved_address';
            $data['order_id'] = $saved->order_id;
            $data['saved_address_id'] = $saved->id;
            $data['shipping_address'] = $saved->shipping_address;
            $data['billing_address'] = $saved->billing_address;
            $result[] = $data;
        }

        return response()->json([
            'status' => true,
            'data' => [
                'customer_addresses' => $result,
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
