<?php

namespace App\Http\Middleware;

use App\Services\TenantCredentialResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
        Log::info('ConnectTenantDatabase entered', [
            'path' => $request->path(),
            'store_hash_present' => $request->attributes->has('store_hash'),
            'resolved_store_present' => $request->attributes->has('resolved_store'),
        ]);

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
        $tenantConnected = false;
        $cleanupScheduled = false;

        try {
            Log::info('Tenant connection starting', [
                'path' => $request->path(),
            ]);

            $store = $this->resolver->connectByStoreHash($storeHash);
            $tenantConnected = true;

            $request->attributes->set('tenant_store', $store);

            DB::setDefaultConnection('tenant');

            Log::info('Tenant connection successful', [
                'path' => $request->path(),
                'registry_id' => $store->id ?? null,
                'legacy_store_id' => $store->legacy_store_id ?? null,
                'tenant_database' => DB::connection('tenant')->getDatabaseName(),
                'tenant_store_present' => true,
            ]);

            $response = $next($request);

            Log::info('Tenant middleware downstream response', [
                'path' => $request->path(),
                'status' => $response->getStatusCode(),
                'streamed' => $response instanceof StreamedResponse,
            ]);

            if ($response instanceof StreamedResponse) {
                $originalCallback = $response->getCallback();

                $response->setCallback(function () use (
                    $originalCallback,
                    $previousConnection,
                    $request
                ) {
                    try {
                        if ($originalCallback !== null) {
                            $originalCallback();
                        }
                    } finally {
                        Log::info('Tenant stream callback finished', [
                            'path' => $request->path(),
                        ]);

                        DB::setDefaultConnection($previousConnection);

                        try {
                            $this->resolver->disconnect();

                            Log::info('Tenant stream cleanup completed', [
                                'path' => $request->path(),
                            ]);
                        } catch (Throwable $cleanupError) {
                            Log::error('Tenant stream cleanup failed', [
                                'path' => $request->path(),
                                'message' => $cleanupError->getMessage(),
                            ]);

                            report($cleanupError);
                        }
                    }
                });

                $cleanupScheduled = true;

                return $response;
            }

            return $response;
        } catch (InvalidArgumentException $e) {
            Log::error('Tenant configuration rejected', [
                'path' => $request->path(),
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Invalid tenant configuration.',
            ], 400);
        } catch (RuntimeException $e) {
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
        } finally {
            if ($tenantConnected && !$cleanupScheduled) {
                DB::setDefaultConnection($previousConnection);

                try {
                    $this->resolver->disconnect();

                    Log::info('Tenant disconnection completed', [
                        'path' => $request->path(),
                    ]);
                } catch (Throwable $cleanupError) {
                    Log::error('Tenant disconnection failed', [
                        'path' => $request->path(),
                        'message' => $cleanupError->getMessage(),
                    ]);

                    report($cleanupError);
                }
            }
        }
    }
}