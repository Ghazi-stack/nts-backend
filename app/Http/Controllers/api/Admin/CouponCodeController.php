<?php

namespace App\Http\Controllers\api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CouponCode;
use App\Models\CouponUsage;
use Illuminate\Http\Request;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;



class CouponCodeController extends Controller
{
    private function getStoreId()
    {
        $storeId = current_store_id();

        if (!$storeId) {
            abort(response()->json([
                'status' => false,
                'message' => 'Store not selected or token invalid.'
            ], 400));
        }

        return $storeId;
    }

    public function store(Request $request)
    {
        try {
            $storeId = $this->getStoreId();

            $validated = $request->validate([
                'couponCode' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('coupon_code', 'couponCode')->where(fn($q) => $q->where('store_id', $storeId)),
                ],
                'couponName' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('coupon_code', 'couponName')->where(fn($q) => $q->where('store_id', $storeId)),
                ],
                'discountType' => [
                    'required',
                    'string',
                    Rule::in([
                        'per_total_discount',
                        'per_item_discount',
                        'percentage_discount',
                        'shipping_discount',
                        'free_shipping',
                    ]),
                ],
                'discountAmount' => [
                    Rule::requiredIf(fn() => $request->discountType !== 'free_shipping'),
                    'nullable',
                    'numeric',
                    'min:0',
                    function ($attribute, $value, $fail) use ($request) {
                        if ($request->discountType === 'percentage_discount' && (float) $value > 100) {
                            $fail('Percentage discount cannot be greater than 100.');
                        }
                    },
                ],
                'minimumPurchase'       => 'nullable|numeric|min:0',
                'expiration'            => 'nullable|date',

                'enabled'               => 'nullable|boolean',
                'excludeCartDiscounts'  => 'nullable|boolean',
                'limitTotalUses'        => 'nullable|boolean',
                'limitUsesPerCustomer'  => 'nullable|boolean',
                'appliesToCategories'   => 'nullable|boolean',
                'appliesToProducts'     => 'nullable|boolean',
                'limitByLocation'       => 'nullable|boolean',
                'limitByShipping'       => 'nullable|boolean',

                'categoryIds'           => 'nullable',
                'productIds'            => 'nullable',
            ], [
                'couponCode.unique' => 'This coupon code already exists.',
                'couponName.unique' => 'This coupon name already exists.',
            ]);

            $appliesToCategories = $request->boolean('appliesToCategories');
            $appliesToProducts   = $request->boolean('appliesToProducts');

