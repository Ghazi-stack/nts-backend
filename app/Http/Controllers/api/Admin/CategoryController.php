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


        $categories = Category::with(['childrenRecursive', 'products'])
            ->where('store_id', $storeId)
            ->whereNull('parent_id')
            ->get();

        $data = $categories->getCollection()->map(function ($category) {
            return $this->formatCategoryWithCounts($category);
        });

        return response()->json([
            'status' => true,
            'message' => 'Categories retrieved successfully',
            'data' => $data,
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

            // simple validation only (no unique rules)
            $request->validate([
                'name'                => 'nullable|string|max:255',
                'slug'                => 'nullable|string|max:255',
                'description'         => 'nullable|string',
                'parentId'            => 'nullable|integer',   // remove exists if you want zero checks
                'isVisible'           => 'nullable|boolean',
                'channel'             => 'nullable|string|max:100',

                'templateLayout'      => 'nullable|string|max:100',
                'sortOrder'           => 'nullable|integer',
                'defaultProductSort'  => 'nullable|string|max:100',

                'seoHomeTitle'        => 'nullable|string|max:70',
                'seoMetakeywords'     => 'nullable|string|max:255',
                'seoMetaDescription'  => 'nullable|string|max:160',
                'seoSearchKeywords'   => 'nullable|string|max:255',

                'image'               => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            ]);

            // figure out slug (no uniqueness check)
            $finalSlug = $category->slug;
            if ($request->filled('slug')) {
                $finalSlug = Str::slug($request->slug);
            } elseif ($request->filled('name')) {
                $finalSlug = Str::slug($request->name);
            }

            // payload: only set fields that were sent
            $data = [];

            if ($request->filled('name'))               $data['name'] = $request->name;
            if ($finalSlug !== $category->slug)         $data['slug'] = $finalSlug;

            if ($request->exists('description'))        $data['description'] = $request->description;
            if ($request->exists('parentId'))           $data['parent_id'] = $request->parentId;
            if ($request->filled('isVisible'))          $data['is_visible'] = $request->boolean('isVisible');
            if ($request->exists('channel'))            $data['channel'] = $request->channel;

            if ($request->exists('templateLayout'))     $data['template_layout'] = $request->templateLayout;
            if ($request->exists('sortOrder'))          $data['sort_order'] = $request->sortOrder;
            if ($request->exists('defaultProductSort')) $data['default_product_sort'] = $request->defaultProductSort;

            if ($request->exists('seoHomeTitle'))       $data['seo_home_title'] = $request->seoHomeTitle;
            if ($request->exists('seoMetakeywords'))    $data['seo_meta_keywords'] = $request->seoMetakeywords;
            if ($request->exists('seoMetaDescription')) $data['seo_meta_description'] = $request->seoMetaDescription;
            if ($request->exists('seoSearchKeywords'))  $data['seo_search_keywords'] = $request->seoSearchKeywords;

            // image (public)
            if ($request->hasFile('image')) {
                $destinationPath = public_path("category_images/{$storeId}");
                if (!file_exists($destinationPath)) {
                    mkdir($destinationPath, 0755, true);
                }

                $original  = $request->file('image')->getClientOriginalName();
                $safeName  = preg_replace('/[^A-Za-z0-9.\-_]/', '_', $original);
                $imageName = time() . '_' . $safeName;

                $request->file('image')->move($destinationPath, $imageName);

                if (!empty($category->image)) {
                    $old = public_path($category->image);
                    if (file_exists($old)) @unlink($old);
                }

                $data['image'] = "category_images/{$storeId}/{$imageName}";
            }

            if (!empty($data)) {
                $category->update($data);
            }

            return response()->json([
                'status'   => true,
                'message'  => 'Category updated successfully',
                'category' => new CategoryResource(
                    $category->fresh()->load(['childrenRecursive','products'])
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
