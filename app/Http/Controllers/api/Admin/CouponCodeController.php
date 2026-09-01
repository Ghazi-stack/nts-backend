<?php

namespace App\Http\Controllers\api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CouponCode;
use App\Models\CouponUsage;
use Illuminate\Http\Request;
use Exception;

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

            $data = $request->all();

            // ✅ force store_id from header
            $data['store_id'] = $storeId;

            // categoryIds → json
            if ($request->has('categoryIds')) {
                $data['categoryIds'] = is_array($request->categoryIds)
                    ? json_encode($request->categoryIds)
                    : $request->categoryIds;
            }

            $coupon = CouponCode::create($data);

            // response me array
            if (!empty($coupon->categoryIds)) {
                $coupon->categoryIds = json_decode($coupon->categoryIds);
            }

            return response()->json([
                'status' => true,
                'message' => 'Coupon added successfully',
                'data' => $coupon
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
            ], 500);
        }
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
                        ->orWhere('expiration', 'like', "%{$search}%");
                });
            }

            $coupons = $query->orderBy('id', 'desc')
                ->paginate($request->get('per_page', 10));

            return response()->json([
                'status' => true,
                'couponcode' => $coupons
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
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
