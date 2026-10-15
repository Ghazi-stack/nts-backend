<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StoreProfileController extends Controller
{
    /**
     * Get the selected store's profile.
     */
    public function show(Request $request)
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store ID is required.',
            ], 422);
        }

        $store = Store::find($storeId);

        if (!$store) {
            return response()->json([
                'status' => false,
                'message' => 'Store not found.',
            ], 404);
        }

        if (!$this->canManageStore($request, $store)) {
            return response()->json([
                'status' => false,
                'message' => 'You do not have permission to manage this store.',
            ], 403);
        }

        return response()->json([
            'status' => true,
            'message' => 'Store profile retrieved successfully.',
            'data' => $this->profileData($store),
        ]);
    }

    /**
     * Update the selected store's profile.
     *
     * The frontend submits store_address as one multiline string.
     * Laravel parses it and saves the components in the existing
     * store_address JSON column.
     */
    
    public function update(Request $request)
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store ID is required.',
            ], 422);
        }

        $store = Store::find($storeId);

        if (!$store) {
            return response()->json([
                'status' => false,
                'message' => 'Store not found.',
            ], 404);
        }

        if (!$this->canManageStore($request, $store)) {
            return response()->json([
                'status' => false,
                'message' => 'You do not have permission to manage this store.',
            ], 403);
        }

        // Normalize address type before validation.
        $addressType = $request->input('address_type');

        if (is_string($addressType)) {
            $normalizedType = strtolower(trim($addressType));

            $addressTypeMap = [
                'home office' => 'Home Office',
                'home_office' => 'Home Office',
                'commercial office' => 'Commercial Office',
                'commercial_office' => 'Commercial Office',
                'retail' => 'Retail',
                'warehouse' => 'Warehouse',
            ];

            $request->merge([
                'address_type' => $addressTypeMap[$normalizedType]
                    ?? $addressType,
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'base_url' => ['nullable', 'url', 'max:2048'],

            // One complete multiline address string.
            'store_address' => ['required', 'string', 'max:1000'],

            'address_type' => [
                'required',
                Rule::in([
                    'Home Office',
                    'Commercial Office',
                    'Retail',
                    'Warehouse',
                ]),
            ],

            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);

        // Preserve the full address exactly as submitted,
        // except for leading and trailing whitespace.
        $fullAddress = trim($validated['store_address']);

        // Save the original address string separately.
        $validated['store_address_full'] = $fullAddress;

        // Save structured address data in the existing JSON column.
        $validated['store_address'] = $this->parseStoreAddress(
            $fullAddress
        );

        // Store a normalized address type in the database.
        $addressTypes = [
            'Home Office' => 'home_office',
            'Commercial Office' => 'commercial_office',
            'Retail' => 'retail',
            'Warehouse' => 'warehouse',
        ];

        $validated['address_type'] =
            $addressTypes[$validated['address_type']];

        // Normalize an omitted or null phone to an empty string.
        $validated['phone'] = $validated['phone'] ?? '';

        $store->fill($validated);
        $store->save();
        $store->refresh();

        return response()->json([
            'status' => true,
            'message' => 'Store profile updated successfully.',
            'data' => $this->profileData($store),
        ]);
    }


    /**
     * Check store ownership or staff access.
     */
    private function canManageStore(Request $request, Store $store): bool
    {
        $user = $request->user();

        if (!$user) {
            return false;
        }

        // Store owner.
        if ((int) $store->user_id === (int) $user->id) {
            return true;
        }

        // Staff access.
        return DB::table('user_permissions')
            ->where('user_id', $user->id)
            ->where('store_id', $store->id)
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('permissions')
                    ->whereColumn(
                        'permissions.id',
                        'user_permissions.permission_id'
                    )
                    ->whereIn('permissions.slug', [
                        'manage_store_profile',
                        'manage_settings',
                    ]);
            })
            ->exists();
    }

    /**
     * Parse a multiline US-style address.
     *
     * Example:
     * Address:
     * 2210 Goldsmith Lane
     * Ste 126-5001
     * Louisville, KY 40218
     * US
     *
     * International address formats may require country-specific parsing.
     */
    private function parseStoreAddress(string $address): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($address));

        $lines = array_values(array_filter(
            array_map('trim', $lines),
            fn ($line) => $line !== ''
        ));

        // Remove an optional "Address:" heading.
        if (
            !empty($lines) &&
            strtolower(rtrim($lines[0], ':')) === 'address'
        ) {
            array_shift($lines);
        }

        $result = [
            'line1' => $lines[0] ?? '',
            'line2' => '',
            'city' => '',
            'state' => '',
            'zip' => '',
            'country' => '',
        ];

        if (empty($lines)) {
            return $result;
        }

        // Recognize common US country labels when provided.
        $lastLine = trim(end($lines));

        $countryCodes = [
            'US' => 'US',
            'USA' => 'US',
            'UNITED STATES' => 'US',
            'UNITED STATES OF AMERICA' => 'US',
        ];

        if (isset($countryCodes[strtoupper($lastLine)])) {
            $result['country'] = $countryCodes[strtoupper($lastLine)];
            array_pop($lines);
        }

        // Parse the final location line, e.g. Louisville, KY 40218.
        if (!empty($lines)) {
            $locationLine = trim(end($lines));

            if (preg_match(
                '/^(.+?),\s*([A-Z]{2})\s+(\d{5}(?:-\d{4})?)$/i',
                $locationLine,
                $matches
            )) {
                $result['city'] = trim($matches[1]);
                $result['state'] = strtoupper($matches[2]);
                $result['zip'] = $matches[3];

                array_pop($lines);

                $result['line1'] = $lines[0] ?? '';

                if (count($lines) > 1) {
                    $result['line2'] = implode(
                        ', ',
                        array_slice($lines, 1)
                    );
                }
            } else {
                // Keep unrecognized lines rather than guessing their meaning.
                $result['line1'] = $lines[0] ?? '';

                if (count($lines) > 1) {
                    $result['line2'] = implode(
                        ', ',
                        array_slice($lines, 1)
                    );
                }
            }
        }

        return $result;
    }

    /**
     * Format an address for the single frontend address field.
     *
     * Existing records without full_address are formatted from
     * their structured address fields.
     */
    private function formatStoreAddress(array $address): string
    {
        $streetLines = array_filter([
            $address['line1'] ?? '',
            $address['line2'] ?? '',
        ], fn ($line) => trim((string) $line) !== '');

        $cityStateZip = trim(implode(', ', array_filter([
            $address['city'] ?? '',
            trim(
                ($address['state'] ?? '') .
                ' ' .
                ($address['zip'] ?? '')
            ),
        ], fn ($value) => trim((string) $value) !== '')));

        $lines = array_merge(
            $streetLines,
            $cityStateZip !== '' ? [$cityStateZip] : [],
            !empty($address['country']) ? [$address['country']] : []
        );

        return implode("\n", $lines);
    }

    /**
     * Keep the profile response consistent with BigCommerce-style fields.
     */
    private function profileData(Store $store): array
    {
        $addressTypes = [
            'home_office' => 'Home Office',
            'commercial_office' => 'Commercial Office',
            'retail' => 'Retail',
            'warehouse' => 'Warehouse',
        ];

        $address = $store->store_address ?? [];

        if (is_string($address)) {
            // Handle legacy records stored as a plain string.
            $address = [
                'full_address' => $address,
            ];
        }

        if (!is_array($address)) {
            $address = [];
        }

        $fullAddress = $address['full_address']
            ?? $this->formatStoreAddress($address);

        return [
            'id' => $store->id,
            'store_name' => $store->name,
            'base_url' => $store->base_url,

            // Single multiline string for the frontend.
            'store_address' => $store->store_address_full
            ?? $this->formatStoreAddress($store->store_address ?? []),

            // Structured components for shipping/tax integrations.
            'store_address_details' => $address,

            'store_address_type' => $addressTypes[$store->address_type]
                ?? $store->address_type,

            'store_email' => $store->email,
            'store_phone' => $store->phone ?? '',

            'created_at' => $store->created_at,
            'updated_at' => $store->updated_at,
        ];
    }
}
