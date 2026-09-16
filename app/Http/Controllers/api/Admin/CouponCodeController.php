<?php

namespace App\Http\Controllers\api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CouponCode;
use App\Models\CouponUsage;
use Illuminate\Http\Request;
use Exception;
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
                'limitTotalUses'        => 'nullable|integer|min:1',
                'limitUsesPerCustomer'  => 'nullable|integer|min:1',
                'excludeCartDiscounts'  => 'nullable',
                'enabled'               => 'nullable',
                'expiration'            => 'nullable|date',
                'appliesToCategories'   => 'nullable',
                'appliesToProducts'     => 'nullable',
                'categoryIds'           => 'nullable',
                'productIds'            => 'nullable',
                'limitByLocation'       => 'nullable',
                'limitByShipping'       => 'nullable',
            ], [
                'couponCode.unique' => 'This coupon code already exists.',
                'couponName.unique' => 'This coupon name already exists.',
            ]);

            $appliesToCategories = $this->toBool($request->input('appliesToCategories', false));
            $appliesToProducts   = $this->toBool($request->input('appliesToProducts', false));

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
                'limitTotalUses'       => $request->filled('limitTotalUses') ? (string) $validated['limitTotalUses'] : null,
                'limitUsesPerCustomer' => $request->filled('limitUsesPerCustomer') ? (string) $validated['limitUsesPerCustomer'] : null,
                'excludeCartDiscounts' => $this->toBool($request->input('excludeCartDiscounts', false)) ? '1' : '0',
                'enabled'              => $request->has('enabled') ? ($this->toBool($request->input('enabled')) ? 1 : 0) : 1,
                'expiration'           => $validated['expiration'] ?? null,
                'appliesToCategories'  => $appliesToCategories ? '1' : '0',
                'appliesToProducts'    => $appliesToProducts ? '1' : '0',
                'categoryIds'          => $appliesToCategories ? json_encode($categoryIds) : null,
                'productIds'           => $appliesToProducts ? json_encode($productIds) : null,
                'limitByLocation'      => $this->toBool($request->input('limitByLocation', false)) ? '1' : '0',
                'limitByShipping'      => $this->toBool($request->input('limitByShipping', false)) ? '1' : '0',
            ]);

            $coupon->categoryIds = $coupon->categoryIds ? json_decode($coupon->categoryIds, true) : [];
            $coupon->productIds  = $coupon->productIds ? json_decode($coupon->productIds, true) : [];

            return response()->json([
                'status'  => true,
                'message' => 'Coupon added successfully',
                'data'    => $coupon,
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

    private function toBool($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
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
                $enabled = filter_var($request->enabled, FILTER_VALIDATE_BOOLEAN);
                $query->where('enabled', $enabled ? 1 : 0);
            }

            if ($request->filled('discountType')) {
                $query->where('discountType', $request->discountType);
            }

            $coupons = $query->orderBy('id', 'desc')
                ->paginate($request->get('per_page', 10));

            $coupons->getCollection()->transform(function ($coupon) {
                $coupon->categoryIds = $coupon->categoryIds
                    ? json_decode($coupon->categoryIds, true)
                    : [];

                $coupon->productIds = $coupon->productIds
                    ? json_decode($coupon->productIds, true)
                    : [];

                $coupon->appliesToCategories = filter_var($coupon->appliesToCategories, FILTER_VALIDATE_BOOLEAN);
                $coupon->appliesToProducts   = filter_var($coupon->appliesToProducts, FILTER_VALIDATE_BOOLEAN);
                $coupon->excludeCartDiscounts = filter_var($coupon->excludeCartDiscounts, FILTER_VALIDATE_BOOLEAN);
                $coupon->limitByLocation     = filter_var($coupon->limitByLocation, FILTER_VALIDATE_BOOLEAN);
                $coupon->limitByShipping     = filter_var($coupon->limitByShipping, FILTER_VALIDATE_BOOLEAN);
                $coupon->enabled             = (bool) $coupon->enabled;
                $coupon->discountAmount      = $coupon->discountAmount !== null ? (float) $coupon->discountAmount : 0;
                $coupon->minimumPurchase     = $coupon->minimumPurchase !== null ? (float) $coupon->minimumPurchase : 0;
                $coupon->limitTotalUses      = $coupon->limitTotalUses !== null && $coupon->limitTotalUses !== ''
                    ? (int) $coupon->limitTotalUses
                    : null;
                $coupon->limitUsesPerCustomer = $coupon->limitUsesPerCustomer !== null && $coupon->limitUsesPerCustomer !== ''
                    ? (int) $coupon->limitUsesPerCustomer
                    : null;

                return $coupon;
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
                    'status' => false,
                    'message' => 'Record not found'
                ], 404);
            }

            if (!empty($coupon->categoryIds)) {
                $coupon->categoryIds = json_decode($coupon->categoryIds);
            }

            return response()->json([
                'status' => true,
                'data' => $coupon
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // show CouponCode web
    public function showWebCouponCode(Request $request, $id = null)
    {
        try {

            $storeId = $this->getStoreId();

            $query = CouponCode::where('store_id', $storeId);

            // Coupon code se fetch
            if ($request->filled('couponCode')) {

                $query->where('couponCode', $request->couponCode);
            }
            // ID se fetch
            elseif ($id) {

                $query->where('id', $id);
            } else {

                return response()->json([
                    'status' => false,
                    'message' => 'CouponCode or ID is required'
                ], 400);
            }

            $coupon = $query->first();

            if (!$coupon) {

                return response()->json([
                    'status' => false,
                    'message' => 'Invalid coupon code'
                ], 404);
            }

            /*
        |--------------------------------------------------------------------------
        | Identify Customer
        |--------------------------------------------------------------------------
        */

            $user = auth('sanctum')->user();
            $userId = $user?->id;
            $sessionId = null;

            if (!$userId) {
                $sessionId = $request->header('X-Session-ID');
            }

            /*
        |--------------------------------------------------------------------------
        | Check Coupon Already Used
        |--------------------------------------------------------------------------
        */

            $usageQuery = CouponUsage::where('store_id', $storeId)
                ->where('coupon_id', $coupon->id);

            if ($userId) {

                // Logged-in user — user_id ya usi user ka email
                $userEmail = strtolower(trim($user->email ?? ''));

                $usageQuery->where(function ($q) use ($userId, $userEmail) {
                    $q->where('user_id', $userId);

                    if ($userEmail) {
                        $q->orWhereRaw('LOWER(email) = ?', [$userEmail]);
                    }
                });
            } else {

                // Guest — yahan email nahi aati, session se check
                if (!$sessionId) {

                    return response()->json([
                        'status' => false,
                        'message' => 'Session ID is required for guest users'
                    ], 400);
                }

                $usageQuery->where('session_id', $sessionId);
            }

            $alreadyUsed = $usageQuery->exists();

            if ($alreadyUsed) {

                return response()->json([
                    'status' => false,
                    'message' => 'You have already used this coupon.'
                ], 400);
            }

            /*
        |--------------------------------------------------------------------------
        | Decode categoryIds
        |--------------------------------------------------------------------------
        */

            if (!empty($coupon->categoryIds)) {
                $coupon->categoryIds = json_decode($coupon->categoryIds);
            }

            /*
        |--------------------------------------------------------------------------
        | Return Coupon
        |--------------------------------------------------------------------------
        */

            return response()->json([
                'status' => true,
                'message' => 'Coupon is valid.',
                'data' => $coupon
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'status' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $storeId = $this->getStoreId();

            $coupon = CouponCode::where('id', $id)
                ->where('store_id', $storeId)
                ->first();

            if (!$coupon) {
                return response()->json([
                    'status' => false,
                    'message' => 'Record not found'
                ], 404);
            }

            $data = $request->all();

            // ❌ store_id overwrite prevent
            unset($data['store_id']);

            if (isset($data['categoryIds']) && is_array($data['categoryIds'])) {
                $data['categoryIds'] = json_encode($data['categoryIds']);
            }

            $coupon->update($data);

            return response()->json([
                'status' => true,
                'message' => 'Coupon updated successfully',
                'data' => $coupon
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
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
                    'status' => false,
                    'message' => 'Record not found'
                ], 404);
            }

            $coupon->delete();

            return response()->json([
                'status' => true,
                'message' => 'Coupon deleted successfully'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
