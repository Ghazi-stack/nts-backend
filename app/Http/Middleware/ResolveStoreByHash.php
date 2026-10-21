<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ResolveStoreByHash
{
    public function handle(Request $request, Closure $next): Response
    {
        $storeHash = $request->header('X-Store-Hash');

        // Require the new store identifier.
        if (!is_string($storeHash) || $storeHash === '') {
            return response()->json([
                'success' => false,
                'message' => 'The store-hash header is required.',
            ], 400);
        }

        // Expected format: store- followed by 12 lowercase alphanumeric characters.
        if (!preg_match('/^store-[a-z0-9]{12}$/', $storeHash)) {
            return response()->json([
                'success' => false,
                'message' => 'The store-hash format is invalid.',
            ], 400);
        }

        try {
            // Always resolve the store through the central registry.
            $store = DB::connection('central')
                ->table('stores')
                ->where('store_hash', $storeHash)
                ->first();
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Unable to resolve the store.',
            ], 500);
        }

        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found.',
            ], 404);
        }

        // Make the trusted registry record available to downstream code.
        $request->attributes->set('resolved_store', $store);
        $request->attributes->set('store_hash', $store->store_hash);

        return $next($request);
    }
}
