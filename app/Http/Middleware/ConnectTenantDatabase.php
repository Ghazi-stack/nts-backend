<?php

namespace App\Http\Middleware;

use App\Services\TenantCredentialResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ConnectTenantDatabase
{
    public function __construct(
        private TenantCredentialResolver $resolver
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // The preceding store.hash middleware must validate the hash.
        $storeHash = $request->attributes->get('store_hash');

        if (!is_string($storeHash) || $storeHash === '') {
            return response()->json([
                'success' => false,
                'message' => 'Store resolution is required.',
            ], 400);
        }

        try {
            $store = $this->resolver->connectByStoreHash($storeHash);

            $request->attributes->set('tenant_store', $store);

            try {
                return $next($request);
            } finally {
                // Runs after the controller, even if it throws.
                $this->resolver->disconnect();
            }
        } catch (InvalidArgumentException $e) {
            $this->resolver->disconnect();

            return response()->json([
                'success' => false,
                'message' => 'Invalid tenant configuration.',
            ], 400);
        } catch (RuntimeException $e) {
            $this->resolver->disconnect();

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Tenant database is not available.',
            ], 503);
        } catch (Throwable $e) {
            $this->resolver->disconnect();

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Unable to process the tenant request.',
            ], 500);
        }
    }
}
