<?php

namespace App\Http\Controllers\api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Brand;
use App\Http\Resources\BrandResource;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Exception;

class BrandController extends Controller
{
    public function index(Request $request)
    {
        try {
            $perPage = $request->query('perPage', 20);

            $brands = Brand::where('store_id', current_store_id())->paginate($perPage);

            return response()->json([
                'status' => true,
                'message' => 'Brands retrieved successfully',
                'data' => BrandResource::collection($brands),
                'pagination' => [
                    'total' => $brands->total(),
                    'count' => $brands->count(),
                    'perPage' => $brands->perPage(),
                    'currentPage' => $brands->currentPage(),
                    'totalPages' => $brands->lastPage(),
                ]
            ], 200);
        } catch (Exception $e) {
            Log::error('Error fetching brands: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve brands',
            ], 500);
        }
    }

    public function showBrands($id)
    {
        try {
            $brand = Brand::where('store_id', current_store_id())->findOrFail($id);

            return response()->json([
                'status' => true,
                'message' => 'Brand retrieved successfully',
                'data' => new BrandResource($brand)
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Brand not found'
            ], 404);
        }
    }

    public function storeBrands(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'logo' => 'nullable|image|mimes:jpg,jpeg,png,svg,webp|max:2048',
            ]);

            $data = [
                'store_id' => current_store_id(),
                'name' => $request->name,
            ];

            if ($request->hasFile('logo')) {
                $imageName = time() . '_' . preg_replace('/[^A-Za-z0-9.\-_]/', '_', $request->file('logo')->getClientOriginalName());
                $request->file('logo')->move(public_path('brand_logos'), $imageName);
                $data['logo'] = 'brand_logos/' . $imageName;
            }

            $brand = Brand::create($data);

            return response()->json([
                'status' => true,
                'message' => 'Brand created successfully',
                'brand' => new BrandResource($brand),
            ], 201);
        } catch (Exception $e) {
            Log::error('Error creating brand: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to create brand',
            ], 500);
        }
    }

    public function updateBrands(Request $request, $id)
    {
        try {
            $request->validate([
                'name' => 'nullable|string|max:255',
                'logo' => 'nullable|image|mimes:jpg,jpeg,png,svg,webp|max:2048',
            ]);

            $brand = Brand::where('store_id', current_store_id())->findOrFail($id);

            if ($request->has('name')) {
                $brand->name = $request->name;
            }

            if ($request->hasFile('logo')) {
                // Optional: delete old logo
                if ($brand->logo && file_exists(public_path($brand->logo))) {
                    unlink(public_path($brand->logo));
                }

                $imageName = time() . '_' . preg_replace('/[^A-Za-z0-9.\-_]/', '_', $request->file('logo')->getClientOriginalName());
                $request->file('logo')->move(public_path('brand_logos'), $imageName);
                $brand->logo = 'brand_logos/' . $imageName;
            }

            $brand->save();

            return response()->json([
                'status' => true,
                'message' => 'Brand updated successfully',
                'brand' => new BrandResource($brand->fresh())
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Brand not found',
            ], 404);
        } catch (Exception $e) {
            Log::error('Error updating brand: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to update brand',
            ], 500);
        }
    }

    public function destroyBrands($id)
    {
        try {
            $brand = Brand::where('store_id', current_store_id())->findOrFail($id);

            if ($brand->logo && file_exists(public_path($brand->logo))) {
                unlink(public_path($brand->logo));
            }

            $brand->delete();

            return response()->json([
                'status' => true,
                'message' => 'Brand deleted successfully',
                'data' => new BrandResource($brand)
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Brand not found',
            ], 404);
        } catch (Exception $e) {
            Log::error('Error deleting brand: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete brand',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
