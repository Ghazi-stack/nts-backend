<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\ProductSeo;
use App\Models\Redirect;
use Illuminate\Support\Facades\DB;

class HandleProductRedirects
{
    public function handle($request, Closure $next)
    {
        // Tenant resolution must happen before querying tenant models.
        $resolvedStore = $request->attributes->get('resolved_store');
        $tenantStore = $request->attributes->get('tenant_store');

        if (!$resolvedStore || !$tenantStore) {
            return $next($request);
        }

        // Ensure the tenant connection has a selected database.
        $database = config('database.connections.tenant.database');

        if (!is_string($database) || $database === '') {
            return $next($request);
        }

        $path = '/' . trim($request->path(), '/');

        // Normalize trailing slash.
        if (!str_ends_with($path, '/') && !str_contains($path, '.')) {
            $path .= '/';
        }

        $storeId = current_store_id();

        if (!$storeId) {
            return $next($request);
        }

        $clean = rtrim($path, '/');

        $seo = ProductSeo::where('store_id', $storeId)
            ->where(function ($query) use ($path, $clean) {
                $query->where('product_url', $path)
                    ->orWhere('product_url', $clean)
                    ->orWhere('product_url', $clean . '/');
            })
            ->first();

        if ($seo) {
            $redirect = Redirect::where('store_id', $storeId)
                ->where('old_url', $seo->product_url)
                ->where('is_active', true)
                ->first();

            if (
                $redirect &&
                !empty($redirect->new_url) &&
                $redirect->new_url !== $seo->product_url
            ) {
                return redirect($redirect->new_url, 301);
            }
        }

        return $next($request);
    }
}
