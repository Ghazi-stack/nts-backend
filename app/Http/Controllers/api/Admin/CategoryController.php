<?php

namespace App\Http\Controllers\api\Admin;

use App\Models\Product;
use App\Models\Category;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\PopularProductResource;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;


class CategoryController extends Controller
{
    protected $visited = [];

    // public function index(Request $request)
    // {
    //     $storeId = current_store_id();

    //     if (!$storeId) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Missing store-id in headers.'
    //         ], 400);
    //     }

    //     $categories = Category::with(['childrenRecursive', 'products'])
    //         ->where('store_id', $storeId)
    //         ->whereNull('parent_id')
    //         ->get();

    //     $data = $categories->map(function ($category) {
    //         return $this->formatCategoryWithCounts($category);
    //     })->values(); // optional: reset keys

    //     return response()->json([
    //         'status' => true,
    //         'message' => 'Categories retrieved successfully',
    //         'data' => $data,
    //     ]);
    // }

    public function index(Request $request)
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Missing store-id in headers.'
            ], 400);
        }

        // Reset visited IDs for loop protection
        $this->visited = [];

        // ⚡ Load categories WITHOUT products to avoid huge payloads
        $categories = Category::with(['childrenRecursive'])
            ->withCount('products') // direct product count
            ->where('store_id', "=", $storeId)
            ->whereNull('parent_id')
            ->get();

        // ⚡ Transform each category safely
        $data = $categories->map(function ($category) {
            return $this->formatCategorySafe($category);
        })->filter()->values();

        return response()->json([
            'status' => true,
            'message' => 'Categories retrieved successfully',
            'data' => $data
        ]);
    }

    private function formatCategorySafe($category)
    {
        // 🔥 Prevent infinite repeat loops
        if (isset($this->visited[$category->id])) {
            return null;
        }
        $this->visited[$category->id] = true;

        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'image' => $category->image ? Storage::disk('s3')->url($category->image) : null,
            'description' => $category->description,
            'is_visible' => $category->is_visible,
            'parent_id' => $category->parent_id,

            // SEO fields
            'seo' => [
                'page_title'       => $category->seo_home_title,
                'meta_keywords'    => $category->seo_meta_keywords,
                'meta_description' => $category->seo_meta_description,
                'search_keywords'  => $category->seo_search_keywords,
            ],

            // ✔ Load counts directly (MUCH faster than loading products)
            'direct_products' => $category->products_count ?? 0,
            'total_products' => $category->products_count + ($category->subtree_products_count ?? 0),
            'in_subcategories' => $category->subtree_products_count ?? 0,

            'subcategories' => $category->childrenRecursive
                ->map(fn($child) => $this->formatCategorySafe($child))
                ->filter()
                ->values(),
        ];
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
                // 'name' => [
                //     'required',
                //     'string',
                //     'max:255',
                //     Rule::unique('categories', 'name')
                //         ->ignore($category->id)  // ✅ $id instead of route $id
                //         ->where(fn($query) => $query->where('store_id', $storeId)), // ✅ closure fix
                // ],
                'name' => 'nullable|string',
                'description' => 'nullable|string',
                'parentId' => 'nullable|exists:categories,id',
                'isVisible' => 'nullable|boolean',
            ]);

            // $slug = Str::slug($request->name);

            // if (Category::where('slug', $slug)
            //     ->where('id', '!=', $category->id) // ✅ use $category->id
            //     ->where('store_id', $storeId)
            //     ->exists()
            // ) {
            //     return response()->json(['status' => false, 'message' => 'Slug already exists.'], 422);
            // }

            $category->update([
                'name' => $request->name,
                // 'slug' => $slug,
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
                'parentId'            => 'nullable|integer',
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

            // image (S3)
            if ($request->hasFile('image')) {
                $file = $request->file('image');

                $name = Str::slug(
                    pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)
                );
                $extension = $file->getClientOriginalExtension();
                $fileName = time() . '_' . $name . '.' . $extension;

                $path = Storage::disk('s3')->putFileAs(
                    "category_images/{$storeId}",
                    $file,
                    $fileName
                );

                // Delete old image from S3
                if (!empty($category->image)) {
                    Storage::disk('s3')->delete($category->image);
                }

                $data['image'] = $path;
            }

            if (!empty($data)) {
                $category->update($data);
            }

            return response()->json([
                'status'   => true,
                'message'  => 'Category updated successfully',
                'category' => new CategoryResource(
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

    // public function filter(Request $request)
    // {
    //     try {
    //         $storeId = current_store_id();

    //         if (!$storeId) {
    //             return response()->json([
    //                 'status' => false,
    //                 'message' => 'Missing store-id in headers.',
    //             ], 400);
    //         }

    //         $query = Product::query()
    //             ->where('store_id', $storeId)
    //             ->with(['brand', 'categories', 'images', 'content', 'seo']);

    //         // ✅ Filters
    //         // if ($request->filled('categoryId')) {
    //         //     $query->where('category_id', $request->categoryId);
    //         // }

    //         if ($request->filled('categoryIds')) {
    //             $categoryIds = explode(',', $request->categoryIds); // support multiple IDs
    //             $query->whereHas('categories', function ($q) use ($categoryIds) {
    //                 $q->whereIn('categories.id', $categoryIds);
    //             });
    //         }

    //         if ($request->filled('brandId')) {
    //             $query->where('brand_id', $request->brandId);
    //         }

    //         if ($request->filled('minPrice')) {
    //             $query->where('price', '>=', $request->minPrice);
    //         }

    //         if ($request->filled('maxPrice')) {
    //             $query->where('price', '<=', $request->maxPrice);
    //         }

    //         // ✅ Sorting
    //         if ($request->filled('sortBy')) {
    //             switch ($request->sortBy) {
    //                 case 'priceLowToHigh':
    //                     $query->orderBy('price', 'asc');
    //                     break;
    //                 case 'priceHighToLow':
    //                     $query->orderBy('price', 'desc');
    //                     break;
    //                 case 'latest':
    //                     $query->orderBy('created_at', 'desc');
    //                     break;
    //                 case 'bestSelling':
    //                     $query->where('sold_count','>',0)
    //                         ->orderBy('sold_count','desc');
    //                     break;
    //                 default:
    //                     $query->orderBy('id', 'desc');
    //             }
    //         } else {
    //             $query->orderBy('id', 'desc');
    //         }

    //         // ✅ Pagination
    //         $page = (int) $request->input('page', 1);
    //         $pageSize = (int) $request->input('pageSize', 12);
    //         $products = $query->paginate($pageSize, ['*'], 'page', $page);

    //         // ✅ Clean response with ProductResource + Pagination Meta
    //         return response()->json([
    //             'status' => true,
    //             'message' => 'Filtered products retrieved successfully',
    //             'data' => ProductResource::collection($products),
    //             'pagination' => [
    //                 'total' => $products->total(),
    //                 'page' => $products->currentPage(),
    //                 'pageSize' => $products->perPage(),
    //                 'lastPage' => $products->lastPage()
    //             ]
    //         ]);
    //     } catch (Exception $e) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Something went wrong.',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    //     public function filter(Request $request)
    // {
    //     try {
    //         $storeId = current_store_id();

    //         if (!$storeId) {
    //             return response()->json([
    //                 'status' => false,
    //                 'message' => 'Missing store-id in headers.',
    //             ], 400);
    //         }

    //         $query = Product::query()
    //             ->where('store_id', $storeId)
    //             ->with(['brand', 'categories', 'images', 'content', 'seo']);

    //         // ✅ CATEGORY FILTER
    //         if ($request->filled('categoryIds')) {
    //             $categoryIds = explode(',', $request->categoryIds);

    //             $allCategoryIds = [];
    //             foreach ($categoryIds as $catId) {
    //                 $allCategoryIds[] = (int)$catId; // include main/parent category

    //                 // Fetch children recursively
    //                 $category = Category::with('childrenRecursive')->find($catId);
    //                 if ($category) {
    //                     $allCategoryIds = array_merge($allCategoryIds, $this->getAllChildCategoryIds($category));
    //                 }
    //             }

    //             $query->whereHas('categories', function ($q) use ($allCategoryIds) {
    //                 $q->whereIn('categories.id', $allCategoryIds);
    //             });
    //         }

    //         // ✅ BRAND FILTER
    //         if ($request->filled('brandId')) {
    //             $query->where('brand_id', $request->brandId);
    //         }

    //         // ✅ PRICE FILTERS
    //         if ($request->filled('minPrice')) {
    //             $query->where('price', '>=', $request->minPrice);
    //         }
    //         if ($request->filled('maxPrice')) {
    //             $query->where('price', '<=', $request->maxPrice);
    //         }

    //         // ✅ SORTING
    //         if ($request->filled('sortBy')) {
    //             switch ($request->sortBy) {
    //                 case 'priceLowToHigh': $query->orderBy('price','asc'); break;
    //                 case 'priceHighToLow': $query->orderBy('price','desc'); break;
    //                 case 'latest': $query->orderBy('created_at','desc'); break;
    //                 case 'bestSelling':
    //                     $query->where('sold_count','>',0)->orderBy('sold_count','desc');
    //                     break;
    //                 default: $query->orderBy('id','desc');
    //             }
    //         } else {
    //             $query->orderBy('id','desc');
    //         }

    //         // ✅ PAGINATION
    //         $page = (int) $request->input('page', 1);
    //         $pageSize = (int) $request->input('pageSize', 12);
    //         $products = $query->paginate($pageSize, ['*'], 'page', $page);

    //         return response()->json([
    //             'status' => true,
    //             'message' => 'Filtered products retrieved successfully',
    //             'data' => ProductResource::collection($products),
    //             'pagination' => [
    //                 'total' => $products->total(),
    //                 'page' => $products->currentPage(),
    //                 'pageSize' => $products->perPage(),
    //                 'lastPage' => $products->lastPage()
    //             ]
    //         ]);

    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Something went wrong.',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    // public function filter(Request $request)
    // {
    //     try {
    //         $storeId = current_store_id();

    //         if (!$storeId) {
    //             return response()->json([
    //                 'status' => false,
    //                 'message' => 'Missing store-id in headers.',
    //             ], 400);
    //         }

    //         $query = Product::query()
    //             ->where('store_id', $storeId)
    //             ->with([
    //                 'brand:id,name', 
    //                 'categories:id,name', 
    //                 'images:id,product_id,path,is_primary', 
    //             ]);

    //         // ✅ CATEGORY FILTER
    //         if ($request->filled('categoryIds')) {
    //             $categoryIds = explode(',', $request->categoryIds);

    //             $allCategoryIds = [];
    //             foreach ($categoryIds as $catId) {
    //                 $allCategoryIds[] = (int)$catId; // include main/parent category

    //                 // Fetch children recursively
    //                 $category = Category::with('childrenRecursive')->find($catId);
    //                 if ($category) {
    //                     $allCategoryIds = array_merge($allCategoryIds, $this->getAllChildCategoryIds($category));
    //                 }
    //             }

    //             $query->whereHas('categories', function ($q) use ($allCategoryIds) {
    //                 $q->whereIn('categories.id', $allCategoryIds);
    //             });
    //         }

    //         // ✅ BRAND FILTER
    //         if ($request->filled('brandId')) {
    //             $query->where('brand_id', $request->brandId);
    //         }

    //         // ✅ PRICE FILTERS
    //         if ($request->filled('minPrice')) {
    //             $query->where('price', '>=', $request->minPrice);
    //         }
    //         if ($request->filled('maxPrice')) {
    //             $query->where('price', '<=', $request->maxPrice);
    //         }

    //         // ✅ SORTING
    //         if ($request->filled('sortBy')) {
    //             switch ($request->sortBy) {
    //                 case 'priceLowToHigh': $query->orderBy('price','asc'); break;
    //                 case 'priceHighToLow': $query->orderBy('price','desc'); break;
    //                 case 'latest': $query->orderBy('created_at','desc'); break;
    //                 case 'bestSelling':
    //                     $query->where('sold_count','>',0)->orderBy('sold_count','desc');
    //                     break;
    //                 default: $query->orderBy('id','desc');
    //             }
    //         } else {
    //             $query->orderBy('id','desc');
    //         }

    //          // ✅ LIMIT TOTAL PRODUCTS
    //         $maxProducts = 10000;
    //         $query->limit($maxProducts);

    //         // ✅ PAGINATION
    //         $page = (int) $request->input('page', 1);
    //         $pageSize = (int) $request->input('pageSize', 12);
    //         $products = $query->paginate($pageSize, ['*'], 'page', $page);

    //         return response()->json([
    //             'status' => true,
    //             'message' => 'Filtered products retrieved successfully',
    //             'data' =>PopularProductResource::collection($products),
    //             'pagination' => [
    //                 'total' => $products->total(),
    //                 'page' => $products->currentPage(),
    //                 'pageSize' => $products->perPage(),
    //                 'lastPage' => $products->lastPage()
    //             ]
    //         ]);

    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Something went wrong.',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    public function filter(Request $request)
    {
        try {
            $storeId = current_store_id();

            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Missing store-id in headers.',
                ], 400);
            }

            $query = Product::query()
                ->where('store_id', $storeId)
                ->where('visible_on_storefront', 1)
                ->with([
                    'brand:id,name',
                    'categories:id,name',
                    'images:id,product_id,path,is_primary',
                    'seo:product_id,product_url,page_title,meta_description'
                ]);

            // ✅ CATEGORY FILTER
            if ($request->filled('categoryIds')) {
                $categoryIds = explode(',', $request->categoryIds);
                $allCategoryIds = [];

                foreach ($categoryIds as $catId) {
                    $allCategoryIds[] = (int)$catId;

                    $category = Category::with('childrenRecursive')->find($catId);
                    if ($category) {
                        $allCategoryIds = array_merge($allCategoryIds, $this->getAllChildCategoryIds($category));
                    }
                }

                $query->whereHas('categories', function ($q) use ($allCategoryIds) {
                    $q->whereIn('categories.id', $allCategoryIds);
                });
            }

            // ✅ BRAND FILTER
            if ($request->filled('brandId')) {
                $query->where('brand_id', $request->brandId);
            }

            // ✅ PRICE FILTERS
            if ($request->filled('minPrice')) {
                $query->where('price', '>=', $request->minPrice);
            }
            if ($request->filled('maxPrice')) {
                $query->where('price', '<=', $request->maxPrice);
            }

            // ✅ SORTING
            if ($request->filled('sortBy')) {
                switch ($request->sortBy) {
                    case 'priceLowToHigh':
                        $query->orderBy('price', 'asc');
                        break;
                    case 'priceHighToLow':
                        $query->orderBy('price', 'desc');
                        break;
                    case 'latest':
                        $query->orderBy('created_at', 'desc');
                        break;
                    case 'bestSelling':
                        $query->where('sold_count', '>', 0)->orderBy('sold_count', 'desc');
                        break;
                    default:
                        $query->orderBy('id', 'desc');
                }
            } else {
                $query->orderBy('id', 'desc');
            }

            // ✅ LIMIT TOTAL PRODUCTS
            $maxProducts = 10000;
            $query->take($maxProducts); // ensures only first 10k products considered

            // ✅ PAGINATION
            $page = (int) $request->input('page', 1);
            $pageSize = (int) $request->input('pageSize', 12);

            $products = $query->paginate($pageSize, ['*'], 'page', $page);

            return response()->json([
                'status' => true,
                'message' => 'Filtered products retrieved successfully',
                'data' => PopularProductResource::collection($products),
                'pagination' => [
                    'total' => min($products->total(), $maxProducts),
                    'page' => $products->currentPage(),
                    'pageSize' => $products->perPage(),
                    'lastPage' => ceil(min($products->total(), $maxProducts) / $products->perPage())
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage()
            ], 500);
        }
    }



    // Recursive helper function
    private function getAllChildCategoryIds($category)
    {
        $ids = [];
        foreach ($category->childrenRecursive as $child) {
            $ids[] = $child->id;
            $ids = array_merge($ids, $this->getAllChildCategoryIds($child));
        }
        return $ids;
    }
}
