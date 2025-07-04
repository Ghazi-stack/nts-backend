<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Category;
use App\Http\Resources\CategoryResource;

class CategoryController extends Controller
{
    /**
     * GET /api/categories
     */
    public function index()
    {
        return CategoryResource::collection(Category::all());
    }

    /**
     * GET /api/categories/{id}
     */
    public function show($id)
    {
        $category = Category::findOrFail($id);
        return response()->json([
            'status' => true,
            'category' => 'Category retrieved successfully',
            'category' => new CategoryResource($category),
        ]);
    }

    /**
     * OPTIONAL: GET /api/categories/slug/{slug}
     */
    public function showBySlug($slug)
    {
        $category = Category::where('slug', $slug)->firstOrFail();
        return new CategoryResource($category);
    }

    /**
     * POST /api/categories/add
     */
    public function storeCategory(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:categories,name',
            'description' => 'nullable|string',
        ]);

        $slug = Str::slug($request->name);

        if (Category::where('slug', $slug)->exists()) {
            return response()->json([
                'message' => 'Slug already exists. Try a different category name.'
            ], 422);
        }

        $category = Category::create([
            'name' => $request->name,
            'slug' => $slug,
            'description' => $request->description,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Category created successfully',
            'category' => new CategoryResource($category),
        ], 201);
    }

    /**
     * PUT /api/categories/{id}
     */
    public function updateCategory(Request $request, $id)
    {
        $category = Category::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255|unique:categories,name,' . $id,
            'description' => 'nullable|string',
        ]);

        $slug = Str::slug($request->name);

        if (Category::where('slug', $slug)->where('id', '!=', $id)->exists()) {
            return response()->json([
                'message' => 'Slug already exists. Try a different category name.'
            ], 422);
        }

        $category->update([
            'name' => $request->name,
            'slug' => $slug,
            'description' => $request->description,
        ]);

        return new CategoryResource($category);
    }

    /**
     * DELETE /api/categories/{id}
     */
    public function destroyCategory($id)
    {
        $category = Category::findOrFail($id);
        $category->delete();

        return response()->json([
            'status' => true,
            'message' => 'Category deleted successfully',
            'category' => $category,
        ],200);
    }
}
