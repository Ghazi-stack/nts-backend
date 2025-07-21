<?php

namespace App\Http\Controllers\API\Admin;
use App\Models\Category;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class CategoryController extends Controller
{
    // GET /categories/list-category
    public function index(Request $request)
    {
        $perPage = $request->query('perPage', 50);

        $categories = Category::with(['childrenRecursive', 'products'])
            ->where('store_id', current_store_id())
            ->whereNull('parent_id')
            ->paginate($perPage);


        if ($categories->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No categories found',
            ], 200);
        }

        $data = $categories->map(function ($category) {
            return $this->formatCategoryWithCounts($category);
        });

        return response()->json([
            'status' => true,
            'message' => 'Categories retrieved successfully',
            'data' => $data,
            'pagination' => [
                'total' => $categories->total(),
                'count' => $categories->count(),
                'perPage' => $categories->perPage(),
                'currentPage' => $categories->currentPage(),
                'totalPages' => $categories->lastPage(),
            ]
        ], 200);
    }

    private function formatCategoryWithCounts($category)
    {
        $directProducts = $category->products->count();
        $subCategoryProducts = $this->countProductsRecursive($category);

        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'is_visible' => $category->is_visible,
            'parent_id' => $category->parent_id,
            'total_products' => $directProducts + $subCategoryProducts,
            'direct_products' => $directProducts,
            'in_subcategories' => $subCategoryProducts,
            'subcategories' => $category->childrenRecursive->map(function ($child) {
                return $this->formatCategoryWithCounts($child);
            })
        ];
    }

    private function countProductsRecursive($category)
    {
        $count = 0;

        foreach ($category->childrenRecursive as $child) {
            $count += $child->products->count();
            $count += $this->countProductsRecursive($child);
        }

        return $count;
    }

    // GET /categories/category/{id}
    public function show($id)
    {
        try {
            $category = Category::with(['childrenRecursive', 'products'])->findOrFail($id);

            return response()->json([
                'status' => true,
                'category' => new CategoryResource($category),
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => false, 'message' => 'Category not found'], 200);
        }
    }

    // GET /categories/slug/{slug}
    public function showBySlug($slug)
    {
        $category = Category::with(['childrenRecursive', 'products'])->where('slug', $slug)->first();

        if (!$category) {
            return response()->json(['status' => false, 'message' => 'Category not found'], 200);
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
            'name'        => 'required|string|unique:categories,name',
            'description' => 'nullable|string',
            'parentId'    => 'nullable|exists:categories,id',
            'isVisible'   => 'nullable|boolean',
        ]);

        $slug = Str::slug($request->name);

        if (Category::where('slug', $slug)->exists()) {
            return response()->json(['status' => false, 'message' => 'Slug already exists.'], 422);
        }

        $category = Category::create([
            'name'        => $request->name,
            'slug'        => $slug,
            'description' => $request->description,
            'parent_id'   => $request->parentId,
            'is_visible'  => $request->boolean('isVisible', false),
            'store_id'    => current_store_id(), // 👈
        ]);

        $category->load(['childrenRecursive', 'products']);

        return response()->json([
            'status'   => true,
            'message'  => 'Category created successfully',
            'category' => ($category),
        ], 201);
    }

    // PUT /categories/update-category/{id}
    public function updateCategory(Request $request, $id)
    {
        try {
            $category = Category::where('store_id', current_store_id())->find($id);

            $request->validate([
                'name'        => 'required|string|max:255|unique:categories,name,' . $id,
                'description' => 'nullable|string',
                'parentId'    => 'nullable|exists:categories,id',
                'isVisible'   => 'nullable|boolean',
            ]);

            $slug = Str::slug($request->name);

            if (Category::where('slug', $slug)->where('id', '!=', $id)->exists()) {
                return response()->json(['status' => false, 'message' => 'Slug already exists.'], 422);
            }

            $category->update([
                'name'        => $request->name,
                'slug'        => $slug,
                'description' => $request->description,
                'parent_id'   => $request->parentId,
                'is_visible'  => $request->boolean('isVisible', $category->is_visible),
            ]);

            $category->load(['childrenRecursive', 'products']);

            return response()->json([
                'status'   => true,
                'message'  => 'Category updated successfully',
                'category' => new CategoryResource($category),
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => false, 'message' => 'Category not found'], 404);
        }
    }

    // DELETE /categories/delete-category/{id}
    public function destroyCategory(Request $request)
    {
        $request->validate([
            'ids' => 'required',
        ]);

        $ids = is_array($request->ids) ? $request->ids : [$request->ids];

        $deleted = [];
        $notFound = [];

        foreach ($ids as $id) {
            $category = Category::where('store_id', current_store_id())->find($id);
            if ($category) {
                $category->delete();
                $deleted[] = $id;
            } else {
                $notFound[] = $id;
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Delete process completed.',
            'deletedIds' => $deleted,
            'notFoundIds' => $notFound
        ]);
    }
}
