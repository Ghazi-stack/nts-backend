<?php

namespace App\Http\Controllers\api\Admin;

use App\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Http\Resources\PromotionResource;
use App\Http\Resources\PromotionsResource;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class PromotionController extends Controller
{
    public function index(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json(['status' => false, 'message' => 'Store not selected or token invalid.'], 400);
            }

            // validate query params (optional)
            $validated = $request->validate([
                'status'  => 'nullable|in:active,inactive',
                'type'    => 'nullable|in:automatic,coupon',
                'perPage' => 'nullable|integer|min:1|max:100',
            ]);

            $query = Promotion::where('store_id', $storeId)
                ->when(isset($validated['status']), function ($q) use ($validated) {
                    $q->where('status', $validated['status']);   // active/inactive only if provided
                })
                ->when(isset($validated['type']), function ($q) use ($validated) {
                    $q->where('type', $validated['type']);       // automatic/coupon only if provided
                })
                ->orderByDesc('priority')
                ->orderByDesc('id');

            // pagination only if perPage provided, otherwise return all
            if (!empty($validated['perPage'])) {
                $paginator  = $query->paginate((int)$validated['perPage']);
                $collection = collect($paginator->items());

                return response()->json([
                    'status'     => true,
                    'message'    => 'Promotions retrieved successfully',
                    'data'       => (new \App\Http\Resources\PromotionResource($collection))->resolve(),
                    'pagination' => [
                        'total'        => $paginator->total(),
                        'count'        => $paginator->count(),
                        'perPage'      => $paginator->perPage(),
                        'currentPage'  => $paginator->currentPage(),
                        'totalPages'   => $paginator->lastPage(),
                    ],
                ], 200);
            }

            // no pagination: return all
            $items = $query->get();

            return response()->json([
                'status'  => true,
                'message' => 'Promotions retrieved successfully',
                'data'    => (new PromotionResource($items))->resolve(),
            ], 200);
        } catch (\Exception $e) {
            \Log::error('Error fetching promotions: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Failed to retrieve promotions'], 500);
        }
    }


    public function show($id)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json(['status' => false, 'message' => 'Store not selected or token invalid.'], 400);
            }

            $promotion = Promotion::where('store_id', $storeId)->findOrFail($id);
            $collection = collect([$promotion]); // keep grouped shape

            return response()->json([
                'status'  => true,
                'message' => 'Promotion retrieved successfully',
                'data'    => (new PromotionResource($collection))->resolve(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => false, 'message' => 'Promotion not found'], 404);
        } catch (\Exception $e) {
            Log::error('Error fetching promotion: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Failed to retrieve promotion'], 500);
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

            $data = $request->validate([
                'kind'             => ['nullable', Rule::in(['automatic', 'coupon'])],
                'type'             => ['required', Rule::in(['custom', 'cart', 'shipping'])],
                'name'             => ['required', 'string', 'max:255'],
                'displayName'      => ['nullable', 'string', 'max:255'],
                'status'           => ['required', Rule::in(['draft', 'active', 'inactive', 'archived'])],
                'priority'         => ['nullable', 'integer', 'min:0'],
                'currency'         => ['nullable', 'string', 'max:10'],
                'channel'          => ['nullable', 'string', 'max:50'],
                'isFeatured'       => ['nullable', 'boolean'],
                'featuredMessage'  => ['nullable', 'string', 'max:80'],
                'maxRedemptions'   => ['nullable', 'integer', 'min:0'],
                'canCombine'       => ['nullable', 'boolean'],
                'startsAt'         => ['nullable', 'date'],
                'endsAt'           => ['nullable', 'date', 'after_or_equal:startsAt'],
                'conditions'       => ['nullable', 'array'],
                'actions'          => ['nullable', 'array'],
                'schedule'         => ['nullable', 'array'],
                'targeting'        => ['nullable', 'array'],
                'banners'          => ['nullable', 'array'],
            ]);

            $promotion = Promotion::create([
                'store_id'         => $storeId,
                'kind'             => $data['kind'] ?? 'automatic',
                'type'             => $data['type'],
                'name'             => $data['name'],
                'display_name'     => $data['displayName'] ?? null,
                'status'           => $data['status'],
                'priority'         => $data['priority'] ?? 0,
                'currency'         => $data['currency'] ?? null,
                'channel'          => $data['channel'] ?? 'any',
                'is_featured'      => $data['isFeatured'] ?? false,
                'featured_message' => $data['featuredMessage'] ?? null,
                'max_redemptions'  => $data['maxRedemptions'] ?? null,
                'redemptions'      => 0,
                'can_combine'      => $data['canCombine'] ?? true,
                'conditions'       => $data['conditions'] ?? null,
                'actions'          => $data['actions'] ?? null,
                'starts_at'        => $data['startsAt'] ?? null,
                'ends_at'          => $data['endsAt'] ?? null,
                'schedule'         => $data['schedule'] ?? null,
                'targeting'        => $data['targeting'] ?? null,
                'banners'          => $data['banners'] ?? null,
            ]);

            return response()->json([
                'status'  => true,
                'message' => 'Promotion created successfully',
                'data'    => new PromotionResource($promotion),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error creating promotion: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to create promotion',
            ], 500);
        }
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
