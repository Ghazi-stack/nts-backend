<?php

namespace App\Http\Controllers\api\Web;

use App\Http\Controllers\Controller;
use App\Models\VisitorSession;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VisitorSessionController extends Controller
{
    public function store(Request $request)
    {
        try {
            $storeId = current_store_id();

            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.',
                ], 400);
            }

            $request->validate([
                'sessionId' => 'required|string|max:255',
                'referrer'  => 'nullable|string|max:2048',
            ]);

            $session = VisitorSession::where('store_id', $storeId)
                ->where('session_id', $request->sessionId)
                ->first();

            if ($session) {
                $session->touch();

                return response()->json([
                    'status'  => true,
                    'message' => 'Session already exists.',
                    'data'    => [
                        'source' => $session->source,
                        'label'  => $session->label,
                        'host'   => $session->host,
                    ],
                ]);
            }

            $meta = $this->sourceMeta($request->input('referrer'));

            $session = VisitorSession::create([
                'store_id'   => $storeId,
                'session_id' => $request->sessionId,
                'source'     => $meta['source'],
                'label'      => $meta['label'],
                'referrer'   => $meta['referrer'],
                'host'       => $meta['host'],
            ]);

            event(new \App\Events\RealtimeStatsUpdated((int) $storeId));

            return response()->json([
                'status'  => true,
                'message' => 'Session stored successfully.',
                'data'    => [
                    'source' => $session->source,
                    'label'  => $session->label,
                    'host'   => $session->host,
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (UniqueConstraintViolationException $e) {
            return response()->json([
                'status'  => true,
                'message' => 'Session already exists.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong',
            ], 500);
        }
    }

    public function stats()
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status'  => false,
                'message' => 'Store not selected or token invalid.',
            ], 400);
        }

        $from = now()->subHours(24);

        $query = VisitorSession::query()
            ->where('store_id', $storeId)
            ->where('created_at', '>=', $from);

        $latestActivity = (clone $query)
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'source', 'label', 'host', 'created_at'])
            ->map(fn($row) => [
                'id'        => $row->id,
                'type'      => 'visit',
                'source'    => $row->source ?: 'direct',
                'label'     => $row->label ?: 'Visit from direct',
                'host'      => $row->host,
                'createdAt' => optional($row->created_at)->toDateTimeString(),
            ])
            ->values();

        return response()->json([
            'status'  => true,
            'message' => 'Visits retrieved successfully',
            'data'    => [
                'window' => [
                    'from' => $from->toDateTimeString(),
                    'to'   => now()->toDateTimeString(),
                ],
                'visits' => (clone $query)->count(),
                'latestActivity' => $latestActivity,
            ],
        ]);
    }

    private function sourceMeta(?string $referrer): array
    {
        $referrer = trim((string) $referrer);
        $host     = $referrer ? parse_url($referrer, PHP_URL_HOST) : null;
        $ownHost  = parse_url((string) config('app.frontend_url'), PHP_URL_HOST);

        $isDirect = !$host || ($ownHost && strcasecmp($host, $ownHost) === 0);

        return [
            'source'   => $isDirect ? 'direct' : 'referral',
            'label'    => $isDirect ? 'Visit from direct' : 'Visit from ' . $host,
            'referrer' => $referrer ?: null,
            'host'     => $isDirect ? null : $host,
        ];
    }
}
