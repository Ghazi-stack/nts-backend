<?php

namespace App\Http\Controllers\API;

use App\Models\Category;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class CategoryController extends Controller
{
   // GET /categories/list-category
    public function index()
    {
        $categories = Category::with('children.children.children')->whereNull('parent_id')->get();

        if ($categories->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No categories found',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'categories' => CategoryResource::collection($categories),
        ]);
    }

    // GET /categories/category/{id}
    public function show($id)
    {
        try {
            $category = Category::with('children')->findOrFail($id);

            return response()->json([
                'status' => true,
                'category' => new CategoryResource($category),
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => false, 'message' => 'Category not found'], 404);
        }
    }

    // GET /categories/slug/{slug}
    public function showBySlug($slug)
    {
        $category = Category::with('children')->where('slug', $slug)->first();

        if (!$category) {
            return response()->json(['status' => false, 'message' => 'Category not found'], 404);
        }

        return response()->json([
            'status' => true,
            'category' => new CategoryResource($category),
        ]);
    }

    // POST /categories/add-categories
    public function storeCategory(Request $request)
    {
        $request->validate([
            'name' => 'required|string|unique:categories,name',
            'description' => 'nullable|string',
            'parentId' => 'nullable|exists:categories,id',
        ]);

        $slug = Str::slug($request->name);

        if (Category::where('slug', $slug)->exists()) {
            return response()->json(['status' => false, 'message' => 'Slug already exists.'], 422);
        }

        $category = Category::create([
            'name' => $request->name,
            'slug' => $slug,
            'description' => $request->description,
            'parent_id' => $request->parentId,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Category created successfully',
            'category' => new CategoryResource($category),
        ], 201);
    }

    // PUT /categories/update-category/{id}
    public function updateCategory(Request $request, $id)
    {
        try {
            $category = Category::findOrFail($id);

            $request->validate([
                'name' => 'required|string|max:255|unique:categories,name,' . $id,
                'description' => 'nullable|string',
                'parentId' => 'nullable|exists:categories,id',
            ]);

            $slug = Str::slug($request->name);

            if (Category::where('slug', $slug)->where('id', '!=', $id)->exists()) {
                return response()->json(['status' => false, 'message' => 'Slug already exists.'], 422);
            }

            $category->update([
                'name' => $request->name,
                'slug' => $slug,
                'description' => $request->description,
                'parent_id' => $request->parentId,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Category updated successfully',
                'category' => new CategoryResource($category),
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => false, 'message' => 'Category not found'], 404);
        }
    }

    // DELETE /categories/delete-category/{id}
    public function destroyCategory($id)
    {
        try {
            $category = Category::findOrFail($id);
            $category->delete();

            return response()->json([
                'status' => true,
                'message' => 'Category deleted successfully',
                'data' => new CategoryResource($category)
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => false, 'message' => 'Category not found'], 404);
        }
    }

    public function destroyOnlySubcategories($id)
    {
        try {
            $category = Category::with('childrenRecursive')->findOrFail($id);

            $this->deleteChildren($category); // Delete only children

            // 🔁 Reload category to refresh its children (now deleted)
            $category->refresh(); // reload from DB
            $category->load('childrenRecursive');

            return response()->json([
                'status' => true,
                'message' => 'Subcategories deleted successfully',
                'data' => new CategoryResource($category),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => false, 'message' => 'Category not found'], 404);
        }
    }
    // Helper method for recursion
    private function deleteChildren($category)
    {
        foreach ($category->childrenRecursive as $child) {
            $this->deleteChildren($child);
            $child->delete();
        }
    }

}
