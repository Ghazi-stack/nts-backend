<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckPermission
{
    
    public function handle(Request $request, Closure $next, ...$names)
    {
        $user = auth('sanctum')->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Customers cannot access dashboard resources.
        if ((int) $user->userRole === 2) {
            return response()->json([
                'status' => false,
                'message' => 'You do not have access to this resource.',
            ], 403);
        }

        // Prefer the trusted central registry record resolved by middleware.
        $store = $request->attributes->get('tenant_store')
            ?? $request->attributes->get('resolved_store');

        if (!$store || !isset($store->id)) {
            return response()->json([
                'status' => false,
                'message' => 'Store context is required.',
            ], 400);
        }

        $hasPermission = $user->permissions()
            ->whereIn('permissions.name', $names)
            ->wherePivot('store_id', (int) $store->id)
            ->exists();

        if (!$hasPermission) {
            return response()->json([
                'status' => false,
                'message' => 'You do not have permission to access this resource.',
            ], 403);
        }

        return $next($request);
    }

}
