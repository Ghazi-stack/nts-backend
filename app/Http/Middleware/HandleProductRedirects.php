<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\ProductSeo;
use App\Models\Redirect;

class HandleProductRedirects
{
    public function handle($request, Closure $next)
    {
        $path = '/' . trim($request->path(), '/');

        // Trailing slash normalize
        if (!str_ends_with($path, '/') && !str_contains($path, '.')) {
            $path .= '/';
        }

        // Store ID resolve karo (apna logic)
        $storeId = current_store_id(); // ya jo aapka helper hai

        if (!$storeId) {
            return $next($request);
        }

        // Pehle check karo ke yeh path product_seo.product_url mein hai ya nahi
        $seo = ProductSeo::where('store_id', $storeId)
            ->where(function ($q) use ($path) {
                $clean = rtrim($path, '/');
                $q->where('product_url', $path)
                    ->orWhere('product_url', $clean)
                    ->orWhere('product_url', $clean . '/');
            })
            ->first();

        if ($seo) {
            // Agar product_seo mein mila to redirects table check karo
            $redirect = Redirect::where('store_id', $storeId)
                ->where('old_url', $seo->product_url)
                ->where('is_active', true)
                ->first();

            if ($redirect && !empty($redirect->new_url) && $redirect->new_url !== $seo->product_url) {
                return redirect($redirect->new_url, 301);
            }
        }

        return $next($request);
    }
}
