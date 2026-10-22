<?php

namespace App\Http\Middleware;

use App\Services\TenantCredentialResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ResolveDashboardTenant
{
    public function __construct(
        private TenantCredentialResolver $resolver
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = auth('sanctum')->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticateds.',
            ], 401);
        }

        // Customers cannot access dashboard resources.
        if ((int) $user->userRole === 2) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized dashboard access.',
            ], 403);
        }

        // Resolve the store using the preceding store.hash middleware.
        $store = $request->attributes->get('resolved_store');

        if (!$store || empty($store->store_hash) || empty($store->id)) {
            return response()->json([
                'success' => false,
                'message' => 'A valid X-Store-Hash header is required.',
            ], 400);
        }

        try {
            // Verify ownership in the central registry.
            $isOwner = DB::connection('central')
                ->table('stores')
                ->where('id', $store->id)
                ->where('user_id', $user->id)
                ->exists();

            // Verify staff membership using the central store ID.
            $isMember = DB::connection('central')
                ->table('store_user')
                ->where('store_id', $store->id)
                ->where('user_id', $user->id)
                ->exists();

            if (!$isOwner && !$isMember) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have access to this store.',
                ], 403);
            }

            // Connect using credentials stored in the central registry.
            $connectedStore = $this->resolver->connectByStoreHash(
                $store->store_hash
            );

            // Expose the trusted store context to downstream middleware/controllers.
            $request->attributes->set('resolved_store', $connectedStore);
            $request->attributes->set(
                'store_hash',
                $connectedStore->store_hash
            );
            $request->attributes->set('tenant_store', $connectedStore);

            return $next($request);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Unable to process the tenant request.',
            ], 503);
        } finally {
            // Always release the tenant connection after the request.
            $this->resolver->disconnect();
        }
    }
}
