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

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'base_url' => ['nullable', 'url', 'max:2048'],

            'store_address' => ['required', 'array'],
            'store_address.line1' => ['required', 'string', 'max:255'],
            'store_address.line2' => ['nullable', 'string', 'max:255'],
            'store_address.city' => ['required', 'string', 'max:100'],
            'store_address.state' => ['required', 'string', 'max:100'],
            'store_address.zip' => ['required', 'string', 'max:20'],
            'store_address.country' => ['required', 'string', 'size:2'],

            'address_type' => [
                'required',
                Rule::in(['home_office', 'business', 'warehouse']),
            ],

            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);

        $validated['store_address']['country'] =
            strtoupper($validated['store_address']['country']);

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
     * Check ownership or store access.
     *
     * Adjust this if your project has a dedicated permission service.
     */
    private function canManageStore(Request $request, Store $store): bool
    {
        $user = $request->user();

        if (!$user) {
            return false;
        }

        // Store owner
        if ((int) $store->user_id === (int) $user->id) {
            return true;
        }

        // Staff access: adapt the permission check to your
        // actual permission slugs and role model.
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
     * Keep the profile response consistent.
     */
    private function profileData(Store $store): array
    {
        return [
            'id' => $store->id,
            'name' => $store->name,
            'base_url' => $store->base_url,
            'store_address' => $store->store_address,
            'address_type' => $store->address_type,
            'email' => $store->email,
            'phone' => $store->phone,
            'created_at' => $store->created_at,
            'updated_at' => $store->updated_at,
        ];
    }
}
