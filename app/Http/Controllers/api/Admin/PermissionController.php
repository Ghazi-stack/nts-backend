<?php

namespace App\Http\Controllers\api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PermissionController extends Controller
{
    // Get all permissions (grouped)
    public function index()
    {
        $permissions = Permission::orderBy('group')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('group')
            ->map(function ($items, $group) {
                return [
                    'group' => $group,
                    'permissions' => $items->map(function ($p) {
                        return [
                            'id'    => $p->id,
                            'name'  => $p->name,
                            'label' => $p->label,
                            'slug'  => $p->slug,
                        ];
                    })->values(),
                ];
            })->values();

        return response()->json([
            'status' => true,
            'data'   => $permissions,
        ]);
    }

    // Get user's permissions
    public function getUserPermissions($userId)
    {
        $storeId = current_store_id();
        $user = User::findOrFail($userId);

        if ($user->userRole == 2) {
            return response()->json([
                'status'  => true,
                'data'    => null,
                'message' => 'Customers do not have permissions.',
            ]);
        }

        $userPermissions = $user->permissions()
            ->where('user_permissions.store_id', $storeId)
            ->orderBy('group')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('group')
            ->map(function ($items, $group) {
                return [
                    'group' => $group,
                    'permissions' => $items->map(function ($p) {
                        return [
                            'id'    => $p->id,
                            'name'  => $p->name,
                            'label' => $p->label,
                            'slug'  => $p->slug,
                        ];
                    })->values(),
                ];
            })->values();

        return response()->json([
            'status' => true,
            'data'   => $userPermissions,
        ]);
    }

    // Assign permissions to user
    public function assignPermissions(Request $request, $userId)
    {
        $request->validate([
            'permissions'   => 'required|array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        $storeId = current_store_id();
        $user = User::findOrFail($userId);

        if ($user->userRole == 2) {
            return response()->json([
                'status'  => false,
                'message' => 'Cannot assign permissions to customers.',
            ], 422);
        }

        // Remove old permissions for this store
        DB::table('user_permissions')
            ->where('user_id', $user->id)
            ->where('store_id', $storeId)
            ->delete();

        // Insert new permissions
        $inserts = [];
        foreach ($request->permissions as $permId) {
            $inserts[] = [
                'user_id'       => $user->id,
                'permission_id' => $permId,
                'store_id'      => $storeId,
                'created_at'    => now(),
                'updated_at'    => now(),
            ];
        }

        DB::table('user_permissions')->insert($inserts);

        return response()->json([
            'status'  => true,
            'message' => 'Permissions assigned successfully.',
        ]);
    }

    // Get my permissions (logged in user)
    
    public function myPermissions(Request $request)
    {
        $user = auth('sanctum')->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ((int) $user->userRole === 2) {
            return response()->json([
                'status' => true,
                'data' => null,
            ]);
        }

        // Use the central registry store ID when the store was resolved
        // by the store.hash middleware.
        $store = $request->attributes->get('resolved_store');

        $storeId = $store
            ? (int) $store->id
            : (int) current_store_id();

        // Permissions are stored in the central database.
        $permissions = $user->permissions()
            ->wherePivot('store_id', $storeId)
            ->orderBy('group')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('group')
            ->map(function ($items, $group) {
                return [
                    'group' => $group,
                    'permissions' => $items->map(function ($p) {
                        return [
                            'id' => $p->id,
                            'name' => $p->name,
                            'label' => $p->label,
                            'slug' => $p->slug,
                        ];
                    })->values(),
                ];
            })->values();

        return response()->json([
            'status' => true,
            'data' => $permissions,
        ]);
    }

}
