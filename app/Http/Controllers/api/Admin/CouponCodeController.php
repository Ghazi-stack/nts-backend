<?php

namespace App\Http\Controllers\api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CouponCode;
use Illuminate\Http\Request;
use Exception;

class CouponCodeController extends Controller
{
    // ADD DATA
    public function store(Request $request)
    {
        try {
            $data = $request->all();

            // categoryIds: array -> json (DB)
            if ($request->has('categoryIds')) {
                $data['categoryIds'] = is_array($request->categoryIds)
                    ? json_encode($request->categoryIds)
                    : $request->categoryIds;
            }

            $coupon = CouponCode::create($data);

            // Response me json ko wapas array bana do
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
    // FETCH ALL
    public function index(Request $request)
    {
        try {
            $query = CouponCode::query();

            // Single search key
            if ($request->filled('search')) {
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                    $q->where('couponCode', 'like', "%{$search}%")
                        ->orWhere('couponName', 'like', "%{$search}%")
                        ->orWhere('expiration', 'like', "%{$search}%");
                });
            }

            // Pagination (default 10)
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

    // FETCH BY ID
    public function show($id)
    {
        try {
            $coupon = CouponCode::find($id);

            if (!$coupon) {
                return response()->json([
                    'status' => false,
                    'message' => 'Record not found'
                ], 404);
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

    // UPDATE BY ID
    public function update(Request $request, $id)
    {
        try {
            $coupon = CouponCode::find($id);

            if (!$coupon) {
                return response()->json([
                    'status' => false,
                    'message' => 'Record not found'
                ], 404);
            }

            $data = $request->all();

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

    // DELETE
    public function destroy($id)
    {
        try {
            $coupon = CouponCode::find($id);

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
