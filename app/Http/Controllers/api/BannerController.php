<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use App\Models\Banner;
use App\Http\Resources\BannerResource;

class BannerController extends Controller
{
    public function index()
    {
        return BannerResource::collection(Banner::all());
    }

    public function storeBanner(Request $request)
    {
        $request->validate([
            'title'  => 'nullable|string',
            'image'  => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
            'link'   => 'nullable|string',
            'status' => 'nullable|boolean',
        ]);

        // Save the uploaded image to public/banner_images
        $imageName = time() . '_' . $request->file('image')->getClientOriginalName();
        $request->file('image')->move(public_path('banner_images'), $imageName);

        $banner = Banner::create([
            'title'  => $request->title,
            'image'  => 'banner_images/' . $imageName,
            'link'   => $request->link,
            'status' => $request->has('status') ? (int) $request->status : 1, // Default: 1 (active)
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Banner created successfully',
            'banner'  => new \App\Http\Resources\BannerResource($banner),
        ], 201);
    }



    public function show($id)
    {
        $banner = Banner::findOrFail($id);
        return new BannerResource($banner);
    }

    // Update banner
    public function updateBanner(Request $request, $id)
    {
        $banner = Banner::findOrFail($id);

        $request->validate([
            'title'  => 'nullable|string',
            'image'  => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'link'   => 'nullable|string',
            'status' => 'nullable',
        ]);

        if ($request->filled('title')) {
            $banner->title = $request->title;
        }

        if ($request->filled('link')) {
            $banner->link = $request->link;
        }

        // ✅ FIXED: Update even when status = 0
        if ($request->has('status') && $request->status !== null) {
            $banner->status = (int) $request->status;
        }

        if ($request->hasFile('image')) {
            if ($banner->image && file_exists(public_path($banner->image))) {
                unlink(public_path($banner->image));
            }

            $imageName = time() . '_' . $request->file('image')->getClientOriginalName();
            $request->file('image')->move(public_path('banner_images'), $imageName);
            $banner->image = 'banner_images/' . $imageName;
        }

        $banner->save();
        $banner->refresh();

        return response()->json([
            'status' => true,
            'message' => 'Banner updated successfully',
            'banner'  => new \App\Http\Resources\BannerResource($banner),
        ], 201);
    }


    public function destroyBanner($id)
    {
        $banner = Banner::find($id);
    
        if (!$banner) {
            return response()->json([
                'status' => false,
                'message' => 'Banner not found',
            ], 404);
        }
    
        if ($banner->image && file_exists(public_path($banner->image))) {
            unlink(public_path($banner->image));
        }
    
        $banner->delete();
    
        return response()->json([
            'status' => true,
            'message' => 'Banner deleted successfully',
        ], 200);
    }

}
