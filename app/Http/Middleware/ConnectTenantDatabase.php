<?php

namespace App\Http\Middleware;

use App\Services\TenantCredentialResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConnectTenantDatabase
{
    public function __construct(
        private TenantCredentialResolver $resolver
    ) {}

    public function handle(Request $request, Closure $next): Response
    {

        Log::info('ConnectTenantDatabase entered', [
            'path' => $request->path(),
            'store_hash_present' =>
                $request->attributes->has('store_hash'),
            'resolved_store_present' =>
                $request->attributes->has('resolved_store'),
        ]);
        /*
        |--------------------------------------------------------------------------
        | 1. Middleware started
        |--------------------------------------------------------------------------
        */

        Log::info('Tenant middleware started', [
            'path' => $request->path(),
            'method' => $request->method(),
            'store_hash_present' => is_string(
                $request->attributes->get('store_hash')
            ),
            'resolved_store_present' =>
                $request->attributes->get('resolved_store') !== null,
            'tenant_store_present' =>
                $request->attributes->get('tenant_store') !== null,
        ]);

        // The preceding store.hash middleware must validate the hash.
        $storeHash = $request->attributes->get('store_hash');

        if (!is_string($storeHash) || $storeHash === '') {
            Log::warning('Tenant middleware rejected request', [
                'reason' => 'store_hash_missing',
                'path' => $request->path(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Store resolution is required.',
            ], 400);
        }

        $previousConnection = DB::getDefaultConnection();

        try {
            /*
            |--------------------------------------------------------------------------
            | 2. Connect to tenant
            |--------------------------------------------------------------------------
            */

            Log::info('Tenant connection starting', [
                'path' => $request->path(),
            ]);

            $store = $this->resolver->connectByStoreHash($storeHash);

            $request->attributes->set('tenant_store', $store);
            DB::setDefaultConnection('tenant');

            Log::info('Tenant connection successful', [
                'path' => $request->path(),
                'registry_id' => $store->id ?? null,
                'legacy_store_id' => $store->legacy_store_id ?? null,
                'tenant_database' => DB::connection('tenant')->getDatabaseName(),
                'tenant_store_present' => true,
            ]);

            /*
            |--------------------------------------------------------------------------
            | 3. Run authentication and controller
            |--------------------------------------------------------------------------
            */

            // try {
            //     $response = $next($request);

            //     Log::info('Tenant middleware downstream response', [
            //         'path' => $request->path(),
            //         'status' => $response->getStatusCode(),
            //     ]);

            //     return $response;
            // } finally {
            //     /*
            //     |--------------------------------------------------------------------------
            //     | 4. Disconnect after request
            //     |--------------------------------------------------------------------------
            //     */

            //     Log::info('Tenant disconnection starting', [
            //         'path' => $request->path(),
            //     ]);

            //     DB::setDefaultConnection($previousConnection);
            //     $this->resolver->disconnect();

            //     Log::info('Tenant disconnection completed', [
            //         'path' => $request->path(),
            //     ]);
            // }

            try {
                $response = $next($request);

                Log::info('Tenant middleware downstream response', [
                    'path' => $request->path(),
                    'status' => $response->getStatusCode(),
                    'streamed' => $response instanceof StreamedResponse,
                ]);

                if ($response instanceof StreamedResponse) {
                    // Keep tenant DB connected until the streamed response finishes.
                    $originalCallback = $response->getCallback();

                    $response->setCallback(function () use (
                        $originalCallback,
                        $previousConnection,
                        $request
                    ) {
                        try {
                            $originalCallback();
                        } finally {
                            Log::info('Tenant stream completed; disconnecting tenant', [
                                'path' => $request->path(),
                            ]);

                            DB::setDefaultConnection($previousConnection);
                            $this->resolver->disconnect();
                        }
                    });

                    return $response;
                }

                return $response;
            } finally {
                // Streamed responses clean up after their callback completes.
                if (!isset($response) || !($response instanceof StreamedResponse)) {
                    Log::info('Tenant disconnection starting', [
                        'path' => $request->path(),
                    ]);

                    DB::setDefaultConnection($previousConnection);
                    $this->resolver->disconnect();

                    Log::info('Tenant disconnection completed', [
                        'path' => $request->path(),
                    ]);
                }
            }
        } catch (InvalidArgumentException $e) {
            DB::setDefaultConnection($previousConnection);
            $this->resolver->disconnect();

            Log::error('Tenant configuration rejected', [
                'path' => $request->path(),
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid tenant configuration.',
            ], 400);
        } catch (RuntimeException $e) {
            DB::setDefaultConnection($previousConnection);
            $this->resolver->disconnect();

            Log::error('Tenant database unavailable', [
                'path' => $request->path(),
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Tenant database is not available.',
            ], 503);
        } catch (Throwable $e) {
            DB::setDefaultConnection($previousConnection);
            $this->resolver->disconnect();

            Log::error('Tenant request failed', [
                'path' => $request->path(),
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Unable to process the tenant request.',
            ], 500);
        }
    }
}
