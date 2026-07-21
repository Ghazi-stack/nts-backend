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

        $result = [];

        /*
    |--------------------------------------------------------------------------
    | Customer
    |--------------------------------------------------------------------------
    */
        if ($customer) {

            $result[] = [
                'type'                    => 'customer',
                'id'                      => $customer->id,
                'store_id'                => $customer->store_id,
                'first_name'              => $customer->first_name,
                'last_name'               => $customer->last_name,
                'company_name'            => $customer->company_name,
                'email'                   => $customer->email,
                'customer_group'          => $customer->customer_group,
                'phone'                   => $customer->phone,
                'store_credit'            => $customer->store_credit,
                'receive_review_emails'   => (bool)$customer->receive_review_emails,
                'force_password_reset'    => (bool)$customer->force_password_reset,
                'tax_exempt_code'         => $customer->tax_exempt_code,
                'country'                 => $customer->country,
                'address_line_1'          => $customer->address,
                'address_line_2'          => $customer->address_line_2,
                'state'                   => $customer->state,
                'created_at'              => optional($customer->created_at)->toDateTimeString(),
                'updated_at'              => optional($customer->updated_at)->toDateTimeString(),
            ];
        }

        /*
    |--------------------------------------------------------------------------
    | Customer Addresses
    |--------------------------------------------------------------------------
    */
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
                'is_default'       => (bool)$address->is_default,
                'created_at'       => optional($address->created_at)->toDateTimeString(),
                'updated_at'       => optional($address->updated_at)->toDateTimeString(),
            ];
        }

        /*
    |--------------------------------------------------------------------------
    | Saved Addresses
    |--------------------------------------------------------------------------
    */
        foreach ($savedAddresses as $saved) {

            $result[] = [
                'type'       => 'saved_address',
                'id'         => $saved->id,
                'store_id'   => $saved->store_id,
                'order_id'   => $saved->order_id,
                'created_at' => optional($saved->created_at)->toDateTimeString(),
                'updated_at' => optional($saved->updated_at)->toDateTimeString(),
            ];

            // Shipping Address
            if (!empty($saved->shipping_address)) {

                $shipping = is_array($saved->shipping_address)
                    ? $saved->shipping_address
                    : json_decode($saved->shipping_address, true);

                $result[] = [
                    'type'            => 'saved_address_shipping',
                    'id'              => $saved->id,
                    'store_id'        => $saved->store_id,
                    'first_name'      => $shipping['firstName'] ?? null,
                    'last_name'       => $shipping['lastName'] ?? null,
                    'company_name'    => $shipping['companyName'] ?? null,
                    'phone'           => $shipping['phone'] ?? null,
                    'address_line_1'  => $shipping['addressLine1'] ?? null,
                    'address_line_2'  => $shipping['addressLine2'] ?? null,
                    'city'            => $shipping['city'] ?? null,
                    'state'           => $shipping['state'] ?? null,
                    'zip'             => $shipping['zip'] ?? null,
                    'country'         => $shipping['country'] ?? null,
                    'created_at'      => optional($saved->created_at)->toDateTimeString(),
                    'updated_at'      => optional($saved->updated_at)->toDateTimeString(),
                ];
            }

            // Billing Address
            if (!empty($saved->billing_address)) {

                $billing = is_array($saved->billing_address)
                    ? $saved->billing_address
                    : json_decode($saved->billing_address, true);

                $name = explode(' ', $billing['name'] ?? '', 2);

                $result[] = [
                    'type'            => 'saved_address_billing',
                    'id'              => $saved->id,
                    'store_id'        => $saved->store_id,
                    'first_name'      => $name[0] ?? null,
                    'last_name'       => $name[1] ?? null,
                    'company_name'    => null,
                    'email'           => $billing['email'] ?? null,
                    'phone'           => $billing['phone'] ?? null,
                    'address_line_1'  => $billing['addressLine1'] ?? null,
                    'address_line_2'  => $billing['addressLine2'] ?? null,
                    'city'            => $billing['city'] ?? null,
                    'state'           => $billing['state'] ?? null,
                    'zip'             => $billing['zip'] ?? null,
                    'country'         => $billing['country'] ?? null,
                    'created_at'      => optional($saved->created_at)->toDateTimeString(),
                    'updated_at'      => optional($saved->updated_at)->toDateTimeString(),
                ];
            }
        }

        return response()->json([
            'status' => true,
            'data' => [
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
                'status' => false,
                'message' => 'Store not selected or token invalid.'
            ], 400);
        }

        $user = auth('sanctum')->user();

        /*
    |--------------------------------------------------------------------------
    | Customer Address
    |--------------------------------------------------------------------------
    */

        $address = CustomerAddress::where('id', $id)
            ->where('store_id', $storeId)
            ->where('customer_id', $user->id)
            ->first();

        if ($address) {

            return response()->json([
                'status' => true,
                'data' => [
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
                ]
            ]);
        }

        /*
    |--------------------------------------------------------------------------
    | Saved Address
    |--------------------------------------------------------------------------
    */

        $saved = SavedAddress::where('id', $id)
            ->where('store_id', $storeId)
            ->where('user_id', $user->id)
            ->first();

        if ($saved) {

            $response = [];

            // Parent Saved Address
            $response[] = [
                'type'       => 'saved_address',
                'id'         => $saved->id,
                'store_id'   => $saved->store_id,
                'order_id'   => $saved->order_id,
                'created_at' => optional($saved->created_at)->toDateTimeString(),
                'updated_at' => optional($saved->updated_at)->toDateTimeString(),
            ];

            // Shipping
            if (!empty($saved->shipping_address)) {

                $shipping = is_array($saved->shipping_address)
                    ? $saved->shipping_address
                    : json_decode($saved->shipping_address, true);

                $response[] = [
                    'type'            => 'saved_address_shipping',
                    'id'              => $saved->id,
                    'store_id'        => $saved->store_id,
                    'first_name'      => $shipping['firstName'] ?? null,
                    'last_name'       => $shipping['lastName'] ?? null,
                    'company_name'    => $shipping['companyName'] ?? null,
                    'phone'           => $shipping['phone'] ?? null,
                    'address_line_1'  => $shipping['addressLine1'] ?? null,
                    'address_line_2'  => $shipping['addressLine2'] ?? null,
                    'city'            => $shipping['city'] ?? null,
                    'state'           => $shipping['state'] ?? null,
                    'zip'             => $shipping['zip'] ?? null,
                    'country'         => $shipping['country'] ?? null,
                    'created_at'      => optional($saved->created_at)->toDateTimeString(),
                    'updated_at'      => optional($saved->updated_at)->toDateTimeString(),
                ];
            }

            // Billing
            if (!empty($saved->billing_address)) {

                $billing = is_array($saved->billing_address)
                    ? $saved->billing_address
                    : json_decode($saved->billing_address, true);

                $name = explode(' ', $billing['name'] ?? '', 2);

                $response[] = [
                    'type'            => 'saved_address_billing',
                    'id'              => $saved->id,
                    'store_id'        => $saved->store_id,
                    'first_name'      => $name[0] ?? null,
                    'last_name'       => $name[1] ?? null,
                    'company_name'    => null,
                    'email'           => $billing['email'] ?? null,
                    'phone'           => $billing['phone'] ?? null,
                    'address_line_1'  => $billing['addressLine1'] ?? null,
                    'address_line_2'  => $billing['addressLine2'] ?? null,
                    'city'            => $billing['city'] ?? null,
                    'state'           => $billing['state'] ?? null,
                    'zip'             => $billing['zip'] ?? null,
                    'country'         => $billing['country'] ?? null,
                    'created_at'      => optional($saved->created_at)->toDateTimeString(),
                    'updated_at'      => optional($saved->updated_at)->toDateTimeString(),
                ];
            }

            return response()->json([
                'status' => true,
                'data' => $response
            ]);
        }

        return response()->json([
            'status' => false,
            'message' => 'Address not found.'
        ], 404);
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
            'data' => [
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
            ]
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
            'data' => [
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
            ]
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
