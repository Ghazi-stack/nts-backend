<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LogAdminLogoutRequest
{
    public function handle(Request $request, Closure $next)
    {
        file_put_contents(
            storage_path('logs/admin-logout-debug.log'),
            now()->toDateTimeString()
                . ' | '
                . $request->method()
                . ' | '
                . $request->fullUrl()
                . PHP_EOL,
            FILE_APPEND
        );

        return $next($request);
    }
}