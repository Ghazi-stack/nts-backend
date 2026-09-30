<?php

namespace App\Http\Controllers\api\Admin;

use App\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Http\Resources\PromotionResource;
use App\Http\Resources\PromotionsResource;
use App\Models\PromotionCouponCode;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class PromotionController extends Controller
{
    public function index(Request $request)
    {
        $storeId = current_store_id();
        if (!$storeId) {
            return response()->json(['status' => false, 'message' => 'Store not selected or token invalid.'], 400);
        }

        $kind   = $request->get('kind', 'automatic'); // automatic | coupon
        $status = $request->get('status', 'all');     // all | active | inactive | archived
        $search = trim((string) $request->get('search', ''));

        $q = Promotion::where('store_id', $storeId)
            ->where('kind', $kind)
            ->withCount('couponCodes');

        if ($status === 'all') {
            $q->where('status', '!=', 'archived');
        } elseif (in_array($status, ['active', 'inactive', 'archived', 'draft'], true)) {
            $q->where('status', $status);
        }

        if ($request->filled('currency')) {
            $q->where('currency', $request->get('currency'));
        }
        if ($request->filled('type')) {
            $q->where('type', $request->get('type')); // custom | cart | shipping
        }
        if ($request->filled('channel')) {
            $q->where('channel', $request->get('channel'));
        }

        if ($search !== '') {
            $q->where(function ($w) use ($search, $kind) {
                $w->where('name', 'like', "%{$search}%");
                if ($kind === 'coupon') {
                    $w->orWhere('code', 'like', "%{$search}%")
                        ->orWhereHas('couponCodes', function ($c) use ($search) {
                            $c->where('code', 'like', "%{$search}%");
                        });
                }
            });
        }

        $rows = $q->orderByDesc('id')->get();

        $data = $rows->map(function ($p) {
            $max  = $p->max_redemptions;
            $used = (int) $p->redemptions;

            return [
                'id'           => $p->id,
                'kind'         => $p->kind,
                'name'         => $p->name,
                'displayName'  => $p->display_name,
                'isFeatured'   => (bool) $p->is_featured,
                'currency'     => $p->currency,
                'type'         => $p->type,
                'channel'      => $p->channel,
                'status'       => $p->status,
                'active'       => $p->status === 'active',
                'usesUsed'     => $used,
                'usesLimit'    => $max,
                'usesLabel'    => is_null($max) ? $used . '/Unlimited' : $used . '/' . $max,
                'codesCount'   => (int) $p->coupon_codes_count,
                'codesLabel'   => $p->kind === 'coupon'
                    ? (($p->coupon_codes_count ?: 0) . ' codes')
                    : null,
                'code'         => $p->code,
                'startsAt'     => optional($p->starts_at)->toDateTimeString(),
                'endsAt'       => optional($p->ends_at)->toDateTimeString(),
                'startLabel'   => optional($p->starts_at)?->format('D, M j, Y g:i A'),
                'endLabel'     => $p->ends_at ? $p->ends_at->format('D, M j, Y g:i A') : 'Not set',
            ];
        })->values();

        return response()->json([
            'status'  => true,
            'message' => 'Promotions fetched',
            'data'    => [
                'kind'  => $kind,
                'total' => $data->count(),
                'items' => $data,
            ],
        ]);
    }

    public function show($id)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.',
                ], 400);
            }

            $promotion = Promotion::where('store_id', $storeId)
                ->withCount('couponCodes')
                ->find($id);

            if (!$promotion) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Promotion not found.',
                ], 404);
            }

            $downloadUrl = $promotion->kind === 'coupon'
                ? url('/api/dashboard/promotions/download-codes/' . $promotion->id)
                : null;

            return response()->json([
                'status'         => true,
                'message'        => 'Promotion fetched successfully',
                'codesGenerated' => (int) $promotion->coupon_codes_count,
                'downloadUrl'    => $downloadUrl,
                'data'           => new PromotionResource($promotion),
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching promotion: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to fetch promotion',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

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

            $validated = $request->validate([
                'kind'                => ['nullable', Rule::in(['automatic', 'coupon'])],
                'editor'              => ['nullable', Rule::in(['standard', 'legacy'])],
                'name'                => ['required', 'string', 'max:255'],
                'displayName'         => ['nullable', 'string', 'max:255'],
                'status'              => ['required', Rule::in(['draft', 'active', 'inactive', 'archived'])],
                'priority'            => ['nullable', 'integer', 'min:0'],
                'currency'            => ['nullable', 'string', 'max:10'],
                'channel'             => ['nullable', 'string', 'max:50'],
                'isFeatured'          => ['nullable', 'boolean'],
                'featuredMessage'     => ['nullable', 'string', 'max:80'],
                'code'                => ['nullable', 'string', 'max:100'],
                'maxRedemptions'      => ['nullable', 'integer', 'min:0'],
                'perCustomerLimit'    => ['nullable', 'integer', 'min:0'],
                'canCombine'          => ['nullable', 'boolean'],
                'startsAt'            => ['nullable', 'date'],
                'endsAt'              => ['nullable', 'date', 'after_or_equal:startsAt'],
                'rules'               => ['required', 'array'],
                'rules.source'        => ['required', Rule::in(['custom', 'cart', 'shipping'])],
                'schedule'            => ['nullable', 'array'],
                'targeting'           => ['nullable', 'array'],
                'banners'             => ['nullable', 'array'],
                'coupon'              => ['nullable', 'array'],
                'coupon.mode'         => ['nullable', Rule::in(['single', 'bulk'])],
                'coupon.count'        => ['nullable', 'integer', 'min:1'],
                'coupon.length'       => ['nullable', 'integer', 'min:1', 'max:20'],
                'coupon.format'       => ['nullable', Rule::in(['alphanumeric', 'numbers', 'letters'])],
                'coupon.prefix'       => ['nullable', 'string', 'max:30'],
                'coupon.suffix'       => ['nullable', 'string', 'max:30'],
                'coupon.separator'    => ['nullable', 'string', 'max:5'],
                'coupon.scheduleMode' => ['nullable', Rule::in(['promotion', 'custom'])],
                'coupon.validFrom'    => ['nullable', 'date'],
                'coupon.validUntil'   => ['nullable', 'date'],
            ]);

            $isFeatured = (bool) ($validated['isFeatured'] ?? false);
            $kind       = $validated['kind'] ?? 'automatic';
            $rules      = $request->input('rules');
            $couponCfg  = $kind === 'coupon'
                ? ($request->input('coupon') ?: ['mode' => 'single'])
                : null;

            $promotion = Promotion::create([
                'store_id'           => $storeId,
                'kind'               => $kind,
                'editor'             => $validated['editor'] ?? 'standard',
                'type'               => $rules['source'] ?? 'custom',
                'name'               => $validated['name'],
                'display_name'       => $validated['displayName'] ?? null,
                'status'             => $validated['status'],
                'priority'           => $validated['priority'] ?? 0,
                'currency'           => $validated['currency'] ?? null,
                'channel'            => $validated['channel'] ?? 'any',
                'is_featured'        => $isFeatured,
                'featured_message'   => $isFeatured ? ($validated['featuredMessage'] ?? null) : null,
                'code'               => null,
                'coupon'             => $couponCfg,
                'max_redemptions'    => $validated['maxRedemptions'] ?? null,
                'per_customer_limit' => $validated['perCustomerLimit'] ?? null,
                'redemptions'        => 0,
                'can_combine'        => $validated['canCombine'] ?? true,
                'conditions'         => $rules,
                'actions'            => $rules['reward'] ?? null,
                'starts_at'          => $validated['startsAt'] ?? null,
                'ends_at'            => $validated['endsAt'] ?? null,
                'schedule'           => $request->input('schedule'),
                'targeting'          => $request->input('targeting'),
                'banners'            => $request->input('banners'),
            ]);

            $generated   = 0;
            $downloadUrl = null;

            if ($kind === 'coupon') {
                $generated   = $this->saveCouponCodes($promotion, $storeId, $validated, $couponCfg ?: []);
                $downloadUrl = url('/api/dashboard/promotions/download-codes/' . $promotion->id);
            }

            return response()->json([
                'status'         => true,
                'message'        => 'Promotion created successfully',
                'codesGenerated' => $generated,
                'downloadUrl'    => $downloadUrl,
                'data'           => new PromotionResource($promotion->fresh()),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error creating promotion: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to create promotion',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function downloadCodes($id)
    {
        $storeId   = current_store_id();
        $promotion = Promotion::where('store_id', $storeId)->findOrFail($id);
        $rows      = PromotionCouponCode::where('promotion_id', $promotion->id)
            ->orderBy('id')
            ->get();

        $filename = 'coupon-codes-' . $promotion->id . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Code', 'Uses', 'Created', 'Last used']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->code,
                    $row->status === 'used' ? 1 : 0,
                    optional($row->created_at)?->toIso8601String(),
                    optional($row->used_at)?->toIso8601String() ?: '',
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function listCodes($id)
    {
        $storeId   = current_store_id();
        $promotion = Promotion::where('store_id', $storeId)->findOrFail($id);
        $rows      = PromotionCouponCode::where('promotion_id', $promotion->id)
            ->orderBy('id')
            ->get();

        return response()->json([
            'status'  => true,
            'message' => 'Coupon codes fetched',
            'data'    => [
                'promotionId' => $promotion->id,
                'count'       => $rows->count(),
                'codes'       => $rows->map(function ($row) {
                    return [
                        'id'        => $row->id,
                        'code'      => $row->code,
                        'status'    => $row->status,
                        'uses'      => $row->status === 'used' ? 1 : 0,
                        'validFrom' => optional($row->valid_from)?->toDateTimeString(),
                        'validUntil' => optional($row->valid_until)?->toDateTimeString(),
                        'created'   => optional($row->created_at)?->toDateTimeString(),
                        'lastUsed'  => optional($row->used_at)?->toDateTimeString(),
                    ];
                })->values(),
            ],
        ]);
    }

    private function saveCouponCodes(Promotion $promotion, $storeId, array $validated, array $coupon): int
    {
        $mode = $coupon['mode'] ?? 'single';

        $validFrom  = $promotion->starts_at;
        $validUntil = $promotion->ends_at;
        if (($coupon['scheduleMode'] ?? 'promotion') === 'custom') {
            $validFrom  = $coupon['validFrom'] ?? $validFrom;
            $validUntil = $coupon['validUntil'] ?? $validUntil;
        }

        if ($mode === 'single') {
            $single = strtoupper(trim((string) ($validated['code'] ?? '')));
            if ($single === '') {
                throw new \Exception('Coupon code required');
            }

            $exists = PromotionCouponCode::where('store_id', $storeId)->where('code', $single)->exists();
            if ($exists) {
                throw new \Exception('Coupon code already exists for this store.');
            }

            PromotionCouponCode::create([
                'store_id'     => $storeId,
                'promotion_id' => $promotion->id,
                'code'         => $single,
                'status'       => 'unused',
                'valid_from'   => $validFrom,
                'valid_until'  => $validUntil,
            ]);

            $promotion->update(['code' => $single]);
            return 1;
        }

        $count     = (int) ($coupon['count'] ?? 0);
        $length    = (int) ($coupon['length'] ?? 6);
        $format    = $coupon['format'] ?? 'alphanumeric';
        $prefix    = (string) ($coupon['prefix'] ?? '');
        $suffix    = (string) ($coupon['suffix'] ?? '');
        $separator = (string) ($coupon['separator'] ?? '');

        if ($count < 1) {
            throw new \Exception('Number of codes required');
        }

        $rows  = [];
        $tries = 0;
        while (count($rows) < $count && $tries < $count * 30) {
            $tries++;
            $body = $this->makeCouponBody($length, $format);
            $code = strtoupper(trim(
                $prefix . ($prefix !== '' ? $separator : '') . $body . ($suffix !== '' ? $separator . $suffix : ''),
                $separator ?: ' '
            ));

            if (isset($rows[$code])) {
                continue;
            }
            if (PromotionCouponCode::where('store_id', $storeId)->where('code', $code)->exists()) {
                continue;
            }

            $rows[$code] = [
                'store_id'     => $storeId,
                'promotion_id' => $promotion->id,
                'code'         => $code,
                'status'       => 'unused',
                'valid_from'   => $validFrom,
                'valid_until'  => $validUntil,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        }

        PromotionCouponCode::insert(array_values($rows));
        return count($rows);
    }

    private function makeCouponBody(int $length, string $format): string
    {
        $pool = match ($format) {
            'numbers' => '0123456789',
            'letters' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            default   => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
        };

        $out = '';
        $max = strlen($pool) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= $pool[random_int(0, $max)];
        }
        return $out;
    }

    public function update(Request $request, $id)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json(['status' => false, 'message' => 'Store not selected or token invalid.'], 400);
            }

            $promotion = Promotion::where('store_id', $storeId)->findOrFail($id);

            $data = $request->validate([
                'type'      => ['sometimes', Rule::in(['automatic', 'coupon'])],
                'name'      => 'sometimes|string|max:255',
                'status'    => ['sometimes', Rule::in(['active', 'inactive'])],
                'priority'  => 'sometimes|integer|min:0',
                'currency'  => 'nullable|string|max:10',
                'channel'   => 'nullable|string|max:255',

                // coupon-only
                'code'              => 'nullable|string|max:100',
                'maxRedemptions'    => 'nullable|integer|min:1',
                'perCustomerLimit'  => 'nullable|integer|min:1',

                'conditions' => 'nullable|array',
                'actions'    => 'nullable|array',
                'startsAt'   => 'nullable|date',
                'endsAt'     => 'nullable|date|after_or_equal:startsAt',
                'extra'      => 'nullable|array',
            ]);

            $finalType = array_key_exists('type', $data) ? $data['type'] : $promotion->type;

            if ($finalType === 'coupon' && array_key_exists('code', $data) && !empty($data['code'])) {
                $exists = Promotion::where('store_id', $storeId)
                    ->where('code', strtoupper($data['code']))
                    ->where('id', '!=', $promotion->id)
                    ->exists();
                if ($exists) {
                    return response()->json(['status' => false, 'message' => 'Coupon code already exists for this store.'], 422);
                }
            }

            $payload = [
                'type'       => $finalType,
                'name'       => array_key_exists('name', $data) ? $data['name'] : $promotion->name,
                'status'     => array_key_exists('status', $data) ? $data['status'] : $promotion->status,
                'priority'   => array_key_exists('priority', $data) ? $data['priority'] : $promotion->priority,
                'currency'   => array_key_exists('currency', $data) ? $data['currency'] : $promotion->currency,
                'channel'    => array_key_exists('channel', $data) ? $data['channel'] : $promotion->channel,
                'conditions' => array_key_exists('conditions', $data) ? $data['conditions'] : $promotion->conditions,
                'actions'    => array_key_exists('actions', $data) ? $data['actions'] : $promotion->actions,
                'starts_at'  => array_key_exists('startsAt', $data) ? $data['startsAt'] : $promotion->starts_at,
                'ends_at'    => array_key_exists('endsAt', $data) ? $data['endsAt'] : $promotion->ends_at,
                'extra'      => array_key_exists('extra', $data) ? $data['extra'] : $promotion->extra,
            ];

            if ($finalType === 'coupon') {
                $payload['code']               = array_key_exists('code', $data) ? strtoupper($data['code']) : $promotion->code;
                $payload['max_redemptions']    = array_key_exists('maxRedemptions', $data) ? $data['maxRedemptions'] : $promotion->max_redemptions;
                $payload['per_customer_limit'] = array_key_exists('perCustomerLimit', $data) ? $data['perCustomerLimit'] : $promotion->per_customer_limit;
            } else {
                // if switched to automatic, clear coupon-only fields
                $payload['code']               = null;
                $payload['max_redemptions']    = null;
                $payload['per_customer_limit'] = null;
            }

            $promotion->update($payload);

            return response()->json([
                'status'  => true,
                'message' => 'Promotion updated successfully',
                'data'    => (new PromotionResource(collect([$promotion->fresh()])))->resolve(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => false, 'message' => 'Promotion not found'], 404);
        } catch (\Exception $e) {
            Log::error('Error updating promotion: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Failed to update promotion'], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json(['status' => false, 'message' => 'Store not selected or token invalid.'], 400);
            }

            $promotion = Promotion::where('store_id', $storeId)->findOrFail($id);
            $promotion->delete();

            return response()->json([
                'status'  => true,
                'message' => 'Promotion deleted successfully',
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => false, 'message' => 'Promotion not found'], 404);
        } catch (\Exception $e) {
            Log::error('Error deleting promotion: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Failed to delete promotion'], 500);
        }
    }
}
