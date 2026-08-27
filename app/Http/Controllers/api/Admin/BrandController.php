<?php

namespace App\Http\Controllers\api\Admin;

use Exception;
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Http\Resources\BrandResource;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

            $perPage = (int) $request->query('perPage', 20);
            $keyword = trim((string) $request->query('keyword', ''));

            $query = Brand::query()
                ->withCount(['products' => function ($q) use ($storeId) {
                    $q->where('store_id', $storeId);
                }])
                ->where('store_id', $storeId);

            if ($keyword !== '') {
                $query->where('name', 'like', "%{$keyword}%")
                    ->orderByRaw("CASE WHEN name LIKE ? THEN 0 ELSE 1 END", ["{$keyword}%"]);
            } else {
                $query->latest('id');
            }

            $brands = $query->paginate($perPage);

            $data = $brands->getCollection()->map(function ($brand) {
                return [
                    'brand' => new BrandResource($brand),
                    'productsCount' => $brand->products_count ?? 0,
                ];
            });

            return response()->json([
                'status' => true,
                'message' => 'Brands retrieved successfully',
                'data' => $data->values(),
                'pagination' => [
                    'total'       => $brands->total(),
                    'count'       => $brands->count(),
                    'perPage'     => $brands->perPage(),
                    'currentPage' => $brands->currentPage(),
                    'totalPages'  => $brands->lastPage(),
                ]
            ], 200);
        } catch (Exception $e) {
            Log::error('Error fetching brands: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve brands',
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
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
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('brands', 'name')->where(function ($query) use ($storeId) {
                        return $query->where('store_id', $storeId);
                    }),
                ],
                'logo' => 'nullable|image|mimes:jpg,jpeg,png,svg,webp|max:2048',
            ], [
                'name.required' => 'Brand name is required.',
                'name.unique' => 'Brand already exists.',
                'logo.image' => 'The logo must be an image.',
                'logo.mimes' => 'The logo must be a jpg, jpeg, png, svg, or webp file.',
                'logo.max' => 'The logo may not be greater than 2MB.',
            ]);

            $data = [
                'store_id' => $storeId,
                'name' => $request->name,
            ];

            if ($request->hasFile('logo')) {

                $file = $request->file('logo');

                $name = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();

                $path = Storage::disk('s3')->putFileAs(
                    'brand_logos',
                    $file,
                    $name
                );

                $data['logo'] = Storage::disk('s3')->url($path);
            }

            $brand = Brand::create($data);

            return response()->json([
                'status' => true,
                'message' => 'Brand created successfully',
                'brand' => new BrandResource($brand),
            ], 201);
        } catch (ValidationException $e) {

            return response()->json([
                'status' => false,
                'message' => $e->validator->errors()->first(),
            ], 422);
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

            if ($request->hasFile('logo')) {

                // Delete old logo from S3
                $this->deleteOldLogoFromS3($brand->logo);

                $file = $request->file('logo');

                $name = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();

                $path = Storage::disk('s3')->putFileAs(
                    'brand_logos',
                    $file,
                    $name
                );

                $brand['logo'] = Storage::disk('s3')->url($path);
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
                'message' => 'Failed to update brand.',
                'error'    => $e->getMessage(),
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

            $idArray = explode(',', $ids);

            if (empty($idArray)) {
                return response()->json([
                    'status' => false,
                    'message' => 'No brand IDs provided.'
                ], 422);
            }

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
                // products table se brand unlink
                \DB::table('products')
                    ->where('brand_id', $brand->id)
                    ->update(['brand_id' => null]);

                // S3 se logo delete
                $this->deleteOldLogoFromS3($brand->logo);

                // brand delete
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


    private function deleteOldLogoFromS3(?string $logoUrl): void
    {
        if (!$logoUrl) return;

        $path = parse_url($logoUrl, PHP_URL_PATH);
        $path = ltrim($path, '/');

        if ($path) {
            Storage::disk('s3')->delete($path);
        }
    }
}
