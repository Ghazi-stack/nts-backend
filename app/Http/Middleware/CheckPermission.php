<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckPermission
{
    public function handle(Request $request, Closure $next, ...$slugs)
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

        // Check specific permissions for userRole 1
        if (!$user->hasAnyPermission($slugs)) {
            return response()->json([
                'status'  => false,
                'message' => 'You do not have permission to access this resource.',
            ], 403);
        }

        return $next($request);
    }
}