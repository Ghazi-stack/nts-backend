<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LogAdminLogoutRequest
{
    public function handle(Request $request, Closure $next)
    {
        Log::info('Admin logout request received', [
            'path' => $request->path(),
            'method' => $request->method(),
            'authorization_present' => $request->bearerToken() !== null,
        ]);

        return $next($request);
    }
}