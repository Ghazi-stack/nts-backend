<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Http\Resources\BrandResource;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    public function index()
    {
        return BrandResource::collection(Brand::all());
    }

    public function show($id)
    {
        $brand = Brand::findOrFail($id);
        return new BrandResource($brand);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'logo' => 'nullable|string',
        ]);

        $brand = Brand::create($request->only(['name', 'logo']));
        return new BrandResource($brand);
    }

    public function update(Request $request, $id)
    {
        $brand = Brand::findOrFail($id);
        $brand->update($request->only(['name', 'logo']));
        return new BrandResource($brand);
    }

    public function destroy($id)
    {
        $brand = Brand::findOrFail($id);
        $brand->delete();
        return response()->json(['message' => 'Brand deleted successfully']);
    }
}
