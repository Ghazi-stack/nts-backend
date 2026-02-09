<?php

namespace App\Http\Controllers\api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CouponCode;
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

            // ✅ Agar request me couponCode aya hai
            if ($request->filled('couponCode')) {
                $query->where('couponCode', $request->couponCode);
            }
            // ✅ Warna ID se fetch karo
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
                    'message' => 'Record not found'
                ], 404);
            }

            // ✅ Decode categoryIds if exists
            if (!empty($coupon->categoryIds)) {
                $coupon->categoryIds = json_decode($coupon->categoryIds);
            }

            return response()->json([
                'status' => true,
                'data'   => $coupon
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong',
                'error'   => $e->getMessage()
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
