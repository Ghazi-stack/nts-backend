<?php

namespace App\Http\Controllers\api\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Http\Resources\BannerResource;
use App\Models\Banner;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class BannerController extends Controller
{
    public function index(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.'
                ], 400);
            }

            $perPage = $request->query('perPage', 20);
            $banners = Banner::with('store')
                ->where('store_id', $storeId)
                ->latest()
                ->paginate($perPage);

            return response()->json([
                'status'  => true,
                'message' => 'Banners retrieved successfully',
                'data'    => BannerResource::collection($banners),
                'pagination' => [
                    'total'       => $banners->total(),
                    'count'       => $banners->count(),
                    'perPage'     => $banners->perPage(),
                    'currentPage' => $banners->currentPage(),
                    'totalPages'  => $banners->lastPage(),
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching banners: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve banners',
            ], 500);
        }
    }

    public function storeBanner(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.'
                ], 400);
            }

            $request->validate([
                'title'        => 'required|string|max:255',
                'pageContent'  => 'nullable|string',
                'locationType' => 'required|in:homepage,category,brand,search',
                'locationId'   => 'nullable|integer|required_if:locationType,category,brand',
                'dateType'     => 'nullable|in:always,range',
                'from'         => 'nullable|required_if:dateType,range|date',
                'through'      => 'nullable|required_if:dateType,range|date|after_or_equal:from',
                'visible'      => 'nullable|boolean',
                'placement'    => 'required|in:top,bottom',
            ]);

            $banner = Banner::create([
                'store_id'     => $storeId,
                'title'        => $request->title,
                'page_content' => $request->pageContent,
                'location'     => $this->buildLocation($request),
                'date_range'   => $this->buildDateRange($request),
                'visible'      => $request->boolean('visible', true),
                'placement'    => $request->placement,
            ]);

            return response()->json([
                'status'  => true,
                'message' => 'Banner created successfully',
                'banner'  => new BannerResource($banner),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error creating banner: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to create banner',
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.'
                ], 400);
            }

            $banner = Banner::where('store_id', $storeId)->findOrFail($id);

            return response()->json([
                'status'  => true,
                'message' => 'Banner retrieved successfully',
                'banner'  => new BannerResource($banner),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Banner not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error fetching banner: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve banner',
            ], 500);
        }
    }

    public function updateBanner(Request $request, $id)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.'
                ], 400);
            }

            $banner = Banner::where('store_id', $storeId)->findOrFail($id);

            $request->validate([
                'title'        => 'required|string|max:255',
                'pageContent'  => 'nullable|string',
                'locationType' => 'required|in:homepage,category,brand,search',
                'locationId'   => 'nullable|integer|required_if:locationType,category,brand',
                'dateType'     => 'nullable|in:always,range',
                'from'         => 'nullable|required_if:dateType,range|date',
                'through'      => 'nullable|required_if:dateType,range|date|after_or_equal:from',
                'visible'      => 'nullable|boolean',
                'placement'    => 'required|in:top,bottom',
            ]);

            $banner->update([
                'title'        => $request->title,
                'page_content' => $request->pageContent,
                'location'     => $this->buildLocation($request),
                'date_range'   => $this->buildDateRange($request),
                'visible'      => $request->boolean('visible', true),
                'placement'    => $request->placement,
            ]);

            return response()->json([
                'status'  => true,
                'message' => 'Banner updated successfully',
                'banner'  => new BannerResource($banner->fresh()),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Banner not found',
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error updating banner: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to update banner',
            ], 500);
        }
    }

    public function destroyBanner($id)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.'
                ], 400);
            }

            $banner = Banner::where('store_id', $storeId)->findOrFail($id);
            $banner->delete();

            return response()->json([
                'status'  => true,
                'message' => 'Banner deleted successfully',
                'data'    => $banner,
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Banner not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error deleting banner: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to delete banner',
            ], 500);
        }
    }

    private function buildLocation(Request $request): array
    {
        $type = strtolower($request->locationType);

        if ($type === 'homepage') {
            return ['key' => 'Homepage'];
        }

        if ($type === 'category') {
            return [
                'key' => 'category',
                'id'  => (int) $request->locationId,
            ];
        }

        if ($type === 'brand') {
            return [
                'key' => 'brand',
                'id'  => (int) $request->locationId,
            ];
        }

        return ['key' => 'search'];
    }

    private function buildDateRange(Request $request)
    {
        if (!$request->dateType || $request->dateType === 'always') {
            return 0;
        }

        return json_encode([
            'from'    => $request->from,
            'through' => $request->through,
        ]);
    }
}
