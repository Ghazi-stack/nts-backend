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
                'status'  => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Customer (userRole 2) — no dashboard access
        if ($user->userRole == 2) {
            return response()->json([
                'status'  => false,
                'message' => 'You do not have access to this resource.',
            ], 403);
        }


        // Check specific permissions by name
        $hasPermission = $user->permissions()
            ->whereIn('permissions.name', $names)
           ->where('user_permissions.store_id', current_store_id() ?? $user->store_id)
            ->exists();

        if (!$hasPermission) {
            return response()->json([
                'status'  => false,
                'message' => 'You do not have permission to access this resource.',
            ], 403);
        }

        return $next($request);
    }
}
