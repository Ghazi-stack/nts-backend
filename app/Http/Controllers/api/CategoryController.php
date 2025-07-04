<?php

namespace App\Http\Controllers\API;

use App\Models\Category;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;

class CategoryController extends Controller
{
    /**
     * GET /api/categories
     */
    public function index()
    {
       $categories = Category::all(); 
       if($categories->isEmpty()){
           return response()->json([
               'status' => false,
               'message' => 'No categories found',
           ], 404);
       }
       return response()->json([
           'status' => true,
           'message' => 'Categories retrieved successfully',
           'categories' => CategoryResource::collection($categories),
       ], 200);
    }

    /**
     * GET /api/categories/{id}
     */
    public function show($id)
    {
        // Using try-catch to handle ModelNotFoundException
        try{
            $category = Category::findOrFail($id);
            return response()->json([
                'status' => true,
                'message' => 'Category retrieved successfully',
                'category' => new CategoryResource($category),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Category not found',
            ], 404);
        }
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
        try {
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

             return response()->json([
                'status' => true,
                'message' => 'Category updated successfully',
                'category' => new CategoryResource($category),
            ], 200);
    } catch (ModelNotFoundException $e) {
        return response()->json([
            'status' => false,
            'message' => 'Category not found',
            ], 404);
        }
    }

    /**
     * DELETE /api/categories/{id}
     */
    public function destroyCategory($id)
    {
        try {
            $category = Category::findOrFail($id);
            $category->delete();

            return response()->json([
                'status' => true,
                'message' => 'Category deleted successfully',
                'category' => $category,
            ],200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Category not found',
            ], 404);
        }
    }
}
