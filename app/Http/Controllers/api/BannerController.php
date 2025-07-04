<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller; // ✅ Correct base controller
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\BannerResource;
use App\Models\Banner;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class BannerController extends Controller
{
    public function index()
    {
        try {
            return BannerResource::collection(Banner::all());
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

            $imageName = time() . '_' . preg_replace('/[^A-Za-z0-9.\-_]/', '_', $request->file('image')->getClientOriginalName());
            $request->file('image')->move(public_path('banner_images'), $imageName);

            $banner = Banner::create([
                'title'  => $request->title,
                'image'  => 'banner_images/' . $imageName,
                'link'   => $request->link,
                'status' => $request->has('status') ? (int) $request->status : 1,
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
            $banner = Banner::findOrFail($id);
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
            $banner = Banner::findOrFail($id);

            $request->validate([
                'title'  => 'nullable|string',
                'image'  => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
                'link'   => 'nullable|string',
                'status' => 'nullable|in:0,1',
            ]);

            Log::info('🔍 Before update:', $banner->toArray());
            Log::info('📥 Request data:', $request->all());

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

            Log::info('✅ After update:', $banner->toArray());

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
            $banner = Banner::findOrFail($id);

            if ($banner->image && file_exists(public_path($banner->image))) {
                unlink(public_path($banner->image));
            }

            $banner->delete();

            return response()->json([
                'status' => true,
                'message' => 'Banner deleted successfully',
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
