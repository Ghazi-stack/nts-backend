<?php

namespace App\Http\Controllers\API\FedEx;

use App\Http\Controllers\Controller;
use App\Models\StoreShippingAddress;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class StoreShippingAddressController extends Controller
{
    /**
     * GET /store-shipping-address/get-address
     *
     * Current pickup/origin address return karti hai
     * Screenshot 1 jaisa — address card dikhega with Edit button
     */
    public function index(): JsonResponse
    {
        $address = StoreShippingAddress::getActive();

        return response()->json([
            'success' => true,
            'data'    => $address,
        ]);
    }

    /**
     * POST /store-shipping-address/save-address
     *
     * Pickup address create ya update karti hai
     * Screenshot 2 jaisa — "Configure shipping address" modal ka Submit button
     *
     * Sirf ek address hoga — agar pehle se hai to update, nahi hai to create
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'address_line_1' => 'required|string|max:255',
            'address_line_2' => 'sometimes|nullable|string|max:255',
            'city'           => 'required|string|max:255',
            'postal_code'    => 'required|string|max:20',
            'country'        => 'sometimes|string|max:255',
            'country_code'   => 'sometimes|string|max:5',
            'state'          => 'sometimes|nullable|string|max:255',
            'state_code'     => 'sometimes|nullable|string|max:5',
            'phone_number'   => 'sometimes|nullable|string|max:20',
        ]);

        // Map country name to code if not provided
        if (!empty($validated['country']) && empty($validated['country_code'])) {
            $validated['country_code'] = $this->mapCountryCode($validated['country']);
        }

        // Map state name to code if not provided
        if (!empty($validated['state']) && empty($validated['state_code'])) {
            $validated['state_code'] = $this->mapStateCode($validated['state']);
        }

        // Sirf ek address — update or create
        $address = StoreShippingAddress::first();

        if ($address) {
            $address->update($validated);
            $message = 'Shipping address updated successfully.';
        } else {
            $validated['is_active'] = true;
            $address = StoreShippingAddress::create($validated);
            $message = 'Shipping address created successfully.';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $address->fresh(),
        ]);
    }

    /**
     * Map common country names to ISO codes
     */
    private function mapCountryCode(string $country): string
    {
        $map = [
            'United States' => 'US',
            'Canada'        => 'CA',
            'Mexico'        => 'MX',
            'United Kingdom'=> 'GB',
            'Australia'     => 'AU',
            'Germany'       => 'DE',
            'France'        => 'FR',
            'India'         => 'IN',
            'Pakistan'      => 'PK',
            'China'         => 'CN',
            'Japan'         => 'JP',
        ];

        return $map[$country] ?? 'US';
    }

    /**
     * Map US state names to codes
     */
    private function mapStateCode(string $state): string
    {
        $map = [
            'Alabama' => 'AL', 'Alaska' => 'AK', 'Arizona' => 'AZ', 'Arkansas' => 'AR',
            'California' => 'CA', 'Colorado' => 'CO', 'Connecticut' => 'CT', 'Delaware' => 'DE',
            'Florida' => 'FL', 'Georgia' => 'GA', 'Hawaii' => 'HI', 'Idaho' => 'ID',
            'Illinois' => 'IL', 'Indiana' => 'IN', 'Iowa' => 'IA', 'Kansas' => 'KS',
            'Kentucky' => 'KY', 'Louisiana' => 'LA', 'Maine' => 'ME', 'Maryland' => 'MD',
            'Massachusetts' => 'MA', 'Michigan' => 'MI', 'Minnesota' => 'MN', 'Mississippi' => 'MS',
            'Missouri' => 'MO', 'Montana' => 'MT', 'Nebraska' => 'NE', 'Nevada' => 'NV',
            'New Hampshire' => 'NH', 'New Jersey' => 'NJ', 'New Mexico' => 'NM', 'New York' => 'NY',
            'North Carolina' => 'NC', 'North Dakota' => 'ND', 'Ohio' => 'OH', 'Oklahoma' => 'OK',
            'Oregon' => 'OR', 'Pennsylvania' => 'PA', 'Rhode Island' => 'RI', 'South Carolina' => 'SC',
            'South Dakota' => 'SD', 'Tennessee' => 'TN', 'Texas' => 'TX', 'Utah' => 'UT',
            'Vermont' => 'VT', 'Virginia' => 'VA', 'Washington' => 'WA', 'West Virginia' => 'WV',
            'Wisconsin' => 'WI', 'Wyoming' => 'WY',
        ];

        return $map[$state] ?? $state;
    }
}
