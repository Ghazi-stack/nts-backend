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

    public function addCodes(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json(['status' => false, 'message' => 'Store not selected or token invalid.'], 400);
            }

            $validated = $request->validate([
                'promotionId'  => ['required', 'integer'],
                'count'        => ['required', 'integer', 'min:1'],
                'length'       => ['nullable', 'integer', 'min:1', 'max:20'],
                'format'       => ['nullable', Rule::in(['alphanumeric', 'numbers', 'letters'])],
                'prefix'       => ['nullable', 'string', 'max:30'],
                'suffix'       => ['nullable', 'string', 'max:30'],
                'separator'    => ['nullable', 'string', 'max:5'],
                'scheduleMode' => ['nullable', Rule::in(['promotion', 'custom'])],
                'validFrom'    => ['nullable', 'date'],
                'validUntil'   => ['nullable', 'date'],
            ]);

            $promotion = Promotion::where('store_id', $storeId)->find($validated['promotionId']);
            if (!$promotion) {
                return response()->json(['status' => false, 'message' => 'Promotion not found.'], 404);
            }

            $count     = (int) $validated['count'];
            $length    = (int) ($validated['length'] ?? 6);
            $format    = $validated['format'] ?? 'alphanumeric';
            $prefix    = (string) ($validated['prefix'] ?? '');
            $suffix    = (string) ($validated['suffix'] ?? '');
            $separator = (string) ($validated['separator'] ?? '');

            $validFrom  = $promotion->starts_at;
            $validUntil = $promotion->ends_at;
            if (($validated['scheduleMode'] ?? 'promotion') === 'custom') {
                $validFrom  = $validated['validFrom'] ?? $validFrom;
                $validUntil = $validated['validUntil'] ?? $validUntil;
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

            return response()->json([
                'status'         => true,
                'message'        => count($rows) . ' coupon codes generated successfully',
                'codesGenerated' => count($rows),
                'downloadUrl'    => url('/api/dashboard/promotions/download-codes/' . $promotion->id),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to generate coupon codes',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function listCodes(Request $request, $id)
    {
        $storeId = current_store_id();
        if (!$storeId) {
            return response()->json(['status' => false, 'message' => 'Store not selected or token invalid.'], 400);
        }

        $promotion = Promotion::where('store_id', $storeId)->find($id);
        if (!$promotion) {
            return response()->json(['status' => false, 'message' => 'Promotion not found.'], 404);
        }

        $q = PromotionCouponCode::where('promotion_id', $promotion->id);

        $search = trim((string) $request->get('search', ''));
        if ($search !== '') {
            $q->where('code', 'like', "%{$search}%");
        }

        if ($request->filled('validFrom')) {
            $q->whereDate('valid_from', '>=', $request->get('validFrom'));
        }
        if ($request->filled('validUntil')) {
            $q->whereDate('valid_until', '<=', $request->get('validUntil'));
        }

        $rows = $q->orderBy('id')->get();

        return response()->json([
            'status'  => true,
            'message' => 'Coupon codes fetched',
            'data'    => [
                'promotionId' => $promotion->id,
                'count'       => $rows->count(),
                'codes'       => $rows->map(function ($row) {
                    $from = $row->valid_from
                        ? $row->valid_from->format('D, M j, Y, g:i A')
                        : 'Not set';
                    $until = $row->valid_until
                        ? $row->valid_until->format('D, M j, Y, g:i A')
                        : 'Not set';

                    return [
                        'id'         => $row->id,
                        'code'       => $row->code,
                        'status'     => $row->status,
                        'uses'       => $row->status === 'used' ? 1 : 0,
                        'created'    => optional($row->created_at)?->toDateTimeString(),
                        'createdLabel' => optional($row->created_at)?->format('D, M j, Y, g:i A'),
                        'validFrom'  => optional($row->valid_from)?->toDateTimeString(),
                        'validUntil' => optional($row->valid_until)?->toDateTimeString(),
                        'validLabel' => $from . ' - ' . $until,
                        'lastUsed'   => optional($row->used_at)?->toDateTimeString(),
                    ];
                })->values(),
            ],
        ]);
    }

    public function updateCodes(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json(['status' => false, 'message' => 'Store not selected or token invalid.'], 400);
            }

            $validated = $request->validate([
                'ids'        => ['required', 'array', 'min:1'],
                'ids.*'      => ['integer'],
                'code'       => ['nullable', 'string', 'max:120'],
                'validFrom'  => ['nullable', 'date'],
                'validUntil' => ['nullable', 'date'],
                'status'     => ['nullable', Rule::in(['unused', 'used'])],
            ]);

            $ids  = array_values(array_unique($validated['ids']));
            $rows = PromotionCouponCode::where('store_id', $storeId)->whereIn('id', $ids)->get();

            if ($rows->isEmpty()) {
                return response()->json(['status' => false, 'message' => 'Coupon code not found.'], 404);
            }

            if (count($ids) === 1 && !empty($validated['code'])) {
                $code = strtoupper(trim($validated['code']));
                $taken = PromotionCouponCode::where('store_id', $storeId)
                    ->where('code', $code)
                    ->where('id', '!=', $ids[0])
                    ->exists();
                if ($taken) {
                    return response()->json(['status' => false, 'message' => 'Coupon code already exists.'], 422);
                }
            }

            foreach ($rows as $row) {
                $data = [];
                if (count($ids) === 1 && !empty($validated['code'])) {
                    $data['code'] = strtoupper(trim($validated['code']));
                }
                if (array_key_exists('validFrom', $validated)) {
                    $data['valid_from'] = $validated['validFrom'] ?? null;
                }
                if (array_key_exists('validUntil', $validated)) {
                    $data['valid_until'] = $validated['validUntil'] ?? null;
                }
                if (!empty($validated['status'])) {
                    $data['status'] = $validated['status'];
                    if ($validated['status'] === 'unused') {
                        $data['used_at'] = null;
                    }
                }
                if (!empty($data)) {
                    $row->update($data);
                }
            }

            return response()->json([
                'status'  => true,
                'message' => 'Coupon codes updated',
                'updated' => $rows->count(),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to update coupon codes',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function deleteCodes(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json(['status' => false, 'message' => 'Store not selected or token invalid.'], 400);
            }

            $validated = $request->validate([
                'ids'   => ['required', 'array', 'min:1'],
                'ids.*' => ['integer'],
            ]);

            $ids = array_values(array_unique($validated['ids']));
            $deleted = PromotionCouponCode::where('store_id', $storeId)
                ->whereIn('id', $ids)
                ->delete();

            if (!$deleted) {
                return response()->json(['status' => false, 'message' => 'Coupon code not found.'], 404);
            }

            return response()->json([
                'status'  => true,
                'message' => 'Coupon codes deleted',
                'deleted' => $deleted,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to delete coupon codes',
                'error'   => $e->getMessage(),
            ], 500);
        }
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
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.',
                ], 400);
            }

            $promotion = Promotion::where('store_id', $storeId)->find($id);
            if (!$promotion) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Promotion not found.',
                ], 404);
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
            $kind       = $validated['kind'] ?? $promotion->kind ?? 'automatic';
            $rules      = $request->input('rules');
            $couponCfg  = $kind === 'coupon'
                ? ($request->input('coupon') ?: $promotion->coupon)
                : null;

            $promotion->update([
                'kind'               => $kind,
                'editor'             => $validated['editor'] ?? $promotion->editor ?? 'standard',
                'type'               => $rules['source'] ?? 'custom',
                'name'               => $validated['name'],
                'display_name'       => $validated['displayName'] ?? null,
                'status'             => $validated['status'],
                'priority'           => $validated['priority'] ?? 0,
                'currency'           => $validated['currency'] ?? null,
                'channel'            => $validated['channel'] ?? 'any',
                'is_featured'        => $isFeatured,
                'featured_message'   => $isFeatured ? ($validated['featuredMessage'] ?? null) : null,
                'coupon'             => $couponCfg,
                'max_redemptions'    => $validated['maxRedemptions'] ?? null,
                'per_customer_limit' => $validated['perCustomerLimit'] ?? null,
                'can_combine'        => $validated['canCombine'] ?? true,
                'conditions'         => $rules,
                'actions'            => $rules['reward'] ?? null,
                'starts_at'          => $validated['startsAt'] ?? null,
                'ends_at'            => $validated['endsAt'] ?? null,
                'schedule'           => $request->input('schedule'),
                'targeting'          => $request->input('targeting'),
                'banners'            => $request->input('banners'),
            ]);

            if ($kind === 'coupon' && ($couponCfg['mode'] ?? 'single') === 'single' && !empty($validated['code'])) {
                $single = strtoupper(trim($validated['code']));
                $taken = PromotionCouponCode::where('store_id', $storeId)
                    ->where('code', $single)
                    ->where('promotion_id', '!=', $promotion->id)
                    ->exists();
                if ($taken) {
                    return response()->json(['status' => false, 'message' => 'Coupon code already exists'], 422);
                }

                $row = PromotionCouponCode::where('promotion_id', $promotion->id)->first();
                if ($row) {
                    $row->update(['code' => $single]);
                } else {
                    PromotionCouponCode::create([
                        'store_id'     => $storeId,
                        'promotion_id' => $promotion->id,
                        'code'         => $single,
                        'status'       => 'unused',
                        'valid_from'   => $promotion->starts_at,
                        'valid_until'  => $promotion->ends_at,
                    ]);
                }
                $promotion->update(['code' => $single]);
            }

            $promotion->refresh()->loadCount('couponCodes');
            $downloadUrl = $kind === 'coupon'
                ? url('/api/dashboard/promotions/download-codes/' . $promotion->id)
                : null;

            return response()->json([
                'status'         => true,
                'message'        => 'Promotion updated successfully',
                'codesGenerated' => (int) $promotion->coupon_codes_count,
                'downloadUrl'    => $downloadUrl,
                'data'           => new PromotionResource($promotion),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error updating promotion: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to update promotion',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function updateStatus(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json(['status' => false, 'message' => 'Store not selected or token invalid.'], 400);
            }

            $validated = $request->validate([
                'status' => ['required', Rule::in(['active', 'inactive', 'archived'])],
                'ids'    => ['required', 'array', 'min:1'],
                'ids.*'  => ['integer'],
            ]);

            $ids = array_values(array_unique($validated['ids']));

            $updated = Promotion::where('store_id', $storeId)
                ->whereIn('id', $ids)
                ->update(['status' => $validated['status']]);

            if (!$updated) {
                return response()->json(['status' => false, 'message' => 'Promotion not found.'], 404);
            }

            return response()->json([
                'status'  => true,
                'message' => 'Promotion status updated',
                'updated' => $updated,
                'data'    => [
                    'ids'    => $ids,
                    'status' => $validated['status'],
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to update status',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json(['status' => false, 'message' => 'Store not selected or token invalid.'], 400);
            }

            $validated = $request->validate([
                'ids'   => ['required', 'array', 'min:1'],
                'ids.*' => ['integer'],
            ]);

            $ids = array_values(array_unique($validated['ids']));

            $promos = Promotion::where('store_id', $storeId)->whereIn('id', $ids)->get();
            if ($promos->isEmpty()) {
                return response()->json(['status' => false, 'message' => 'Promotion not found.'], 404);
            }

            $promoIds = $promos->pluck('id');
            $codesDeleted = PromotionCouponCode::whereIn('promotion_id', $promoIds)->delete();
            $deleted = Promotion::where('store_id', $storeId)->whereIn('id', $promoIds)->delete();

            return response()->json([
                'status'       => true,
                'message'      => 'Promotion deleted successfully',
                'deleted'      => $deleted,
                'codesDeleted' => $codesDeleted,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to delete promotion',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
