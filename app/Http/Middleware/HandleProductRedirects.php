<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\ProductSeo;
use App\Models\Redirect;

class HandleProductRedirects
{
    public function handle($request, Closure $next)
    {
        // API routes skip karo
        if ($request->is('api/*')) {
            return $next($request);
        }

        // Tenant resolution check
        $resolvedStore = $request->attributes->get('resolved_store');
        $tenantStore = $request->attributes->get('tenant_store');

        if (!$resolvedStore || !$tenantStore) {
            return $next($request);
        }

        // Database selected hai ya nahi
        $database = config('database.connections.tenant.database');

        if (!is_string($database) || $database === '') {
            return $next($request);
        }

        $path = '/' . trim($request->path(), '/');

        // Trailing slash normalize
        if (!str_ends_with($path, '/') && !str_contains($path, '.')) {
            $path .= '/';
        }

        $storeId = current_store_id();

        if (!$storeId) {
            return $next($request);
        }

        $clean = rtrim($path, '/');

        // product_seo check
        $seo = ProductSeo::where('store_id', $storeId)
            ->where(function ($query) use ($path, $clean) {
                $query->where('product_url', $path)
                    ->orWhere('product_url', $clean)
                    ->orWhere('product_url', $clean . '/');
            })
            ->first();

        if ($seo) {
            // redirects table check
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