            if ($appliesToCategories && $appliesToProducts) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Coupon can apply to categories or products, not both.',
                ], 422);
            }

            $categoryIds = $this->normalizeIds($request->input('categoryIds'));
            $productIds  = $this->normalizeIds($request->input('productIds'));

            if ($appliesToCategories && empty($categoryIds)) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Please select at least one category.',
                ], 422);
            }

            if ($appliesToProducts && empty($productIds)) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Please select at least one product.',
                ], 422);
            }

            $coupon = CouponCode::create([
                'store_id'             => $storeId,
                'couponCode'           => $validated['couponCode'],
                'couponName'           => $validated['couponName'],
                'discountType'         => $validated['discountType'],
                'discountAmount'       => $validated['discountType'] === 'free_shipping'
                    ? '0'
                    : (string) ($validated['discountAmount'] ?? 0),
                'minimumPurchase'      => (string) ($validated['minimumPurchase'] ?? 0),
                'expiration'           => $validated['expiration'] ?? null,

                'enabled'              => $request->has('enabled') ? $request->boolean('enabled') : true,
                'excludeCartDiscounts' => $request->boolean('excludeCartDiscounts'),
                'limitTotalUses'       => $request->boolean('limitTotalUses'),
                'limitUsesPerCustomer' => $request->boolean('limitUsesPerCustomer'),
                'appliesToCategories'  => $appliesToCategories,
                'appliesToProducts'    => $appliesToProducts,
                'limitByLocation'      => $request->boolean('limitByLocation'),
                'limitByShipping'      => $request->boolean('limitByShipping'),

                'categoryIds'          => $appliesToCategories ? json_encode($categoryIds) : null,
                'productIds'           => $appliesToProducts ? json_encode($productIds) : null,
            ]);

            return response()->json([
                'status'  => true,
                'message' => 'Coupon added successfully',
                'data'    => $this->formatCoupon($coupon),
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    private function formatCoupon($coupon)
    {
        $coupon->categoryIds = $coupon->categoryIds ? json_decode($coupon->categoryIds, true) : [];
        $coupon->productIds  = $coupon->productIds ? json_decode($coupon->productIds, true) : [];

        $coupon->discountAmount  = $coupon->discountAmount !== null ? (float) $coupon->discountAmount : 0;
        $coupon->minimumPurchase = $coupon->minimumPurchase !== null ? (float) $coupon->minimumPurchase : 0;

        return $coupon;
    }

    private function normalizeIds($ids): array
    {
        if (empty($ids)) {
            return [];
        }

        if (is_string($ids)) {
            $decoded = json_decode($ids, true);
            $ids = json_last_error() === JSON_ERROR_NONE ? $decoded : explode(',', $ids);
        }

        if (!is_array($ids)) {
            $ids = [$ids];
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    public function index(Request $request)
    {
        try {
            $storeId = $this->getStoreId();

            $query = CouponCode::where('store_id', $storeId);

            if ($request->filled('search')) {
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                    $q->where('couponCode', 'like', "%{$search}%")
                        ->orWhere('couponName', 'like', "%{$search}%")
                        ->orWhere('discountType', 'like', "%{$search}%")
                        ->orWhere('expiration', 'like', "%{$search}%");
                });
            }

            if ($request->filled('enabled')) {
                $query->where('enabled', $request->boolean('enabled') ? 1 : 0);
            }

            if ($request->filled('discountType')) {
                $query->where('discountType', $request->discountType);
            }

            $coupons = $query->orderBy('id', 'desc')
                ->paginate($request->get('per_page', 10));

            $coupons->getCollection()->transform(function ($coupon) {
                return $this->formatCoupon($coupon);
            });

            return response()->json([
                'status'     => true,
                'message'    => 'Coupons fetched successfully',
                'couponcode' => $coupons,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $storeId = $this->getStoreId();

            $coupon = CouponCode::where('id', $id)
                ->where('store_id', $storeId)
                ->first();

            if (!$coupon) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Record not found',
                ], 404);
            }

            return response()->json([
                'status'  => true,
                'message' => 'Coupon fetched successfully',
                'data'    => $this->formatCoupon($coupon),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // show CouponCode web
    public function showWebCouponCode(Request $request, $id = null)
    {
        try {
            $storeId = $this->getStoreId();

            $query = CouponCode::where('store_id', $storeId);

            if ($request->filled('couponCode')) {
                $query->where('couponCode', $request->couponCode);
            } elseif ($id) {
                $query->where('id', $id);
            } else {
                return response()->json([
                    'status'  => false,
                    'message' => 'CouponCode or ID is required',
                ], 400);
            }

            $coupon = $query->first();

            if (!$coupon) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Invalid coupon code',
                ], 404);
            }

            if (!$coupon->enabled) {
                return response()->json([
                    'status'  => false,
                    'message' => 'This coupon is not enabled.',
                ], 400);
            }

            if (!empty($coupon->expiration) && strtotime($coupon->expiration) < strtotime(date('Y-m-d'))) {
                return response()->json([
                    'status'  => false,
                    'message' => 'This coupon has expired.',
                ], 400);
            }

            $user = $request->user('sanctum') ?? auth('sanctum')->user();

            if (!$user && $request->bearerToken()) {
                $token = \Laravel\Sanctum\PersonalAccessToken::findToken($request->bearerToken());
                $user  = $token?->tokenable;
            }

            $userId    = $user?->id;
            $sessionId = $request->header('X-Session-ID');

            $usageQuery = CouponUsage::where('store_id', $storeId)
                ->where(function ($q) use ($coupon) {
                    $q->where('coupon_id', $coupon->id)
                        ->orWhere('coupon_code', $coupon->couponCode);
                });

            if ($userId) {
                $userEmail = strtolower(trim($user->email ?? ''));

                $usageQuery->where(function ($q) use ($userId, $userEmail) {
                    $q->where('user_id', $userId);

                    if ($userEmail) {
                        $q->orWhereRaw('LOWER(email) = ?', [$userEmail]);
                    }
                });
            } elseif ($sessionId) {
                $usageQuery->where('session_id', $sessionId);
            } else {
                return response()->json([
                    'status'  => false,
                    'message' => 'Token or X-Session-ID is required',
                ], 400);
            }

            $existingUsage = $usageQuery->orderBy('id', 'desc')->first();

            if ($existingUsage && (int) $existingUsage->is_delete === 1) {
                $existingUsage = null;
            }

            if ($existingUsage && (int) $existingUsage->user_used === 1) {
                return response()->json([
                    'status'  => false,
                    'message' => 'You have already used this coupon.',
                ], 400);
            }

            $coupon = $this->formatCoupon($coupon);

            $productIds = $this->normalizeIds($request->input('productIds', $request->input('product_id')));

            if (empty($productIds) && $request->filled('cart')) {
                foreach ((array) $request->input('cart') as $item) {
                    if (isset($item['productId']) || isset($item['product_id'])) {
                        $productIds[] = (int) ($item['productId'] ?? $item['product_id']);
                    }
                }
                $productIds = array_values(array_unique(array_filter($productIds)));
            }

            if ($coupon->appliesToCategories) {
                if (empty($productIds)) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Product IDs are required to apply this coupon.',
                    ], 400);
                }

                $pivotCategoryIds = DB::table('category_product')
                    ->whereIn('product_id', $productIds)
                    ->pluck('category_id')
                    ->toArray();

                $productTableCategoryIds = DB::table('products')
                    ->whereIn('id', $productIds)
                    ->whereNotNull('category_id')
                    ->pluck('category_id')
                    ->toArray();

                $productCategoryIds = collect($pivotCategoryIds)
                    ->merge($productTableCategoryIds)
                    ->map(fn($id) => (int) $id)
                    ->filter()
                    ->unique()
                    ->values()
                    ->toArray();

                $allowedCategoryIds = $coupon->categoryIds ?? [];
                $matchedCategoryIds = array_values(array_intersect($productCategoryIds, $allowedCategoryIds));

                if (empty($matchedCategoryIds)) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'This coupon is not applicable to the products in your cart.',
                    ], 400);
                }

                $coupon->applicableCategoryIds = $matchedCategoryIds;
            }

            if ($coupon->appliesToProducts) {
                if (empty($productIds)) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Product IDs are required to apply this coupon.',
                    ], 400);
                }

                $allowedProductIds = $coupon->productIds ?? [];
                $matchedProductIds = array_values(array_intersect($productIds, $allowedProductIds));

                if (empty($matchedProductIds)) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'This coupon is not applicable to the products in your cart.',
                    ], 400);
                }

                $coupon->applicableProductIds = $matchedProductIds;
            }

            if (!$existingUsage) {
                CouponUsage::create([
                    'store_id'        => $storeId,
                    'user_id'         => $userId,
                    'session_id'      => $userId ? null : $sessionId,
                    'coupon_id'       => $coupon->id,
                    'coupon_code'     => $coupon->couponCode,
                    'discount_amount' => $coupon->discountAmount,
                    'user_used'       => 0,
                    'is_delete'       => 0,
                    'email'           => $user->email ?? null,
                ]);
            }

            return response()->json([
                'status'  => true,
                'message' => 'Coupon is valid.',
                'data'    => $coupon,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function showDashboardCouponCode(Request $request, $id = null)
    {
        try {
            $storeId = $this->getStoreId();

            $admin = $request->user('sanctum') ?? auth('sanctum')->user();

            if (!$admin && $request->bearerToken()) {
                $token = \Laravel\Sanctum\PersonalAccessToken::findToken($request->bearerToken());
                $admin = $token?->tokenable;
            }

            if (!$admin || (int) $admin->userRole !== 1) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Unauthorized. Admin token required.',
                ], 403);
            }

            $request->validate([
                'email' => 'required|email',
            ]);

            $email = strtolower(trim($request->email));

            $query = CouponCode::where('store_id', $storeId);

            if ($request->filled('couponCode')) {
                $query->where('couponCode', $request->couponCode);
            } elseif ($id) {
                $query->where('id', $id);
            } else {
                return response()->json([
                    'status'  => false,
                    'message' => 'CouponCode or ID is required',
                ], 400);
            }

            $coupon = $query->first();

            if (!$coupon) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Invalid coupon code',
                ], 404);
            }

            if (!$coupon->enabled) {
                return response()->json([
                    'status'  => false,
                    'message' => 'This coupon is not enabled.',
                ], 400);
            }

            if (!empty($coupon->expiration) && strtotime($coupon->expiration) < strtotime(date('Y-m-d'))) {
                return response()->json([
                    'status'  => false,
                    'message' => 'This coupon has expired.',
                ], 400);
            }

            $alreadyUsed = CouponUsage::where('store_id', $storeId)
                ->where('coupon_id', $coupon->id)
                ->whereRaw('LOWER(email) = ?', [$email])
                ->exists();

            if ($alreadyUsed) {
                return response()->json([
                    'status'  => false,
                    'message' => 'You have already used this coupon.',
                ], 400);
            }

            $coupon = $this->formatCoupon($coupon);

            $productIds = $this->normalizeIds($request->input('productIds', $request->input('product_id')));

            if (empty($productIds) && $request->filled('cart')) {
                foreach ((array) $request->input('cart') as $item) {
                    if (isset($item['productId']) || isset($item['product_id'])) {
                        $productIds[] = (int) ($item['productId'] ?? $item['product_id']);
                    }
                }
                $productIds = array_values(array_unique(array_filter($productIds)));
            }

            if ($coupon->appliesToCategories) {
                if (empty($productIds)) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Product IDs are required to apply this coupon.',
                    ], 400);
                }

                $pivotCategoryIds = DB::table('category_product')
                    ->whereIn('product_id', $productIds)
                    ->pluck('category_id')
                    ->toArray();

                $productTableCategoryIds = DB::table('products')
                    ->whereIn('id', $productIds)
                    ->whereNotNull('category_id')
                    ->pluck('category_id')
                    ->toArray();

                $productCategoryIds = collect($pivotCategoryIds)
                    ->merge($productTableCategoryIds)
                    ->map(fn($id) => (int) $id)
                    ->filter()
                    ->unique()
                    ->values()
                    ->toArray();

                $allowedCategoryIds = $coupon->categoryIds ?? [];
                $matchedCategoryIds = array_values(array_intersect($productCategoryIds, $allowedCategoryIds));

                if (empty($matchedCategoryIds)) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'This coupon is not applicable to the products in your cart.',
                    ], 400);
                }

                $coupon->applicableCategoryIds = $matchedCategoryIds;
            }

            if ($coupon->appliesToProducts) {
                if (empty($productIds)) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Product IDs are required to apply this coupon.',
                    ], 400);
                }

                $allowedProductIds = $coupon->productIds ?? [];
                $matchedProductIds = array_values(array_intersect($productIds, $allowedProductIds));

                if (empty($matchedProductIds)) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'This coupon is not applicable to the products in your cart.',
                    ], 400);
                }

                $coupon->applicableProductIds = $matchedProductIds;
            }

            CouponUsage::create([
                'store_id'        => $storeId,
                'user_id'         => null,
                'session_id'      => null,
                'coupon_id'       => $coupon->id,
                'coupon_code'     => $coupon->couponCode,
                'discount_amount' => $coupon->discountAmount,
                'user_used'       => 0,
                'email'           => $email,
            ]);

            return response()->json([
                'status'  => true,
                'message' => 'Coupon is valid.',
                'data'    => $coupon,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
    public function getMyCouponUsage(Request $request)
    {
        try {
            $storeId = $this->getStoreId();

            $user = $request->user('sanctum') ?? auth('sanctum')->user();

            if (!$user && $request->bearerToken()) {
                $token = \Laravel\Sanctum\PersonalAccessToken::findToken($request->bearerToken());
                $user  = $token?->tokenable;
            }

            $userId    = $user?->id;
            $sessionId = $request->header('X-Session-ID');
            $isDraft   = (int) $request->query('isDraft', 0) === 1;

            $query = CouponUsage::where('store_id', $storeId)
                ->where('is_delete', 0)
                ->where('user_used', 0);

            if ($userId) {
                $userEmail = strtolower(trim($user->email ?? ''));

                $query->where(function ($q) use ($userId, $userEmail) {
                    $q->where('user_id', $userId);

                    if ($userEmail) {
                        $q->orWhereRaw('LOWER(email) = ?', [$userEmail]);
                    }
                });
            } elseif ($sessionId) {
                $query->where('session_id', $sessionId);
            } else {
                return response()->json([
                    'status'  => false,
                    'message' => 'Token or X-Session-ID is required',
                ], 400);
            }

            if ($isDraft) {
                $draftUsage = (clone $query)
                    ->where('is_draft', 1)
                    ->orderBy('id', 'desc')
                    ->first();

                if ($draftUsage) {
                    (clone $query)
                        ->where('id', '!=', $draftUsage->id)
                        ->update(['is_delete' => 1]);

                    return response()->json([
                        'status'  => true,
                        'message' => 'Coupon usages fetched successfully',
                        'data'    => $draftUsage,
                    ]);
                }
            }

            $usage = $query
                ->where('is_draft', 0)
                ->orderBy('id', 'desc')
                ->first();

            return response()->json([
                'status'  => true,
                'message' => 'Coupon usages fetched successfully',
                'data'    => $usage,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong',
            ], 500);
        }
    }
    public function deleteMyCouponUsage(Request $request, $id = null)
    {
        try {
            $storeId = $this->getStoreId();

            $user = $request->user('sanctum') ?? auth('sanctum')->user();

            if (!$user && $request->bearerToken()) {
                $token = \Laravel\Sanctum\PersonalAccessToken::findToken($request->bearerToken());
                $user  = $token?->tokenable;
            }

            $userId    = $user?->id;
            $sessionId = $request->header('X-Session-ID');

            $query = CouponUsage::where('store_id', $storeId)
                ->where('is_delete', 0)
                ->where('user_used', 0);

            if ($userId) {
                $userEmail = strtolower(trim($user->email ?? ''));

                $query->where(function ($q) use ($userId, $userEmail) {
                    $q->where('user_id', $userId);

                    if ($userEmail) {
                        $q->orWhereRaw('LOWER(email) = ?', [$userEmail]);
                    }
                });
            } elseif ($sessionId) {
                $query->where('session_id', $sessionId);
            } else {
                return response()->json([
                    'status'  => false,
                    'message' => 'Token or X-Session-ID is required',
                ], 400);
            }

            if ($id) {
                $query->where('id', $id);
            }

            if ($request->filled('coupon_id')) {
                $query->where('coupon_id', $request->coupon_id);
            }

            $usages = $query->get();

            if ($usages->isEmpty()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Record not found',
                ], 404);
            }

            $updatedIds = $usages->pluck('id')->values();

            $query->update([
                'is_delete' => 1,
                'is_draft'  => 0,
            ]);

            return response()->json([
                'status'     => true,
                'message'    => 'Coupon usage deleted successfully',
                'deletedIds' => $updatedIds,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong',
            ], 500);
        }
    }

    public function deleteCouponUsageByEmail(Request $request, $id = null)
    {
        try {
            $storeId = $this->getStoreId();

            $request->validate([
                // 'email' => 'required|email',
                'id'    => $id ? 'nullable' : 'required|integer',
            ]);

            // $email    = strtolower(trim($request->email));
            $recordId = $id ?: $request->id;

            $usage = CouponUsage::where('store_id', $storeId)
                ->where('id', $recordId)
                ->first();
            //->whereRaw('LOWER(email) = ?', [$email])

            if (!$usage) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Record not found',
                ], 404);
            }

            $deletedId = $usage->id;
            $usage->delete();

            return response()->json([
                'status'     => true,
                'message'    => 'Coupon usage deleted successfully',
                'deletedIds' => [$deletedId],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong',
            ], 500);
        }
    }

    public function updateMyCouponDraft(Request $request)
    {
        try {
            $storeId = $this->getStoreId();

            $request->validate([
                'email'    => 'nullable|email',
                'is_draft' => 'nullable|in:0,1',
            ]);

            $email = strtolower(trim($request->email));

            $usage = CouponUsage::where('store_id', $storeId)
                ->whereRaw('LOWER(email) = ?', [$email])
                ->orderBy('id', 'desc')
                ->first();

            if (!$usage) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Record not found',
                ], 404);
            }

            $usage->is_draft = (int) $request->is_draft;
            $usage->save();

            return response()->json([
                'status'  => true,
                'message' => 'Draft status updated successfully',
                'data'    => $usage,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong',
            ], 500);
        }
    }
    public function update(Request $request, $id)
    {
        try {
            $storeId = $this->getStoreId();

            if ($request->input('expiration') === '') {
                $request->merge(['expiration' => null]);
            }

            $coupon = CouponCode::where('id', $id)
                ->where('store_id', $storeId)
                ->first();

            if (!$coupon) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Record not found',
                ], 404);
            }

            $validated = $request->validate([
                'couponCode' => [
                    'sometimes',
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('coupon_code', 'couponCode')
                        ->where(fn($q) => $q->where('store_id', $storeId))
                        ->ignore($coupon->id),
                ],
                'couponName' => [
                    'sometimes',
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('coupon_code', 'couponName')
                        ->where(fn($q) => $q->where('store_id', $storeId))
                        ->ignore($coupon->id),
                ],
                'discountType' => [
                    'sometimes',
                    'required',
                    'string',
                    Rule::in([
                        'per_total_discount',
                        'per_item_discount',
                        'percentage_discount',
                        'shipping_discount',
                        'free_shipping',
                    ]),
                ],
                'discountAmount' => [
                    'sometimes',
                    'nullable',
                    'numeric',
                    'min:0',
                    function ($attribute, $value, $fail) use ($request, $coupon) {
                        $type = $request->input('discountType', $coupon->discountType);
                        if ($type === 'percentage_discount' && $value !== null && (float) $value > 100) {
                            $fail('Percentage discount cannot be greater than 100.');
                        }
                    },
                ],
                'minimumPurchase'       => 'sometimes|nullable|numeric|min:0',
                'expiration'            => 'sometimes|nullable|date',
                'enabled'               => 'sometimes|boolean',
                'excludeCartDiscounts'  => 'sometimes|boolean',
                'limitTotalUses'        => 'sometimes|boolean',
                'limitUsesPerCustomer'  => 'sometimes|boolean',
                'appliesToCategories'   => 'sometimes|boolean',
                'appliesToProducts'     => 'sometimes|boolean',
                'limitByLocation'       => 'sometimes|boolean',
                'limitByShipping'       => 'sometimes|boolean',
                'categoryIds'           => 'sometimes|nullable',
                'productIds'            => 'sometimes|nullable',
            ], [
                'couponCode.unique' => 'This coupon code already exists.',
                'couponName.unique' => 'This coupon name already exists.',
            ]);

            $data = [];

            foreach (
                [
                    'couponCode',
                    'couponName',
                    'discountType',
                    'minimumPurchase',
                    'expiration',
                ] as $field
            ) {
                if ($request->exists($field)) {
                    $data[$field] = $validated[$field] ?? null;
                }
            }

            if ($request->exists('discountAmount')) {
                $data['discountAmount'] = (string) ($validated['discountAmount'] ?? 0);
            }

            foreach (
                [
                    'enabled',
                    'excludeCartDiscounts',
                    'limitTotalUses',
                    'limitUsesPerCustomer',
                    'appliesToCategories',
                    'appliesToProducts',
                    'limitByLocation',
                    'limitByShipping',
                ] as $field
            ) {
                if ($request->exists($field)) {
                    $data[$field] = $request->boolean($field);
                }
            }

            $appliesToCategories = $data['appliesToCategories'] ?? (bool) $coupon->appliesToCategories;
            $appliesToProducts   = $data['appliesToProducts'] ?? (bool) $coupon->appliesToProducts;

            if ($appliesToCategories && $appliesToProducts) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Coupon can apply to categories or products, not both.',
                ], 422);
            }

            if ($request->exists('categoryIds')) {
                $categoryIds = $this->normalizeIds($request->input('categoryIds'));
                $data['categoryIds'] = $appliesToCategories ? json_encode($categoryIds) : null;
            }

            if ($request->exists('productIds')) {
                $productIds = $this->normalizeIds($request->input('productIds'));
                $data['productIds'] = $appliesToProducts ? json_encode($productIds) : null;
            }

            if ($request->exists('appliesToCategories') && !$appliesToCategories) {
                $data['categoryIds'] = null;
            }

            if ($request->exists('appliesToProducts') && !$appliesToProducts) {
                $data['productIds'] = null;
            }

            $discountType = $data['discountType'] ?? $coupon->discountType;
            if ($discountType === 'free_shipping') {
                $data['discountAmount'] = '0';
            }

            if ($appliesToCategories) {
                $finalCategoryIds = $this->normalizeIds($data['categoryIds'] ?? $coupon->categoryIds);
                if (empty($finalCategoryIds)) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Please select at least one category.',
                    ], 422);
                }
            }

            if ($appliesToProducts) {
                $finalProductIds = $this->normalizeIds($data['productIds'] ?? $coupon->productIds);
                if (empty($finalProductIds)) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Please select at least one product.',
                    ], 422);
                }
            }

            unset($data['store_id']);

            if (!empty($data)) {
                $coupon->update($data);
            }

            return response()->json([
                'status'  => true,
                'message' => 'Coupon updated successfully',
                'data'    => $this->formatCoupon($coupon->fresh()),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function updateEnabled(Request $request, $id)
    {
        try {
            $storeId = $this->getStoreId();

            $request->validate([
                'enabled' => 'required|boolean',
            ]);

            $coupon = CouponCode::where('id', $id)
                ->where('store_id', $storeId)
                ->first();

            if (!$coupon) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Record not found',
                ], 404);
            }

            $coupon->update([
                'enabled' => $request->boolean('enabled'),
            ]);

            return response()->json([
                'status'  => true,
                'message' => 'Coupon status updated successfully',
                'data'    => $this->formatCoupon($coupon->fresh()),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
    public function destroy($id)
    {
        try {
            $storeId = $this->getStoreId();

            $coupon = CouponCode::where('id', $id)
                ->where('store_id', $storeId)
                ->first();

            if (!$coupon) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Record not found',
                ], 404);
            }

            $coupon->delete();

            return response()->json([
                'status'  => true,
                'message' => 'Coupon deleted successfully',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
