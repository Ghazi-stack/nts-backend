<?php

namespace App\Http\Controllers\api\Admin;

use App\Models\Category;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Missing store-id in headers.'
            ], 400);
        }

        $perPage = $request->query('perPage', 50);

        $categories = Category::with(['childrenRecursive', 'products'])
            ->where('store_id', $storeId)
            ->whereNull('parent_id')
            ->paginate($perPage);

        $data = $categories->getCollection()->map(function ($category) {
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
        ]);
    }

    private function formatCategoryWithCounts($category)
    {
        $directProducts = $category->products->count();
        $subProducts = $this->countProductsRecursive($category);

        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'is_visible' => $category->is_visible,
            'parent_id' => $category->parent_id,
            'total_products' => $directProducts + $subProducts,
            'direct_products' => $directProducts,
            'in_subcategories' => $subProducts,
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

    public function show($id)
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json(['status' => false, 'message' => 'Missing store-id in headers.'], 400);
        }

        try {
            $category = Category::with(['childrenRecursive', 'products'])
                ->where('store_id', $storeId)
                ->findOrFail($id);

            return response()->json([
                'status' => true,
                'category' => new CategoryResource($category),
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => false, 'message' => 'Category not found'], 404);
        }
    }

    public function showBySlug($slug)
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json(['status' => false, 'message' => 'Missing store-id in headers.'], 400);
        }

        $category = Category::where('store_id', $storeId)
            ->where('slug', $slug)
            ->with(['childrenRecursive', 'products'])
            ->first();

        if (!$category) {
            return response()->json(['status' => false, 'message' => 'Category not found'], 404);
        }

        return response()->json([
            'status' => true,
            'category' => new CategoryResource($category),
        ]);
    }

    public function storeCategory(Request $request)
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json(['status' => false, 'message' => 'Missing store-id in headers.'], 400);
        }

        $request->validate([
            'name'        => 'required|string|max:255|unique:categories,name',
            'description' => 'nullable|string',
            'parentId'    => 'nullable|exists:categories,id',
            'isVisible'   => 'nullable|boolean',
        ]);

        $slug = Str::slug($request->name);

        if (Category::where('slug', $slug)->where('store_id', $storeId)->exists()) {
            return response()->json(['status' => false, 'message' => 'Slug already exists.'], 422);
        }

        $category = Category::create([
            'name' => $request->name,
            'slug' => $slug,
            'description' => $request->description,
            'parent_id' => $request->parentId,
            'is_visible' => $request->boolean('isVisible', true),
            'store_id' => $storeId,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Category created successfully',
            'category' => new CategoryResource($category->load(['childrenRecursive', 'products'])),
        ], 201);
    }

    public function updateSingleCategory(Request $request, $id)
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json(['status' => false, 'message' => 'Missing store-id in headers.'], 400);
        }

        try {
            $category = Category::where('store_id', $storeId)->findOrFail($id);

            $request->validate([
                'name' => 'required|string|max:255|unique:categories,name,' . $id,
                'description' => 'nullable|string',
                'parentId' => 'nullable|exists:categories,id',
                'isVisible' => 'nullable|boolean',
            ]);

            $slug = Str::slug($request->name);

            if (Category::where('slug', $slug)
                ->where('id', '!=', $id)
                ->where('store_id', $storeId)
                ->exists()) {
                return response()->json(['status' => false, 'message' => 'Slug already exists.'], 422);
            }

            $category->update([
                'name' => $request->name,
                'slug' => $slug,
                'description' => $request->description,
                'parent_id' => $request->parentId,
                'is_visible' => $request->boolean('isVisible', $category->is_visible),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Category updated successfully',
                'category' => new CategoryResource($category->load(['childrenRecursive', 'products'])),
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => false, 'message' => 'Category not found'], 404);
        }
    }

    public function updateCategoryExtras(Request $request, $id)
    {
        $storeId = current_store_id();
        if (!$storeId) {
            return response()->json(['status' => false, 'message' => 'Missing store-id in headers.'], 400);
        }

        try {
            $category = Category::where('store_id', $storeId)->findOrFail($id);

            // Validate only "extras" (no name/slug changes here)
            $request->validate([
                'image'               => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
                'seoHomeTitle'        => 'nullable|string|max:70',
                'seoMetakeywords'     => 'nullable|string|max:255',
                'seoMetaDescription'  => 'nullable|string|max:160',
                'seoSearchKeywords'   => 'nullable|string|max:255',
                'templateLayout'      => 'nullable|string|max:100',
                'sortOrder'           => 'nullable|integer',
                'defaultProductSort'  => 'nullable|string|max:100',
                'isVisible'           => 'nullable|boolean',
                'channel'             => 'nullable|string|max:100',
            ]);

            // Map only provided fields
            $data = [
                'seo_home_title'       => $request->seoHomeTitle,
                'seo_meta_keywords'    => $request->seoMetakeywords,
                'seo_meta_description' => $request->seoMetaDescription,
                'seo_search_keywords'  => $request->seoSearchKeywords,
                'template_layout'      => $request->templateLayout,
                'sort_order'           => $request->sortOrder,
                'default_product_sort' => $request->defaultProductSort,
                'channel'              => $request->channel,
            ];

            if ($request->filled('isVisible')) {
                $data['is_visible'] = $request->boolean('isVisible');
            }

            // Handle image upload (publicly accessible in /public)
            if ($request->hasFile('image')) {
                $destinationPath = public_path("category_images/{$storeId}");
                if (!file_exists($destinationPath)) {
                    mkdir($destinationPath, 0755, true);
                }

                $original  = $request->file('image')->getClientOriginalName();
                $safeName  = preg_replace('/[^A-Za-z0-9.\-_]/', '_', $original);
                $imageName = time() . '_' . $safeName;

                $request->file('image')->move($destinationPath, $imageName);

                // Remove previous image if exists
                if (!empty($category->image)) {
                    $old = public_path($category->image);
                    if (file_exists($old)) {
                        @unlink($old);
                    }
                }

                $relativePath = "category_images/{$storeId}/{$imageName}";
                $data['image'] = $relativePath;
            }

            // Update only non-null values
            $category->update(array_filter($data, fn ($v) => !is_null($v)));

            // Build a full URL for convenience in the response
            $imageUrl = $category->image ? url($category->image) : null;

            return response()->json([
                'status'     => true,
                'message'    => 'Category extras updated successfully',
                'category'   => new CategoryResource(
                    $category->fresh()->load(['childrenRecursive', 'products'])
                ),
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => false, 'message' => 'Category not found'], 404);
        }
    }

    public function updateBulkCategory(Request $request)
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json(['status' => false, 'message' => 'Missing store-id in headers.'], 400);
        }

        $request->validate([
            'categories' => 'required|array|min:1',
            'categories.*.id' => 'required|exists:categories,id',
            'categories.*.name' => 'required|string|max:255',
            'categories.*.description' => 'nullable|string',
            'categories.*.parentId' => 'nullable|exists:categories,id',
            'categories.*.isVisible' => 'nullable|boolean',
        ]);

        $updated = [];

        foreach ($request->categories as $data) {
            try {
                $category = Category::where('store_id', $storeId)->findOrFail($data['id']);

                // $slug = Str::slug($data['name']);

                // if (Category::where('slug', $slug)
                //     ->where('id', '!=', $data['id'])
                //     ->where('store_id', $storeId)
                //     ->exists()) {
                //     return response()->json([
                //         'status' => false,
                //         'message' => "Slug already exists for: {$data['name']}"
                //     ], 422);
                // }

                $category->update([
                    'name' => $data['name'],
                    // 'slug' => $slug,
                    'description' => $data['description'] ?? null,
                    'parent_id' => $data['parentId'] ?? null,
                    'is_visible' => isset($data['isVisible']) ? (bool)$data['isVisible'] : $category->is_visible,
                ]);

                $updated[] = new CategoryResource($category->load(['childrenRecursive', 'products']));
            } catch (ModelNotFoundException $e) {
                return response()->json(['status' => false, 'message' => "Category not found: ID {$data['id']}"], 404);
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Bulk categories updated successfully',
            'categories' => $updated,
        ]);
    }


    public function destroyCategory(Request $request)
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json(['status' => false, 'message' => 'Missing store-id in headers.'], 400);
        }

        $request->validate([
            'ids' => 'required|array',
        ]);

        $deleted = [];
        $notFound = [];

        foreach ($request->ids as $id) {
            $category = Category::where('store_id', $storeId)->find($id);
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
            'notFoundIds' => $notFound,
        ]);
    }
}
