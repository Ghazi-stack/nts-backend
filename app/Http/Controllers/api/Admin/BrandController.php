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
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected or token invalid.'
                ], 400);
            }

            $perPage = $request->query('perPage', 20);

            $brands = Brand::where('store_id', $storeId)->paginate($perPage);

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
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected or token invalid.'
                ], 400);
            }

            $brand = Brand::where('store_id', $storeId)->findOrFail($id);

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
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected or token invalid.'
                ], 400);
            }

            $request->validate([
                'name' => 'required|string|max:255',
                'logo' => 'nullable|image|mimes:jpg,jpeg,png,svg,webp|max:2048',
            ]);

            $data = [
                'store_id' => $storeId,
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
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected or token invalid.'
                ], 400);
            }

            $validated = $request->validate([
                'name'              => 'nullable|string|max:255',
                'pageTitle'         => 'nullable|string|max:255',
                'metaKeywords'      => 'nullable|string|max:255',
                'metaDescription'   => 'nullable|string|max:1000',
                'searchKeywords'    => 'nullable|string|max:255',
                'brandURL'          => 'nullable|url|max:255',
                'templateLayout'    => 'nullable|string|max:255',
                'logo'              => 'nullable|image|mimes:jpg,jpeg,png,svg,webp|max:2048',
            ]);

            $brand = Brand::where('store_id', $storeId)->findOrFail($id);

            // ✅ Update fields using fillable mapping
            $brand->fill([
                'name'              => $validated['name'] ?? $brand->name,
                'page_title'        => $validated['pageTitle'] ?? $brand->page_title,
                'meta_keywords'     => $validated['metaKeywords'] ?? $brand->meta_keywords,
                'meta_description'  => $validated['metaDescription'] ?? $brand->meta_description,
                'search_keywords'   => $validated['searchKeywords'] ?? $brand->search_keywords,
                'brand_url'         => $validated['brandURL'] ?? $brand->brand_url,
                'template_layout'   => $validated['templateLayout'] ?? $brand->template_layout,
            ]);

            // ✅ Handle logo upload
            if ($request->hasFile('logo')) {
                $this->deleteOldLogo($brand->logo);

                $image = $request->file('logo');
                $imageName = time() . '_' . preg_replace('/[^A-Za-z0-9.\-_]/', '_', $image->getClientOriginalName());
                $image->move(public_path('brand_logos'), $imageName);
                $brand->logo = 'brand_logos/' . $imageName;
            }

            $brand->save();

            return response()->json([
                'status'  => true,
                'message' => 'Brand updated successfully',
                'brand'   => new \App\Http\Resources\BrandResource($brand->fresh())
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Brand not found.'
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Error updating brand: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to update brand.'
            ], 500);
        }
    }

    public function destroyBrands($ids)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected or token invalid.'
                ], 400);
            }

            // Convert comma-separated string to array
            $idArray = explode(',', $ids);

            if (empty($idArray)) {
                return response()->json([
                    'status' => false,
                    'message' => 'No brand IDs provided.'
                ], 422);
            }

            // Fetch brands for deletion
            $brands = Brand::where('store_id', $storeId)
                           ->whereIn('id', $idArray)
                           ->get();

            if ($brands->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'No matching brands found.'
                ], 404);
            }

            foreach ($brands as $brand) {
                if ($brand->logo && file_exists(public_path($brand->logo))) {
                    unlink(public_path($brand->logo));
                }
                $brand->delete();
            }

            return response()->json([
                'status' => true,
                'message' => 'Brands deleted successfully.',
                'data' => BrandResource::collection($brands)
            ]);
        } catch (Exception $e) {
            Log::error('Error deleting brands: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete brands.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    private function deleteOldLogo(?string $path): void
    {
        if ($path && file_exists(public_path($path))) {
            @unlink(public_path($path));
        }
    }
}
