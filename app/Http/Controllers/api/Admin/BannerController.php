<?php

namespace App\Http\Controllers\API\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
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
            $perPage = $request->query('perPage', 20);
            $banners = Banner::with('store') // 🟢 Eager load store
                ->where('store_id', current_store_id())
                ->latest()
                ->paginate($perPage);
                
            return response()->json([
                'status' => true,
                'message' => 'Banners retrieved successfully',
                'data' => BannerResource::collection($banners),
                'pagination' => [
                    'total' => $banners->total(),
                    'count' => $banners->count(),
                    'perPage' => $banners->perPage(),
                    'currentPage' => $banners->currentPage(),
                    'totalPages' => $banners->lastPage(),
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching banners: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve banners',
            ], 500);
        }
    }

    public function storeBanner(Request $request)
    {
        try {
            $request->validate([
                'title'  => 'nullable|string',
                'image'  => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
                'link'   => 'nullable|string',
                'status' => 'nullable|boolean',
            ]);

            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json(['error' => 'Store not selected.'], 400);
            }

            $destinationPath = public_path('banner_images');
            if (!file_exists($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }

            $imageName = time() . '_' . preg_replace('/[^A-Za-z0-9.\-_]/', '_', $request->file('image')->getClientOriginalName());
            $request->file('image')->move($destinationPath, $imageName);

            $banner = Banner::create([
                'store_id' => $storeId,
                'title'    => $request->title,
                'image'    => 'banner_images/' . $imageName,
                'link'     => $request->link,
                'status'   => $request->has('status') ? (int) $request->status : 1,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Banner created successfully',
                'banner' => new BannerResource($banner),
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating banner: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to create banner',
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $banner = Banner::where('store_id', current_store_id())->findOrFail($id);
            return response()->json([
                'status' => true,
                'message' => 'Banner retrieved successfully',
                'banner' => new BannerResource($banner),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Banner not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error fetching banner: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve banner',
            ], 500);
        }
    }

    public function updateBanner(Request $request, $id)
    {
        try {
            $banner = Banner::where('store_id', current_store_id())->findOrFail($id);

            $request->validate([
                'title'  => 'nullable|string',
                'image'  => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
                'link'   => 'nullable|string',
                'status' => 'nullable|in:0,1',
            ]);

            if ($request->has('title')) {
                $banner->title = $request->title;
            }

            if ($request->has('link')) {
                $banner->link = $request->link;
            }

            if ($request->has('status')) {
                $banner->status = (int) $request->status;
            }

            if ($request->hasFile('image')) {
                if ($banner->image && file_exists(public_path($banner->image))) {
                    unlink(public_path($banner->image));
                }

                $imageName = time() . '_' . preg_replace('/[^A-Za-z0-9.\-_]/', '_', $request->file('image')->getClientOriginalName());
                $request->file('image')->move(public_path('banner_images'), $imageName);
                $banner->image = 'banner_images/' . $imageName;
            }

            $banner->save();
            $banner->refresh();

            return response()->json([
                'status' => true,
                'message' => 'Banner updated successfully',
                'banner' => new BannerResource($banner),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Banner not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating banner: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to update banner',
            ], 500);
        }
    }

    public function destroyBanner($id)
    {
        try {
            $banner = Banner::where('store_id', current_store_id())->findOrFail($id);

            if ($banner->image && file_exists(public_path($banner->image))) {
                unlink(public_path($banner->image));
            }

            $banner->delete();

            return response()->json([
                'status' => true,
                'message' => 'Banner deleted successfully',
                'data' => $banner
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Banner not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error deleting banner: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete banner',
            ], 500);
        }
    }
}
